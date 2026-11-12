<?php
namespace WHMCS\Module\Registrar\gateway_srs;

require_once __DIR__ . '/bootstrap.php';

class DNSAPI
{
    protected string $username;
    protected string $password;
    protected string $epp_username = '';
    protected string $registry_api_url;
    protected string $portal_api_url;
    protected string $mode;
    protected string $authentication;
    public bool $debug = false;
    protected string $bearer = '';
    private string $cacheKey;
    private bool $authenticating = false;
    /** Optional background requests (installation report): no failure logging unless Debug is on, short timeouts. */
    private bool $quiet = false;
    private $transport;
    private $operationLock;
    private static array $tokens = [];
    protected $lock_statusses = ['clientTransferProhibited', 'clientUpdateProhibited'];
    protected const TOKEN_REFRESH_MARGIN_SECONDS = 120;

    // The optional transport is a dependency-injection seam for offline API contract tests.
    public function __construct(array $params, ?callable $transport = null)
    {
        $this->username = trim($params['Portal_Username'] ?? '');
        $this->password = $params['Portal_Password'] ?? '';
        $this->mode = $params['AccountMode'] ?? 'live';
        $this->authentication = $params['Authentication'] ?? 'password';
        if (!in_array($this->mode, ['live', 'ote', 'dev'], true)
            || !in_array($this->authentication, ['password', 'jwt'], true)) {
            throw new RestAPIException('Choose a valid account mode and authentication method.');
        }
        $host = ['live' => 'https://api.gatewaysrs.com', 'ote' => 'https://api-ote.gatewaysrs.com', 'dev' => 'https://api-dev.gatewaysrs.com'][$this->mode];
        $this->registry_api_url = $host . '/api/';
        $this->portal_api_url = $host . '/portal/';
        $this->transport = $transport;
        $this->debug = !empty($params['debug']);
        $state = new RuntimeState();
        $this->operationLock = $state->lock('operations', false);
        if (in_array($state->read('update')['phase'] ?? '', ['installing', 'recovering'], true)) {
            throw new RestAPIException('A module update was interrupted. Recover it through Gateway SRS Manager before running registrar operations.');
        }
        if ($this->authentication === 'jwt') {
            $this->bearer = trim($params['JWT_Token'] ?? '');
            $expiry = self::jwtExpiry($this->bearer);
            if ($expiry === null || $expiry <= time()) {
                throw new RestAPIException('The portal-issued JWT is missing, malformed or expired. Replace it in the registrar settings.');
            }
        } elseif ($this->username === '' || $this->password === '') {
            throw new RestAPIException('Enter the portal username and password, or select JWT authentication.');
        }
        $this->cacheKey = hash('sha256', json_encode([$host, $this->authentication, $this->username,
            $this->authentication === 'jwt' ? $this->bearer : $this->password]));
        $this->doLogin();
    }

    public function __destruct()
    {
        if (is_resource($this->operationLock)) {
            flock($this->operationLock, LOCK_UN);
            fclose($this->operationLock);
        }
    }

    /** Decode scheduling metadata only; the API authenticates the token and checks revocation. */
    public static function jwtExpiry(string $jwt): ?int
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || in_array('', $parts, true)
            || !preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1])) {
            return null;
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $claims = $payload === false ? null : json_decode($payload, true);
        $expiry = is_array($claims) ? ($claims['exp'] ?? null) : null;
        return is_int($expiry) && $expiry > 0 ? $expiry : null;
    }

    public function doLogin(): void
    {
        $cached = self::$tokens[$this->cacheKey] ?? null;
        if ($cached && $cached['expires'] - time() > self::TOKEN_REFRESH_MARGIN_SECONDS) {
            $this->bearer = $cached['bearer'];
            $this->epp_username = $cached['epp_username'];
            return;
        }
        $this->authenticating = true;
        try {
            if ($this->authentication === 'password') {
                $this->bearer = '';
                $login = $this->postCH($this->portal_api_url . 'auth-jwt/', [
                    'username' => $this->username, 'password' => $this->password,
                ]);
                $this->bearer = $login['token'] ?? '';
                if ($this->bearer === '') {
                    throw new RestAPIException('The portal did not return an access token.');
                }
            }
            $identity = $this->getCH($this->portal_api_url . 'auth-epp-username/');
            if (empty($identity['epp_username']) || !is_string($identity['epp_username'])) {
                throw new RestAPIException('These credentials are not linked to an active Gateway SRS registrar.');
            }
            $this->epp_username = $identity['epp_username'];
            self::$tokens[$this->cacheKey] = ['bearer' => $this->bearer, 'epp_username' => $this->epp_username,
                'expires' => self::jwtExpiry($this->bearer) ?? time() + 300];
        } finally {
            $this->authenticating = false;
        }
    }

    public function validateConnection(): void
    {
        // Check the capabilities used by this module without purchases or expensive full-list reads.
        $this->getCH($this->registry_api_url . 'registry/domains/?limit=1');
        $this->getCH($this->portal_api_url . 'billing/pricelist-current/?limit=1');
    }

    protected function getCH($url): array { return $this->request('GET', $url); }
    protected function postCH($url, $data): array { return $this->request('POST', $url, $data); }
    protected function putCH($url, $data): array { return $this->request('PUT', $url, $data); }
    protected function patchCH($url, $data): array { return $this->request('PATCH', $url, $data); }
    protected function delCH($url): array { return $this->request('DELETE', $url); }

    private function request(string $method, string $url, ?array $data = null, bool $retried = false): array
    {
        if (strpos($url, $this->registry_api_url) !== 0 && strpos($url, $this->portal_api_url) !== 0) {
            throw new RestAPIException('Refusing an API request outside the selected Gateway SRS environment.');
        }
        $response = $this->send($method, $url, $data);
        $status = (int) $response['status'];
        $body = $response['body'];
        $output = null;
        if ($status < 200 || $status >= 300) {
            $output = is_array($body) ? $body : json_decode((string) $body, true);
            $output = is_array($output) ? $output : [];
        }
        if ($this->debug || ($status >= 400 && !$this->quiet)) {
            // The svtrid lets support find the transaction in the registry logs; it carries no request data.
            SafeLog::write('API request', array_filter(['method' => $method, 'status' => $status, 'retry' => $retried,
                'svtrid' => $output !== null ? RestAPIException::transactionId($output) : null], static fn ($v) => $v !== null));
        }
        if ($status === 401 && !$this->authenticating && !$retried && $this->authentication === 'password') {
            unset(self::$tokens[$this->cacheKey]);
            $this->doLogin();
            return $this->request($method, $url, $data, true);
        }
        if ($output !== null) {
            if ($status === 401 && $this->authentication === 'jwt') {
                throw new RestAPIException('Gateway SRS rejected the JWT. It may be expired or revoked; replace it in the registrar settings.', 401);
            }
            if (($output['error'] ?? '') === '2FA_REQ') {
                throw new RestAPIException('This account requires two-factor authentication. Use a portal-issued API-user JWT for unattended registrar operations.', 401);
            }
            throw new RestAPIException(self::errorMessage($status, $output), $status, $output);
        }
        if ($status === 204 || $body === '') {
            return ['http_code' => $status];
        }
        $decoded = is_array($body) ? $body : json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RestAPIException('Gateway SRS returned an invalid JSON response.', $status);
        }
        $decoded['http_code'] = $status;
        return $decoded;
    }

    /**
     * registry-api returns EPP failures as {"detail": <EPP result message>, "cltrid", "svtrid"}, with the HTTP status
     * mapped from the EPP result code (register/epp/response/__init__.py), and serializer failures as {"<field>": [...]}.
     * Do not return arbitrary bodies, field values, credentials or upstream HTML to clients: only fixed wording keyed
     * on the known messages and statuses, the names of rejected fields, and the transaction reference.
     */
    private const KNOWN_DETAILS = [
        'Object exists' => 'Domain already exists.',
        'Not found.' => 'Not found.',
        'Signature has expired.' => 'The API access token has expired.',
        'No active account found with the given credentials' => 'Unable to log in with provided credentials.',
        'Billing failure' => 'Gateway SRS could not charge the account for this operation (billing failure). Check the available credit in Gateway SRS Manager.',
        'Invalid authorization information' => 'The registry rejected the EPP (auth) code.',
        'Object does not exist' => 'The registry has no such domain or object.',
        'Object pending transfer' => 'The domain has a pending transfer, so the registry refused this operation.',
        'Object not pending transfer' => 'The domain has no pending transfer.',
        'Domain not pending transfer' => 'The domain has no pending transfer.', // rEngin's wording for EPP 2301
        'Object status prohibits operation' => 'The domain\'s current status (for example a lock, hold or pending action) does not allow this operation.',
        'Object association prohibits operation' => 'Another registry object depends on this one, so the operation was refused.',
        'Object is not eligible for renewal' => 'The registry says this domain is not eligible for renewal.',
        'Object is not eligible for transfer' => 'The registry says this domain is not eligible for transfer.',
        'Parameter value policy error' => 'The registry policy rejected a value in this request.',
        'Required parameter missing' => 'The registry rejected the request because a required value was missing.',
        'Parameter value range error' => 'The registry rejected a value in this request as out of range.',
        'Parameter value syntax error' => 'The registry rejected a value in this request as badly formatted.',
        'Command syntax error' => 'The registry could not read the request.',
        'Command use error' => 'The registry does not allow this operation in the domain\'s current state.',
        'Unimplemented option' => 'The registry does not support this option for this domain.',
        'Authorization error' => 'This Gateway SRS account is not authorised for this operation.',
    ];

    private const STATUS_MESSAGES = [
        403 => 'This Gateway SRS account is not authorised for this operation.',
        // DRF's default detail, e.g. "No Domain matches the given query." for an update to a domain not on the account.
        404 => 'Gateway SRS has no such record for this account in the selected environment. Check Account Mode and the registrar assignment in the portal.',
        409 => 'The registry refused the request because of the domain\'s current state (for example a lock, a pending transfer or another registrar).',
        424 => 'Gateway SRS could not charge the account for this operation (billing failure). Check the available credit in Gateway SRS Manager.',
        429 => 'The registry is busy (session limit reached). Retry in a few minutes.',
    ];

    public static function errorMessage(int $status, array $output): string
    {
        $detail = $output['detail'] ?? null;
        $message = is_string($detail) ? (self::KNOWN_DETAILS[$detail] ?? '') : '';
        if ($message === '' && $status === 400 && !isset($output['detail'])) {
            // Serializer errors are keyed by field name; the names are the module's own request keys.
            $fields = array_slice(array_filter(array_keys($output), static fn ($k) => is_string($k)
                && preg_match('/^[a-z_]{1,40}$/D', $k) && $k !== 'non_field_errors'), 0, 5);
            if ($fields) {
                $message = 'Gateway SRS rejected the request. Missing or invalid field(s): ' . implode(', ', $fields) . '.';
            }
        }
        if ($message === '') {
            $message = self::STATUS_MESSAGES[$status] ?? '';
        }
        if ($message === '' && $status >= 500) {
            $message = 'Gateway SRS could not complete the request (HTTP ' . $status . '). The registry may still have processed it:'
                . ' check the domain before retrying.';
        }
        $reasons = self::registryReasons($output);
        if ($message === '') {
            $message = $reasons !== [] ? 'The registry rejected the request (HTTP ' . $status . ').'
                : 'Gateway SRS API request failed (HTTP ' . $status . '). Check the account permissions and request settings.';
        }
        if ($reasons !== []) {
            $message .= (count($reasons) > 1 ? ' Registry reasons: ' : ' Registry reason: ') . implode('; ', $reasons)
                . (preg_match('/[.!?]$/', end($reasons)) ? '' : '.');
        }
        $reference = RestAPIException::transactionId($output);
        return $reference !== null ? $message . ' (Reference: ' . $reference . ')' : $message;
    }

    /**
     * The registry's own explanation of an EPP error, written for registrars: registry-api (from DNS-Africa/registry-api
     * PR 118) returns rEngin's <extValue> entries as "reasons": [{"reason": "...", "value": "..."}] beside "detail".
     * Older registry-api releases send no "reasons", and anything malformed is ignored, so the fixed wording stands
     * alone. A value is kept only when it looks like a domain, host, IP address or handle, so personal data a client
     * submitted (addresses, e-mail addresses, phone numbers) is not repeated.
     *
     * @return string[]
     */
    public static function registryReasons(array $output): array
    {
        $entries = $output['reasons'] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            return [];
        }
        $clean = static function ($text): string {
            if (!is_string($text)) { return ''; }
            $text = str_replace(['<', '>'], '', strip_tags($text));
            $text = trim(preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text) ?? '');
            return preg_match('/^.{301}/us', $text) ? rtrim(preg_replace('/^(.{299}).*$/us', '$1', $text)) . '…' : $text;
        };
        $reasons = [];
        foreach ($entries as $entry) {
            $reason = $clean(is_array($entry) ? ($entry['reason'] ?? '') : $entry);
            if ($reason === '') { continue; }
            $value = is_array($entry) ? $clean($entry['value'] ?? '') : '';
            if ($value !== '' && preg_match('/^[A-Za-z0-9._:-]{1,253}$/D', $value) && stripos($reason, $value) === false) {
                $reason .= ' (' . $value . ')';
            }
            $reasons[$reason] = $reason;
            if (count($reasons) === 5) { break; }
        }
        return array_values($reasons);
    }

    /**
     * JSON request body. An empty PHP array must go out as {} — json_encode([]) is "[]", a JSON list, and the
     * registry API reads request.data as an object (lock/unlock/block/unblock returned HTTP 500 for "[]").
     */
    public static function encodeBody(array $data): string
    {
        return json_encode($data === [] ? new \stdClass() : $data, JSON_THROW_ON_ERROR);
    }

    protected function send(string $method, string $url, ?array $data): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $data, $this->bearer);
        }
        $ch = curl_init($url);
        try {
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->quiet ? 5 : 20, CURLOPT_TIMEOUT => $this->quiet ? 10 : 60,
                CURLOPT_USERAGENT => self::userAgent(),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $this->bearer]]);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, self::encodeBody($data));
            }
            $body = curl_exec($ch);
            if ($body === false) {
                if ($this->debug || !$this->quiet) {
                    SafeLog::write('API transport failure', ['event' => 'transport_error']);
                }
                throw RestAPIException::noResponse('Cannot connect to Gateway SRS. Check connectivity and retry; verify the domain state before repeating a purchase.');
            }
            return ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body];
        } finally {
            unset($ch);
        }
    }

    /** Product tokens for Gateway SRS access logs, e.g. "GatewaySRS-WHMCS/5.1.0 WHMCS/8.13.1 PHP/8.3.4". */
    public static function userAgent(): string
    {
        static $agent = null;
        if ($agent === null) {
            $whmcs = Module::whmcsVersion();
            $agent = 'GatewaySRS-WHMCS/' . InstallationReport::cleanVersion(Module::version()['version'])
                . ($whmcs !== '' ? ' WHMCS/' . $whmcs : '') . ' PHP/' . InstallationReport::cleanVersion(PHP_VERSION);
        }
        return $agent;
    }

    /**
     * Daily installation report (see InstallationReport); any 2xx is accepted. Quiet: the endpoint may not be
     * deployed yet, so a 404 or an unreachable portal is not logged unless Debug is on, and it gives up fast.
     */
    public function reportInstallation(array $payload): void
    {
        $this->quiet = true;
        try {
            $this->postCH($this->portal_api_url . 'integrations/installations/', $payload);
        } finally {
            $this->quiet = false;
        }
    }

    protected function _fetch_all(string $url, int $limit = 100): array
    {
        $items = [];
        $offset = 0;
        do {
            $page = $this->getCH($url . (strpos($url, '?') === false ? '?' : '&') . http_build_query(['limit' => $limit, 'offset' => $offset]));
            if (!isset($page['results']) || !is_array($page['results'])) {
                throw new RestAPIException('Gateway SRS returned an invalid list response.');
            }
            $batch = $page['results'];
            $items = array_merge($items, $batch);
            $offset += count($batch);
            $count = (int) ($page['count'] ?? $offset);
            if (!$batch && $offset < $count) {
                throw new RestAPIException('Gateway SRS returned an incomplete paginated response.');
            }
        } while ($offset < $count);
        return $items;
    }

        static private function endsWith(string $needle, string $haystack): bool
        {
            $length = strlen( $needle );
            if( !$length ) {
                return true;
            }
            return substr( $haystack, -$length ) === $needle;
        }

        /*
         * Function generates a random password using the domain,
         * username, and md5 encryption.
         * We're also adding a # at the end,
         * to ensure that all domain standards are met.
         */
        static public function generate_password($domain): string
        {
            // Generate
            if (DNSAPI::endsWith('.na',$domain)) {
                $epp_key = DNSAPI::RandomString(16);
            } elseif (DNSAPI::endsWith('co.za',$domain))
            {
                $epp_key = 'coza';
            } else {
                $epp_key = DNSAPI::RandomString(16, true);
            }
            return $epp_key;
        }

        /*
         * Function generates a random ID using
         * the domain, username, and md5 encryption.
         */
        /**
         * @param bool $use_special_chars
         */


        static public function RandomString(int $length, bool $use_special_chars = false): string
        {
            $alpha = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $numeric = '0123456789';
            $special = '?!@#%^*()';

            $password = '';

            // Ensure at least one alpha character
            $password .= $alpha[random_int(0, strlen($alpha) - 1)];

            // Ensure at least one numeric character
            $password .= $numeric[random_int(0, strlen($numeric) - 1)];

            if ($use_special_chars) {
                // Ensure at least one special character
                $password .= $special[random_int(0, strlen($special) - 1)];
            }

            $remainingLength = $length - strlen($password);

            $allChars = $alpha . $numeric;
            if ($use_special_chars) {
                $allChars .= $special;
            }

            for ($i = 0; $i < $remainingLength; $i++) {
                $password .= $allChars[random_int(0, strlen($allChars) - 1)];
            }

            $password = str_shuffle($password);

            return $password;
        }

        public function generate_id($domain): string
        {
            return "DNS_GW_" . substr($domain, 0, 3) . DNSAPI::RandomString(6);
        }

        #Contact Operation

        /*
         * Create Contact
         */

        public function setcontact($contact_info)
        {
            $url = $this->registry_api_url . 'registry/contacts/';
            $result = $this->postCH($url, $contact_info);
            return $result['id'];
        }

        /*
         * Update Existing Contact
         */

        public function update_contact($contact_info, $existing_id)
        {
            // Check For Existing Contact
            $url = $this->registry_api_url . 'registry/contacts/' . rawurlencode($existing_id) . '/';
            $contact_info['id'] = $existing_id;
            $result = $this->patchCH($url, $contact_info);
            return true;
        }

        /*
         * Pulls all Existing Contacts
         */
        public function contact_listing()
        {
            $url = $this->registry_api_url . 'registry/contacts/';
            return $this->_fetch_all($url);
        }

        /*
         * Get the conatcs wid id
         */
        public function get_contact_wid($contact_id)
        {
            $url = $this->registry_api_url . 'registry/contacts/?id=' . $contact_id;
            $result = $this->getCH($url);
            return $result['results'][0]['wid'];
        }

        /*
         * Pulls a called contacts info
         */
        public function contact_info($contact_id): array
        {
            $url = $this->registry_api_url . 'registry/contacts/' . rawurlencode($contact_id) . '/';
            return $this->getCH($url);
        }

        /*
         * Checks if a contact exists
         */

        public function contact_check($check, $contact_name = '')
        {
            $url = $this->registry_api_url . 'registry/contacts/check/';
            $result = $this->postCH($url, $check);
            return $result['results'];
        }

        #Domain Operations

        /*
         * Registers a new domain
         */

        public function register_domain($domain_info)
        {
            $url = $this->registry_api_url . 'registry/domains/';
            $result = $this->postCH($url, $domain_info);
            return $result['wid'];
        }

        /*
         * Pulls all Existing Domains
         *  - Can Be Filtered By Domain Name
         */

        public function list_domains($domain_name = '')
        {
            $url = $this->registry_api_url.
                    'registry/domains/'.
                    ($domain_name!='' ? '?name=' . rawurlencode($domain_name) : '');

            $result = $this->getCH($url);
            return $result['results'];
        }

        /*
         * Pulls Domains Transferring In
         *  - Can Be Filtered By Domain Name
         */

        public function list_domain_transfer_in($domain_name = '')
        {
            $url = $this->registry_api_url.
                'registry/transfersin/'.
                ($domain_name!='' ? '?name=' . rawurlencode($domain_name) : '');

            $result = $this->getCH($url);
            return $result['results'];
        }

        /*
         * Pulls Domains Transferring Out
         *  - Can Be Filtered By Domain Name
         */

        public function list_domain_transfer_out($domain_name = '')
        {
            $url = $this->registry_api_url.
                'registry/transfersout/'.
                ($domain_name!='' ? '?name=' . rawurlencode($domain_name) : '');

            $result = $this->getCH($url);
            return $result['results'];
        }

        /*
         * Get's Domain's info
         */

        public function view_domain($domain_name)
        {
            $url = $this->registry_api_url . 'registry/domains/' . rawurlencode($domain_name) . '/';
            try {
                return $this->getCH($url);
            } catch (RestAPIException $e) {
                if ($e->getCode() !== 404) { throw $e; }
                $environment = ['live' => 'production', 'ote' => 'OTE', 'dev' => 'development'][$this->mode];
                throw new RestAPIException('No domain record is available to this Gateway SRS account in the '
                    . $environment . ' environment (HTTP 404). Check Account Mode and the domain\'s registrar assignment in the portal.',
                    404, null, $e);
            }
        }

        /*
         * Updates Existing Domain
         */

        public function update_domain($domain_info, $domain_id = '')
        {
            $name = $domain_info['name'] ?? $domain_id;
            if (!is_string($name) || strpos($name, '.') === false) {
                throw new RestAPIException('A domain name is required for this operation.');
            }
            $url = $this->registry_api_url . 'registry/domains/' . rawurlencode($name) . '/';
            $result = $this->patchCH($url, $domain_info);
            return $result['wid'];
        }

        /*
         * Delete Existing domain
         */

        /**
         * @throws RestAPIException
         */
        public function delete_domain($domain_id)
        {
            $url = $this->registry_api_url . 'registry/domains/' . rawurlencode($domain_id) . '/';
            $result = $this->delCH($url);
            if ($result["http_code"] >= 200 and $result["http_code"]  < 300)
                return true;
            else
                if (key_exists("details", $result))
                    throw new RestAPIException("Failed to delete domain: ". $result["details"], $result["http_code"], $result);
                else
                    throw new RestAPIException("Failed to delete domain: ", $result["http_code"], $result);
        }

        /*
         */

        /**
         * Renew Existing Domain
         *
         * @param array $domain
         * Required values
         *                  'name' => Domain Name
         *                  'curExpDate' => The current expiry date [yyyy-mm-dd] in UTC
         * Optional values
         *                  'period' => number of years,
         *                  'period_unit' => 'y', for years
         *
         * @return array
         * @throws RestAPIException
         */
        public function renew_domain($domain): array
        {
            $url = $this->registry_api_url . 'registry/WHMCS/domains/' . rawurlencode($domain['name']) . '/renew/';
            $result = $this->postCH($url, $domain);
            return $result;
        }

        /*
         * Transfers Existing domain
         */

        public function transfer_domain($domain)
        {
            $url = $this->registry_api_url . 'registry/domains/transfer-request/';
            $result = $this->postCH($url, $domain);
            return $result;
        }

        /*
         * Cancels Domain Transfer Request
         */

        public function cancel_domain_transfer($domain)
        {
            $url = $this->registry_api_url . 'registry/domains/transfer-cancel/';
            $result = $this->postCH($url, $domain);
            return true;
        }

        /*
         * Reject Domain Transfer
         */

        public function reject_transfer_domain($domain, $unused = null)
        {
            $name = is_array($domain) ? $domain['name'] : $domain;
            return $this->postCH($this->registry_api_url . 'registry/domains/' . rawurlencode($name) . '/transfer-reject/', []);
        }

        public function approve_transfer_domain($domain, $unused = null)
        {
            $name = is_array($domain) ? $domain['name'] : $domain;
            return $this->postCH($this->registry_api_url . 'registry/domains/' . rawurlencode($name) . '/transfer-approve/', []);
        }

        /**
         * Pulls Information about Current Domain from the registry
         * @param $domain
         * @return mixed
         * @throws RestAPIException
         */
        public function registry_domain_info(string $domain): array
        {
            $url = $this->registry_api_url . 'registry/domains/info/';
            $result = $this->postCH($url, array("name" => $domain));

            $info = $result['results'] ?? [];
            if (isset($info[0])) {
                $info = $info[0];
            }
            if (!is_array($info) || !$info) {
                throw new RestAPIException('Gateway SRS returned no domain information.');
            }
            return $info;
        }

        public function registrarId(): string { return $this->epp_username; }

        public function domain_info($domain)
        {
            $info = $this->registry_domain_info($domain);
            if (isset($info['rar']) && $this->epp_username !== $info['rar']) {
                throw new RestAPIException('This domain is managed by another registrar.');
            }
            return $info;
        }

        public function transfer_query(string $name): array
        {
            // Collection action works before an incoming domain belongs to this registrar.
            return $this->postCH($this->registry_api_url . 'registry/domains/transfer-query/', ['name' => $name]);
        }

        public function balances(): array
        {
            return $this->getCH($this->portal_api_url . 'billing/balances/');
        }

        public function dnssec(string $name): array
        {
            $response = $this->getCH($this->registry_api_url . 'registry/WHMCS/domains/' . rawurlencode($name) . '/dnssec/');
            if (!isset($response['domainsec']) || !is_array($response['domainsec'])) {
                throw new RestAPIException('Gateway SRS returned no valid DNSSEC record list.');
            }
            return $response['domainsec'];
        }

        /*
         * Pulls Information about Current Domain
         */

        public function domain_sync($domain)
        {
            $url = $this->registry_api_url . "registry/WHMCS/domains/" . rawurlencode($domain) . "/";

            return $this->getCH($url);
        }

        /*
         * Locks selected domain
         *
         * will be unable to -
         * Update
         * Delete
         * or Transfer
         */
        /**
         * @param string $domain_name
         */
        public function get_domain_wid($domain_name): int
        {
            $url = $this->registry_api_url . "registry/WHMCS/domains/" . rawurlencode($domain_name) . "/wid/";
            $result = $this->getCH($url);

            return $result["wid"];
        }

        /**
         * @param string $domain_name
         */
        public function get_domain_nameservers($domain_name): array
        {
            $url = $this->registry_api_url . "registry/WHMCS/domains/" . rawurlencode($domain_name) . "/nameservers/";

            return $this->getCH($url);
        }

        public function get_domain_contacts($domain_name): array
        {
            $url = $this->registry_api_url . "registry/WHMCS/domains/" . rawurlencode($domain_name) . "/contacts/";

            return $this->getCH($url);
        }
        /*
         * Check if the Domain exists
         */

        public function domain_check($domain)
        {
            $url = $this->registry_api_url . 'registry/domains/check/';
            $result = $this->postCH($url, array("name" => $domain));
            return $result;
        }

        /*
         * Function checks if a Domain is locked or unlocked
         */

        public function check_domain_lock($domain)
        {
            // Grab Domain Info
            $domain_arr = [
                'name'       => $domain,
                'authinfo'   => $this->generate_password($domain)
            ];

            $domain_info = $this->domain_info($domain);
            $locked = false;
            foreach ($domain_info['statuses'] as $status) {
                if (in_array($status['code'], $this->lock_statusses)) {
                    $locked = true;
                }
            }

            return $locked;
        }


        /*
         * Manage Hosts
         *
         * Create, Update & Delete hosts
         *
         */


        /*
         * List Hosts
         */

        // Current APIs manage glue within a domain's hosts array, not standalone host resources.
        public function list_hosts($hostname = '') { throw new RestAPIException('Manage nameserver glue through its domain.'); }
        public function create_host($hostname, $ip, $ip_class = 'v4') { throw new RestAPIException('Manage nameserver glue through its domain.'); }
        public function update_host($wid, $hostname, $ip, $ip_class = 'v4') { throw new RestAPIException('Manage nameserver glue through its domain.'); }
        public function delete_host($wid) { throw new RestAPIException('Manage nameserver glue through its domain.'); }

        public function tld_pricing(): array
        {
            $result = $this->getCH($this->portal_api_url . 'billing/pricelist-current/?fetch_all=true');
            if (!isset($result['results']) || !is_array($result['results'])
                || (isset($result['count']) && (int) $result['count'] !== count($result['results']))) {
                throw new RestAPIException('Gateway SRS returned an incomplete price list. No prices were imported.');
            }
            return $result['results'];
        }

        public function suspend($domain)
        {
            $url = $this->registry_api_url . 'registry/WHMCS/domains/' . rawurlencode($domain) . '/suspend/';
            $result = $this->postCH($url,array());

            if($result['http_code']==200)
                return true;

            return false;
        }

        public function unsuspend($domain)
        {
            $url = $this->registry_api_url . 'registry/WHMCS/domains/' . rawurlencode($domain) . '/unsuspend/';
            $result = $this->postCH($url,array());
            
            if($result['http_code']==200)
                return true;

            return false;
        }

        public function lock($domain)
        {
            $url = $this->registry_api_url . 'registry/WHMCS/domains/' . rawurlencode($domain) . '/lock/';
            $result = $this->postCH($url,array());

            if($result['http_code']==200)
                return true;

            return false;
        }

        public function unlock($domain)
        {
            $url = $this->registry_api_url . 'registry/WHMCS/domains/' . rawurlencode($domain) . '/unlock/';
            $result = $this->postCH($url,array());

            if($result['http_code']==200)
                return true;

            return false;
        }

        public function block($domain)
        {
            $url = $this->registry_api_url . 'registry/WHMCS/domains/' . rawurlencode($domain) . '/block/';
            $result = $this->postCH($url,array());

            if($result['http_code']==200)
                return true;

            return false;
        }

        public function unblock($domain)
        {
            $url = $this->registry_api_url . 'registry/WHMCS/domains/' . rawurlencode($domain) . '/unblock/';
            $result = $this->postCH($url,array());

            if($result['http_code']==200)
                return true;

            return false;
        }
    }
