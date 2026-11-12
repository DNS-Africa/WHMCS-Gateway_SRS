<?php
    /*
     * Approved by ModulesGarden
     *
     * This modules allows you to transfer, register, check availability and
     * use other fucntions for domains within whmcs.
     *
     * The modules files must be stored in: modules/registrars/
     * The modules name must always be lowercase and start with a letter
     * and only use letter and numbers for the modules name.
     *
     * The modules functions all must start with
     * the modules name followed by an understcore
     * for example ModuleName_FunctionName(){
     *}
     * @copyright Copyright (c) DNS Africa 2023
     * @author  David Peall <david@dns.business>
     */

    if (!defined('WHMCS')) {
        die('This file cannot be accessed directly');
    }

    require_once __DIR__ . '/lib/bootstrap.php';

    use \WHMCS\Module\Registrar\gateway_srs\{Module, PremiumPricing, Lifecycle, DnssecPage, RegistryWatch};
    use \WHMCS\Module\Registrar\gateway_srs\RuntimeState;
    use \WHMCS\Module\Registrar\gateway_srs\RestAPIException;

    use \WHMCS\Domains\DomainLookup\ResultsList;
    use \WHMCS\Domains\DomainLookup\SearchResult;
    use \WHMCS\Exception\Module\InvalidConfiguration;
    use \WHMCS\Module\Registrar\gateway_srs\DNSAPI;
    use \WHMCS\Database\Capsule;
    use \WHMCS\Domain\TopLevel\ImportItem;


    // Require any libraries needed for the modules to function.

    /**
     * Define modules related metadata
     *
     * Provide some modules information including
     * the display name and API Version to
     * determine the method of decoding the input values.
     *
     * @return array
     */

    function gateway_srs_MetaData()
    {
        $meta = json_decode(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . "whmcs.json"), true, 512, JSON_THROW_ON_ERROR);
        return [
            'DisplayName' => $meta['description']['name'],
            'APIVersion' => '1.1',
        ];
    }

    /**
     * Define registrar configuration options.
     *
     * configuration file can be found in whmcs->
     * Setup->Products/Services->Domain Registrars->Your ModuleName -> Configure
     *
     * The following field types are supported:
     *  * Text
     *  * Password
     *  * Yes/No Checkboxes
     *  * Dropdown Menus
     *  * Radio Buttons
     *  * Text Areas
     */

    function gateway_srs_getConfigArray()
    {
        $meta = json_decode(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . "whmcs.json"), true, 512, JSON_THROW_ON_ERROR);

        return [
            'FriendlyName' => [
                'Type' => 'System',
                'Value' => $meta['description']['name']
            ],
            'Description' => [
                'Type' => 'System',
                'Value' => $meta['description']['tagline']
            ],
            'Module_Version' => [
                'Type' => 'System',
                'Value' => 'Installed module version: ' . Module::escape(Module::version()['version']),
            ],
            'Authentication' => [
                'FriendlyName' => 'Authentication', 'Type' => 'dropdown',
                'Options' => ['password' => 'Portal username and password', 'jwt' => 'Portal-issued JWT'],
                'Default' => 'password', 'Description' => 'Existing installations continue to use portal credentials.'
            ],
            'JWT_Token' => [
                'FriendlyName' => 'Portal-issued JWT', 'Type' => 'password', 'Size' => '60', 'Default' => '',
                'Description' => 'Paste the API-user access token from the portal. Expiry warnings start at 30 days and become urgent at 7 days.'
            ],
            'Module_Status' => [
                'Type' => 'System', 'Value' => '<a href="addonmodules.php?module=gateway_srs_manager">Module status and updates</a> (activate Gateway SRS Manager under Addon Modules).'
            ],
            'Portal_Username' => [
                'FriendlyName' => 'Portal Username',
                'Type' => 'text',
                'Size' => '25',
                'Default' => '',
                'Description' => 'Username for https://portal.gatewaysrs.com/'
            ],
            'Portal_Password' => [
                'FriendlyName' => 'Password',
                'Type' => 'password',
                'Size' => '25',
                'Default' => '',
                'Description' => ''
            ],
            // the dropdown field type renders a select menu of options
            'AccountMode' => [
                'FriendlyName' => 'Account Mode',
                'Type' => 'dropdown',
                'Size' => '25',
                'Options' => [
                    'live' => 'Live',
                    'ote' => 'Testing (OTE Account)',
                    'dev' => 'Development (DEV Account)'
                ],
                'Description' => 'Choose one',
            ],
            'debug' => [
                'FriendlyName' => 'Debug',
                'Type' => 'yesno',
                'Description' => 'Log HTTP metadata only. Credentials and API bodies are never logged.',
            ],
            'Balance_Alerts' => [
                'FriendlyName' => 'Low-funds alerts', 'Type' => 'yesno',
                'Description' => 'Email system administrators when available credit reaches the thresholds below. Balance remains visible with alerts disabled.'
            ],
            'Balance_Low' => [
                'FriendlyName' => 'Low available credit', 'Type' => 'text', 'Size' => '15', 'Default' => '1000',
                'Description' => 'USD. Warn at or below this amount of available credit.'
            ],
            'Balance_Critical' => [
                'FriendlyName' => 'Critical available credit', 'Type' => 'text', 'Size' => '15', 'Default' => '0',
                'Description' => 'USD. Send an urgent alert at or below this amount. Must be lower than the low threshold.'
            ],
        ];
    }

    /**
     * @throws InvalidConfiguration
     */
    function gateway_srs_config_validate($params)
    {
        try {
            \WHMCS\Module\Registrar\gateway_srs\BalanceMonitor::thresholds($params);
            Module::api($params)->validateConnection();
        } catch (\Throwable $e) {
            throw new InvalidConfiguration($e->getMessage());
        }
        // Keep the old name migration idempotent and restricted to known legacy names.
        $state = new RuntimeState();
        $lock = $state->lock('legacy-migration');
        try {
            if (empty($state->read('legacy-migration')['complete'])) {
                Capsule::connection()->transaction(static function () {
                    Capsule::table('tbldomains')->whereIn('registrar', ['dns_gateway', 'ns_gateway'])->update(['registrar' => 'gateway_srs']);
                    Capsule::table('tbldomainpricing')->whereIn('autoreg', ['dns_gateway', 'ns_gateway'])->update(['autoreg' => 'gateway_srs']);
                });
                $state->write('legacy-migration', ['complete' => true]);
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        // Report straight away so a new or upgraded installation appears without waiting for the daily cron.
        Module::reportInstallation($params);
        return null;
    }


    # Function to register domain
    /*
     * Called when the registration of a new domain is initiated within WHMCS.
     *
     * Function will trigger a payment is made or a new domain is registered
     */

    function gateway_srs_RegisterDomain($params)
    {
        try {
            // Registration parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = $params['regperiod'];

            //Nameservers
            $nameservers = [];
            for ($n = 1; $n <= 5; $n++) {
                if (isset($params['ns' . $n]) && $params['ns' . $n] != '') {
                    $nameservers[] = [
                        'hostname' => $params['ns' . $n],
                    ];
                }
            }

            // Connect To API
            $api = Module::api($params);

            $agreement = PremiumPricing::agreement($api, $params, $domain, 'create');

            // Registrant information
            $registrant_info = [
                'id' => $api->generate_id($domain),
                'phone' => $params['fullphonenumber'],
                'fax' => NULL,
                'email' => $params['email'],
                'contact_address' => [
                    [
                        'real_name' => $params['fullname'],
                        'org' => $params['companyname'],
                        'street' => $params['address1'],
                        'street2' => $params['address2'],
                        'street3' => NULL,
                        'city' => $params['city'],
                        'province' => $params['state'],
                        'code' => $params['postcode'],
                        'country' => $params['countrycode'],
                        'type' => 'loc'
                    ],
                    [
                        'real_name' => iconv("UTF-8", "ASCII//TRANSLIT", $params['fullname']),
                        'org' => iconv("UTF-8", "ASCII//TRANSLIT", $params['companyname']),
                        'street' => iconv("UTF-8", "ASCII//TRANSLIT", $params['address1']),
                        'street2' => iconv("UTF-8", "ASCII//TRANSLIT", $params['address2']),
                        'street3' => NULL,
                        'city' => iconv("UTF-8", "ASCII//TRANSLIT", $params['city']),
                        'province' => iconv("UTF-8", "ASCII//TRANSLIT", $params['state']),
                        'code' => $params['postcode'],
                        'country' => $params['countrycode'],
                        'type' => 'int'
                    ]
                ]
            ];

            // Admin contact information.
            $admin_info = [
                'id' => $api->generate_id($domain),
                'phone' => $params['adminfullphonenumber'],
                'fax' => NULL,
                'email' => $params['adminemail'],
                'contact_address' => [
                    [
                        'real_name' => $params['adminfirstname'] .
                            ' ' . $params['adminlastname'],
                        'org' => $params['admincompanyname'],
                        'street' => $params['adminaddress1'],
                        'street2' => $params['adminaddress2'],
                        'street3' => NULL,
                        'city' => $params['admincity'],
                        'province' => $params['adminstate'],
                        'code' => $params['adminpostcode'],
                        'country' => $params['admincountry'],
                        'type' => 'loc'
                    ],
                    [
                        'real_name' => iconv("UTF-8", "ASCII//TRANSLIT", $params['adminfirstname']) .
                            ' ' . iconv("UTF-8", "ASCII//TRANSLIT", $params['adminlastname']),
                        'org' => iconv("UTF-8", "ASCII//TRANSLIT", $params['admincompanyname']),
                        'street' => iconv("UTF-8", "ASCII//TRANSLIT", $params['adminaddress1']),
                        'street2' => iconv("UTF-8", "ASCII//TRANSLIT", $params['adminaddress2']),
                        'street3' => NULL,
                        'city' => iconv("UTF-8", "ASCII//TRANSLIT", $params['admincity']),
                        'province' => iconv("UTF-8", "ASCII//TRANSLIT", $params['adminstate']),
                        'code' => $params['adminpostcode'],
                        'country' => $params['admincountry'],
                        'type' => 'int'
                    ]
                ]
            ];

            // Billing contact information.
            $billing_info = $admin_info;
            $billing_info["id"] = $api->generate_id($domain);

            // Tech contact information.
            $tech_info = $admin_info;
            $tech_info["id"] = $api->generate_id($domain);


            // Create Contacts
            $registrant_contact = $api->setcontact($registrant_info);
            $admin_contact = $api->setcontact($admin_info);
            $billing_contact = $api->setcontact($billing_info);
            $tech_contact = $api->setcontact($tech_info);

            // Register Domain
            $domain = [
                'name' => $domain,
                'period' => $registrationPeriod,
                'period_unit' => 'y',
                'autorenew' => false,
                'authinfo' => $api->generate_password($domain),
                'hosts' => $nameservers,
                'contacts' => [
                    [
                        'type' => 'registrant',
                        'contact' => [
                            'id' => $registrant_contact
                        ]
                    ],
                    [
                        'type' => 'admin',
                        'contact' => [
                            'id' => $admin_contact
                        ]
                    ],
                    [
                        'type' => 'billing',
                        'contact' => [
                            'id' => $billing_contact
                        ]
                    ],
                    [
                        'type' => 'tech',
                        'contact' => [
                            'id' => $tech_contact
                        ]
                    ]
                ]
            ];

            $domain = array_merge($domain, $agreement);

            //  Call The Register Domain Function
            try {
                $api->register_domain($domain);
            } catch (RestAPIException $e) {
                // After a timeout or a registry 5xx the domain may have been created. Report success only when the
                // registry shows it on this account; otherwise keep the original error.
                if (!$e->outcomeUnknown() || !Lifecycle::ownedByAccount($api, $domain['name'])) { throw $e; }
            }

            return [
                'success' => true
            ];

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to transfer a domain
    /*
     * Called when a domain transfer request is initiated within WHMCS.
     *
     * Function is triggered when a peding domain trasfer is accpeted,
     * when a payment is recieved for a domains transfer,
     * manual transfer by admin.
     */

    function gateway_srs_TransferDomain($params)
    {
        // Registration parameters
        $sld = $params['sld'];
        $tld = ltrim($params['tld'], '.');
        $domain = $sld . '.' . $tld;
        $registrationPeriod = $params['regperiod'];
        $eppCode = htmlspecialchars_decode($params['eppcode']);
        if ($tld == 'co.za') {
            $eppCode = 'coza';
        }

        // Connect To API
        try {
            $api = Module::api($params);
            // Check if domain is already pending transfer
            $domain_transfer_in = $api->list_domain_transfer_in($domain);

            if (isset($domain_transfer_in[0]['wid'])) {
                return ['success' => true];
            }


            // Transfer Domain
            $domain_array = [
                'name' => $domain,
                'authinfo' => $eppCode,
                'period' => $registrationPeriod
            ];

            $domain_array = array_merge($domain_array, PremiumPricing::agreement($api, $params, $domain, 'transfer'));

            //  Call The Register Domain Function
            $api->transfer_domain($domain_array);
            return ['success' => true];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    # Function to renew domain
    /*
     * Called when a request to renew a domain is initiated within WHMCS.
     *
     * Function is trigered when a payment is recvied for a domain renew order,
     * pending renew order is accepted,
     * manual renewal by admin.
     *
     * Attempt to renew/extend a domain for a given number of years.
     */

    function gateway_srs_RenewDomain($params)
    {
        try {
            // Registration parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = $params['regperiod'];


            $api = Module::api($params);


            // Get Domain info
            $row = $api->list_domains($domain)[0] ?? null;
            $current = is_array($row) ? ($row['curExpDate'] ?? null) : null;
            if (!is_string($current) || $current === '') {
                throw new \RuntimeException('Gateway SRS has no current expiry date for this domain on this account, so the renewal was stopped.'
                    . ' Check Account Mode and the domain\'s registrar assignment in the Gateway SRS portal.');
            }
            // EPP's curExpDate only prevents a repeated renewal if it is the date WHMCS expects, not one re-read live.
            Lifecycle::renewalGuard($current, Module::whmcsExpiry((int) ($params['domainid'] ?? 0)));

            // Renew Domain
            $renew_array = [
                'name' => $domain,
                'period' => $registrationPeriod,
                'period_unit' => 'y',
                'curExpDate' => $current
            ];

            $renew_array = array_merge($renew_array, PremiumPricing::agreement($api, $params, $domain, 'renew'));
            $api->renew_domain($renew_array);

            return [
                'success' => true
            ];

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch current nameservers.
     *
     * This function should return an array of nameservers for a given domain.
     *
     * Called when a domain is viewed within WHMCS. It can
     * return up to 5 nameservers that are set for the domain.
     *
     * $sld = eg. yourdomain
     * $tld = eg. .com
     */

    function gateway_srs_GetNameservers($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            $domain_info = $api->get_domain_nameservers($domain);

            $count = 0;
            $nameservers = [];
            foreach ($domain_info['nameservers'] ?? [] as $namserver) {
                $count++;
                $nameservers['ns' . $count] = $namserver['hostname'];
            }
            return $nameservers;

        } catch (\Throwable $e) {
            // registry-api answers a missing domain here with 200 and an empty list, but a 404 from any future version
            // is reported like every other lookup: as an error, and recorded for the registry-missing digest.
            if ($e instanceof RestAPIException && $e->getCode() === 404) {
                Module::registryMissing($params, $domain);
            }
            return [
                'error' => $e->getMessage()
            ];
        }
    }


    # Function to save set of nameservers
    /*
     * Called when a change is submitted for a domains nameservers.
     *
     * $sld = eg. yourdomain
     * $tld = eg. .com
     */

    function gateway_srs_SaveNameservers($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = (
            $params['regperiod'] > 0 ?
                $params['regperiod'] :
                1
            );

            // Connect To API
            $api = Module::api($params);


            // Grab Domain Info
            $domain_view = $api->get_domain_nameservers($domain);


            // Handle Nameservers
            $hosts = $domain_view['nameservers'];
            $new_hosts = [];

            for ($n = 1; $n <= 5; $n++) {
                if (isset($params['ns' . $n]) && $params['ns' . $n] != '') {
                    // Compare Nameservers
                    $host_record = [
                        'hostname' => $params['ns' . $n]
                    ];
                    foreach ($hosts as $host_key => $host) {
                        if ($host['hostname'] == $params['ns' . $n]) {
                            $host_record['glue'] = $host['glue'] ?? [];
                        }
                    }
                    // Add Nameserver
                    $new_hosts[] = $host_record;
                }
            }

            // Update Domain
            $update_array = [
                'name' => $domain,
                'period' => $registrationPeriod,
                'period_unit' => 'y',
                'hosts' => $new_hosts,
                'contacts' => $api->get_domain_contacts($domain)["contacts"],
            ];
            $api->update_domain($update_array, $domain);
            return ['success' => 'success'];

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }


    # Function to grab contact details
    /*
     * Called when the WHOIS information is displayed within WHMCS.
     *
     * Should return a multi-level array of the contacts and name/address
     * fields that be modified.
     */

    function gateway_srs_GetContactDetails($params)
    {
        try {
            $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');
            $api = Module::api($params);
            // One call: the domain view already nests each full contact (address, email, phone, fax), identical to
            // registry/contacts/<id>/. Previously this page made an unused list call plus one call per contact role.
            $domain_view = $api->view_domain($domain);
            $fetched = [];
            $contacts = [];
            foreach ($domain_view['contacts'] ?? [] as $contact) {
                $contact_info = $contact['contact'] ?? [];
                if (!isset($contact_info['contact_address'][0])) {
                    // Defensive fallback if a response ever omits the nested address; each contact is fetched once.
                    $id = (string) ($contact_info['id'] ?? '');
                    $contact_info = $fetched[$id] ??= $api->contact_info($id);
                }
                $address = $contact_info['contact_address'][0];
                $name_parts = explode(' ', (string) $address['real_name']);
                $last_name = end($name_parts);
                array_pop($name_parts);
                $contacts[ucfirst($contact['type'])] = [
                    'First Name' => implode(' ', $name_parts),
                    'Last Name' => $last_name,
                    'Company Name' => $address['org'],
                    'Email Address' => $contact_info['email'],
                    'Address 1' => $address['street'],
                    'Address 2' => $address['street2'],
                    'City' => $address['city'],
                    'State' => $address['province'],
                    'Postcode' => $address['code'],
                    'Country' => $address['country'],
                    'Phone Number' => $contact_info['phone'],
                    'Fax Number' => $contact_info['fax'],
                ];
            }
            return $contacts;
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    # Function to save contact details
    /*
     * Called when revised WHOIS information is submitted.
     * Update the WHOIS Contact Information for a given domain.
     */

    function gateway_srs_SaveContactDetails($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            // Grab Domain Info
            $domain_view = $api->get_domain_contacts($domain);

            // Loop Contacts
            foreach ($domain_view['contacts'] as $contact) {
                // Updated Contact information
                $new_details = $params['contactdetails'][ucfirst($contact['type'])];

                // Contact information.
                $contact_info = [
                    'phone' => $new_details['Phone Number'],
                    'email' => $new_details['Email Address'],
                    'contact_address' => [
                        [
                            'real_name' => $new_details['First Name'] .
                                ' ' . $new_details['Last Name'],
                            'org' => $new_details['Company Name'],
                            'street' => $new_details['Address 1'],
                            'street2' => $new_details['Address 2'],
                            'street3' => NULL,
                            'city' => $new_details['City'],
                            'province' => $new_details['State'],
                            'code' => $new_details['Postcode'],
                            'country' => $new_details['Country'],
                            'type' => 'loc'
                        ]
                    ]
                ];

/*                foreach ($contact_info['contact_address'][0] as $key => $contact_address) {
                    if (strlen($contact_address) < 1){
                        unset($contact_info['contact_address'][0][$key]);
                    }
                }*/

                if (isset($new_details['Fax Number']) && !empty($new_details['Fax Number'])) {
                    $contact_info['fax'] = $new_details['Fax Number'];
                }

                // Grab API Side Contact Info
                $api_contact = $api->contact_info($contact['contact']['id']);

                // Update Contact
                $api->update_contact($contact_info, $contact['contact']['id']);
            }

            return ['success' => 'success'];

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to check a domains availability and premium status
    /*
     * Check Domain Availability.
     * Determine if a domain is availability for registration or transfer.
     *
     * Check is the domain name is premium
     */
    function gateway_srs_CheckAvailability($params)
    {
        try {
            // Connect To API
            $api = Module::api($params);

            // Initiate Results List
            $results = new ResultsList();

            // Loop Through Domains To Check Availability
            foreach ($params['tldsToInclude'] as $tld) {
                $searchResult = new SearchResult($params['sld'], $tld);
                [$domain_check, $quotes] = PremiumPricing::fetch($api, $params['sld'] . '.' . ltrim($tld, '.'));

                $reason = strtolower($domain_check['results'][0]['reason'] ?? '');
                $available = (int)$domain_check['results'][0]['avail'];

                $status = SearchResult::STATUS_NOT_REGISTERED;
                if ($available == 0) {
                    if ($reason == "in use" or $reason == "domain exists") {
                        $status = SearchResult::STATUS_REGISTERED;

                    } elseif (strpos($reason, "domain reserved") !== false) {
                        $status = SearchResult::STATUS_RESERVED;
                    } else {
                        $status = SearchResult::STATUS_UNKNOWN;
                    }
                } elseif ($available > 1) {
                    $status = SearchResult::STATUS_UNKNOWN;
                }

                $searchResult->setStatus($status);

                if (array_filter($quotes, static fn ($quote) => $quote['premium'])) {
                    $searchResult->setPremiumDomain(true);
                    $currencies = array_unique(array_column($quotes, 'currency'));
                    if (count($currencies) !== 1) { throw new \RuntimeException('Gateway SRS returned mixed premium currencies.'); }
                    $searchResult->setPremiumCostPricing([
                        'register' => isset($quotes['create']) ? (float) $quotes['create']['price'] : null,
                        'renew' => isset($quotes['renew']) ? (float) $quotes['renew']['price'] : null,
                        'transfer' => isset($quotes['transfer']) ? (float) $quotes['transfer']['price'] : null,
                        'CurrencyCode' => reset($currencies)
                    ]);
                }
                $results->append($searchResult);
            }

            return $results;

        } catch (\Throwable $e) {
            return array(
                'error' => $e->getMessage()
            );
        }
    }

    # Function to Check if Registrar is locked
    /*
     * Called when a domains details are viewed within WHMCS.
     * It should return the current lock status of a domain.
     *
     * If a domain is locked it will be unable to-
     * Update
     * Transfer or
     * Delete
     */

    function gateway_srs_GetRegistrarLock($params)
    {

        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Call API
            $api = Module::api($params);

            $domain_lock = false;
            $domain_sync = $api->domain_sync($domain);
            if (isset($domain_sync['statuses'])) {
                foreach ($domain_sync['statuses'] as $val) {
                    if ($val['code'] == 'clientTransferProhibited') {
                        $domain_lock = true;
                    }
                }
            }


            if ($domain_lock == true) {
                return 'locked';
            } else {
                return 'unlocked';
            }

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to save Registrar Lock
    /*
     * Called when the lock status setting is toggled within WHMCS.
     */

    function gateway_srs_SaveRegistrarLock($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            $api = Module::api($params);

            if ($params['lockenabled'] == 'locked') {
                $api->lock($domain);
            } else {
                $api->unlock($domain);
            }
            return ['success' => 'success'];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Get epp code
    /*
     * Called when the EPP Code is requested for a transfer out.
     *
     * Can display EPP code directly to the user or
     * indicate that the code will be emailed to the registrant.
     */

    function gateway_srs_GetEPPCode($params)
    {
        try {
            $tld = ltrim($params['tld'], '.');
            $domain = $params['sld'] . '.' . $tld;
            if (in_array($tld, ['co.za', 'net.za', 'org.za', 'web.za'], true)) {
                return ['error' => 'This TLD Does Not Support EPP Codes'];
            }
            $api = Module::api($params);
            // The domain view returns the current auth code in ~0.2 s. The live registry info query returned the
            // same code but took ~5.3 s on OTE, so it is only a fallback when no stored code is available.
            $view = $api->view_domain($domain);
            if (isset($view['rar']) && $view['rar'] !== $api->registrarId()) {
                return ['error' => 'This domain is managed by another registrar.'];
            }
            $code = $view['authinfo'] ?? '';
            if (!is_string($code) || $code === '') {
                $code = $api->domain_info($domain)['authinfo'] ?? '';
            }
            if (!is_string($code) || $code === '') {
                return ['error' => 'The registry did not return an EPP code for this domain. Please contact support.'];
            }
            return ['eppcode' => $code];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Client-area actions that are not sidebar buttons. NewEPPCode is posted from the Auth Code page. */
    function gateway_srs_ClientAreaAllowedFunctions()
    {
        return ['New EPP Code' => 'NewEPPCode'];
    }

    /**
     * Generate and set a new auth (EPP) code, then return to the Auth Code page, which shows the stored code.
     * WHMCS has already checked the CSRF token, the client's ownership of the domain and managedomains permission.
     * registry-api validates a PATCH as a full domain (hosts, contacts, period and period_unit are required) but
     * sends the registry only the keys that differ from its stored copy. Resending the current values unchanged
     * therefore changes only the auth code; DNSSEC (not sent) is untouched.
     */
    function gateway_srs_NewEPPCode($params)
    {
        $back = 'clientarea.php?action=domaingetepp&domainid=' . (int) ($params['domainid'] ?? 0) . '&eppcode=';
        $result = 'failed';
        try {
            $tld = ltrim($params['tld'], '.');
            $domain = $params['sld'] . '.' . $tld;
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || in_array($tld, ['co.za', 'net.za', 'org.za', 'web.za'], true)) {
                throw new \RuntimeException('A new EPP code can only be requested from the Auth Code page.');
            }
            $api = Module::api($params);
            $view = $api->view_domain($domain);
            if (isset($view['rar']) && $view['rar'] !== $api->registrarId()) {
                throw new \RuntimeException('This domain is managed by another registrar.');
            }
            if (!isset($view['hosts'], $view['contacts']) || !is_array($view['hosts']) || !is_array($view['contacts'])) {
                throw new \RuntimeException('Gateway SRS returned incomplete domain settings.');
            }
            $api->update_domain(['name' => $domain,
                'authinfo' => \WHMCS\Module\Registrar\gateway_srs\DNSAPI::generate_password($domain),
                'hosts' => $view['hosts'], 'contacts' => $view['contacts'],
                'period' => $view['period'] ?? 1, 'period_unit' => $view['period_unit'] ?? 'y'], $domain);
            $result = 'new';
        } catch (\Throwable $e) {
            \WHMCS\Module\Registrar\gateway_srs\SafeLog::write('New EPP code failed', ['event' => 'eppcode_error']);
        }
        Module::redirect($back . $result);
        return $result;
    }

    # Request deletion of the domain name
    /*
     * Trigers when a domain deletion request comes from WHMCS or
     * manual deletion from admin. *
     */

    function gateway_srs_RequestDelete($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Call api
            $api = Module::api($params);

            $api->delete_domain($domain);

            return array(
                'success' => 'success',
            );

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to register nameserver
    /*
     * Called when a child nameserver registration request comes from WHMCS
     */

    function gateway_srs_RegisterNameserver($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = (
            $params['regperiod'] > 0 ?
                $params['regperiod'] :
                1
            );

            // Nameserver parameters
            $nameserver = $params['nameserver'];
            $ipAddress = $params['ipaddress'];

            // Connect To API
            $api = Module::api($params);


            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Prepare Hosts
            $hosts = $domain_view['hosts'];
            $hosts[] = [
                'hostname' => $params['nameserver'],
                'glue' => [
                    [
                        'ip' => $params['ipaddress'],
                        'class_field' => filter_var($params['ipaddress'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'v6' : 'v4'
                    ]
                ]
            ];

            // Update Domain & Hosts
            $update_array = [
                'name' => $domain,
                'period' => $registrationPeriod,
                'period_unit' => 'y',
                'hosts' => $hosts,
                'contacts' => $domain_view['contacts'],
            ];

            $api->update_domain($update_array, $domain);

            return true;

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Modify nameserver
    /*
     * Called when a child nameserver modification request comes from WHMCS
     *
     * Modifies the IP of a child nameserver.
     */

    function gateway_srs_ModifyNameserver($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = (
            $params['regperiod'] > 0 ?
                $params['regperiod'] :
                1
            );

            // Nameserver parameters
            $nameserver = $params['nameserver'];
            $CurrentIpAddress = $params['currentipaddress'];
            $NewIpAddress = $params['newipaddress'];

            // Connect To API
            $api = Module::api($params);

            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Prepare Hosts
            $hosts = $domain_view['hosts'];
            foreach ($hosts as $host_key => $host) {
                if ($host['hostname'] == $nameserver) {
                    // Go Through Glue Records
                    foreach ($host['glue'] as $glue_key => $glue) {
                        if ($glue['ip'] == $CurrentIpAddress) {
                            // Update Domain & Hosts
                            $hosts[$host_key]['glue'][$glue_key]['ip'] = $NewIpAddress;
                            $hosts[$host_key]['glue'][$glue_key]['class_field'] = filter_var($NewIpAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'v6' : 'v4';

                            $update_array = [
                                'name' => $domain,
                                'period' => $registrationPeriod,
                                'period_unit' => 'y',
                                'hosts' => $hosts,
                                'contacts' => $domain_view['contacts'],
                            ];

                            $api->update_domain($update_array, $domain);

                            return true;
                        }
                    }
                }
            }

            // Return Error If No Matching Hostname Was Found
            return [
                'error' => 'No Matching Hostname Was Found'
            ];

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Delete nameserver
    /*
     * Called when a child nameserver deletion request comes from WHMCS.
     */

    function gateway_srs_DeleteNameserver($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;


            // Nameserver parameters
            $nameserver = $params['nameserver'];

            // Connect To API
            $api = Module::api($params);

            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Prepare Hosts
            $hosts = $domain_view['hosts'];
            $new_hosts = [];
            foreach ($hosts as $host_key => $host) {
                if ($host['hostname'] != $nameserver) {
                    $new_hosts[] = $host;
                }
            }
            // Update Domain & Hosts. registry-api validates a PATCH as a full domain, so period is required.
            $update_array = [
                'name' => $domain,
                'period' => $domain_view['period'] ?? 1,
                'period_unit' => $domain_view['period_unit'] ?? 'y',
                'hosts' => $new_hosts,
                'contacts' => $domain_view['contacts'],
            ];
            $api->update_domain($update_array, $domain);

            return true;

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Sync Domain Status & Expiration Date.
     *
     * To ensure expiry date and status changes made
     * directly at the domain register are in sync with WHMCS.
     */

    function gateway_srs_Sync($params)
    {
        try {
            $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');
            $result = Lifecycle::sync(Module::api($params), $domain,
                static function () use ($params, $domain) { Module::registryMissing($params, $domain); });
            Module::registryFound($params, $domain);
            return $result;
        } catch (\Throwable $e) {
            // A 404 or an authentication failure is not evidence that the domain transferred away.
            return ['error' => $e->getMessage()];
        }
    }

    /*
     * Update Domain Auto-renew
     */

    function gateway_srs_update_autorenew($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Update Domain
            $update_array = [
                'name' => $domain,
                'period' => 1,
                'period_unit' => 'y',
                'hosts' => $domain_view['hosts'],
                'contacts' => $domain_view['contacts'],
                'autorenew' => false
            ];
            $api->update_domain($update_array, $domain);

            return true;

        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /*
     * Update EPP Key
     */

    function gateway_srs_Update_EPP_key($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;
            $registrationPeriod = (
            $params['regperiod'] > 0 ?
                $params['regperiod'] :
                1
            );

            // Connect To API
            $api = Module::api($params);

            // Grab Domain Info
            $domain_list = $api->list_domains($domain);
            $domain_view = $api->view_domain($domain);

            // Update Domain
            $update_array = [
                'name' => $domain,
                'period' => $registrationPeriod,
                'period_unit' => 'y',
                'authinfo' => $api->generate_password($domain),
                'hosts' => $domain_view['hosts'],
                'contacts' => $domain_view['contacts'],
            ];
            $api->update_domain($update_array, $domain);

            return true;

        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }


    /*
     * Incoming Domain Transfer Sync.
     *
     * This function will be called for every domain in the
     * Pending Transfer status when the domain sync cron runs.
     *
     * Check status of incoming domain transfers and notify end-user upon
     * completion. This function is called daily for incoming domains.
     */

    function gateway_srs_TransferSync($params)
    {
        try {
            $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');
            return Lifecycle::transfer(Module::api($params), $domain);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    # Function to cancel domain transfer
    /*
     *
     */

    function gateway_srs_Cancel_Transfer($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            // Build post data
            $domain_info = [
                'name' => $domain
            ];

            $api->cancel_domain_transfer($domain_info);

            return true;

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to reject domain transfer
    /*
     *
     */

    function gateway_srs_Reject_Transfer($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);

            // Submit Rejection (the endpoint is addressed by name; no wid lookup is needed)
            $api->reject_transfer_domain(['name' => $domain]);

            return true;

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to Approve domain transfer
    /*
     *
     */

    function gateway_srs_Approve_Transfer($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Connect To API
            $api = Module::api($params);


            // Submit Approval (the endpoint is addressed by name; no wid lookup is needed)
            $api->approve_transfer_domain(['name' => $domain]);

            return ['success' => true];

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to lock domain
    /*
     *
     */

    function gateway_srs_Lock_Domain($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Call API
            $api = Module::api($params);

            $api->lock($domain);


        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    # Function to unlock domain
    /*
     *
     */

    function gateway_srs_Unlock_Domain($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            //  Call API
            $api = Module::api($params);

            $api->unlock($domain);

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    function gateway_srs_Block_Domain($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Call API
            $api = Module::api($params);

            $api->block($domain);


        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    function gateway_srs_Unblock_Domain($params)
    {
        try {
            // Domain parameters
            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            // Call API
            $api = Module::api($params);

            $api->unblock($domain);


        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    # Function custom button in admin area
    /*
     *
     */

    function gateway_srs_AdminCustomButtonArray($params)
    {
        $buttons = ['Update EPP Key' => 'Update_EPP_key'];
        try {
            $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');
            $api = Module::api(array_merge(Module::settings(), $params));
            $codes = array_column($api->domain_sync($domain)['statuses'] ?? [], 'code');
            if (in_array('pendingTransfer', $codes, true)) {
                $buttons += ['Approve Transfer' => 'Approve_Transfer', 'Reject Transfer' => 'Reject_Transfer', 'Cancel Transfer' => 'Cancel_Transfer'];
            }
            foreach ([
                ['clientTransferProhibited', 'Unlock Transfers', 'Unlock_Domain', 'Lock Transfers', 'Lock_Domain'],
                ['clientHold', 'Unsuspend', 'Unsuspend_Domain', 'Suspend', 'Suspend_Domain'],
                ['clientUpdateProhibited', 'Unblock Updates', 'Unblock_Domain', 'Block Updates', 'Block_Domain'],
            ] as [$status, $onLabel, $onAction, $offLabel, $offAction]) {
                if (in_array($status, $codes, true)) { $buttons[$onLabel] = $onAction; }
                else { $buttons[$offLabel] = $offAction; }
            }
        } catch (\Throwable $e) {
            // An expired token must not prevent WHMCS from displaying the domain admin page.
        }
        return $buttons;
    }

    # Function update domain status for transfer
    /*
     * Called when API returns "transferPending".
     *
     * Function will update domain status in WHMCS database.
     */

    function gateway_srs_Suspend_Domain($params)
    {
        try {

            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            $api = Module::api($params);
            $result = $api->suspend($domain);

            return $result;

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    function gateway_srs_Unsuspend_Domain($params)
    {
        try {

            $sld = $params['sld'];
            $tld = ltrim($params['tld'], '.');
            $domain = $sld . '.' . $tld;

            $api = Module::api($params);
            $result = $api->unsuspend($domain);

            return $result;

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    function gateway_srs_TransferStatusUpdate($domainID, $message)
    {
        if (strpos($message, 'pendingTransfer') !== false) {
            gateway_srs_updateDomainDatabaseStatus($domainID, 'Pending Transfer');
            return ['error' => 'Domain status "Pending Transfer"'];
        }
        return ['error' => $message];
    }

    function gateway_srs_GetTldPricing($params)
    {
        try {
            $rows = Module::api($params)->tld_pricing();
            $grouped = [];
            foreach ($rows as $row) {
                if (!isset($row['tld'], $row['action'], $row['price'], $row['currency']) || !is_numeric($row['price'])) {
                    throw new \RuntimeException('Gateway SRS returned invalid pricing data.');
                }
                $action = $row['action'];
                // Exclude premium/closed/special products from standard retail cost imports.
                if (!in_array($action, ['new', 'renew', 'transfer', 'redeem'], true)
                    || ($row['product_code'] ?? '') !== $row['tld'] . '_' . $action) { continue; }
                $tld = $row['tld'];
                if (isset($grouped[$tld]['currency']) && $grouped[$tld]['currency'] !== $row['currency']) {
                    throw new \RuntimeException('Gateway SRS returned mixed currencies for one extension.');
                }
                $grouped[$tld]['currency'] = $row['currency'];
                $grouped[$tld][$action] = (float) $row['price'];
            }
            $results = new \WHMCS\Results\ResultsList();
            foreach ($grouped as $tld => $prices) {
                if (!isset($prices['new'])) { continue; }
                $item = (new ImportItem())->setExtension($tld)->setMinYears(1)->setMaxYears(1)
                    ->setRegisterPrice($prices['new'])->setRenewPrice($prices['renew'] ?? null)
                    ->setTransferPrice($prices['transfer'] ?? null)->setRedemptionFeePrice($prices['redeem'] ?? null)
                    ->setCurrency($prices['currency'])->setEppRequired(!in_array($tld, ['co.za', 'net.za', 'org.za', 'web.za'], true));
                $results[] = $item;
            }
            return $results;
        } catch (\Throwable $e) { return ['error' => $e->getMessage()]; }
    }

    # Function update domain status in WHMCS database
    /*
     *
     */

    function gateway_srs_updateDomainDatabaseStatus($domainId, $status)
    {
        return Capsule::table('tbldomains')
            ->where('id', $domainId)
            ->update(array(
                    'status' => $status
                )
            );
    }


    function gateway_srs_ClientAreaCustomButtonArray()
    {
        return ['DNSSEC' => 'DNSSEC'];
    }

    function gateway_srs_DNSSEC($params)
    {
        return ['templatefile' => 'dnssec', 'vars' => ['dnssecHtml' => DnssecPage::client($params)]];
    }

    function gateway_srs_ClientArea($params)
    {
        return '<p><a class="btn btn-default" href="' . Module::escape(DnssecPage::link((int) ($params['domainid'] ?? 0)))
            . '">Manage DNSSEC</a></p>';
    }

    // WHMCS calls <registrar>_AdminDomainsTabFields; "AdminClientDomainsTabFields" is only a hook point name.
    function gateway_srs_AdminDomainsTabFields($params)
    {
        if (!Module::canManage()) { return []; }
        $fields = ['DNSSEC' => '<a href="addonmodules.php?module=gateway_srs_manager&amp;domainid='
            . (int) ($params['domainid'] ?? 0) . '">Manage DNSSEC records</a>'];
        // These params carry only the domain, not the module settings that select the account environment.
        $settings = Module::settings();
        $missing = $settings ? Module::registryMissingFor($settings, ($params['sld'] ?? '') . '.' . ltrim($params['tld'] ?? '', '.')) : null;
        if ($missing) {
            $fields['Registry status'] = '<span class="label label-danger">Not found at the registry</span> since '
                . gmdate('Y-m-d H:i', (int) $missing['first_seen']) . ' UTC ('
                . Module::escape(RegistryWatch::label(RegistryWatch::environment($settings)))
                . ' environment). The Gateway SRS API returned HTTP 404, so clients cannot manage this domain.'
                . ' Check Account Mode and the domain\'s registrar assignment in the Gateway SRS portal.';
        }
        return $fields;
    }

    function gateway_srs_GetDomainInformation($params)
    {
        try {
            $name = $params['sld'] . '.' . ltrim($params['tld'], '.');
            try {
                $info = Module::api($params)->view_domain($name);
            } catch (RestAPIException $e) {
                if ($e->getCode() === 404) { Module::registryMissing($params, $name); }
                throw $e;
            }
            Module::registryFound($params, $name);
            $nameservers = [];
            foreach ($info['hosts'] ?? $info['nameservers'] ?? [] as $host) {
                $nameservers['ns' . (count($nameservers) + 1)] = is_array($host) ? $host['hostname'] : $host;
            }
            $statuses = Lifecycle::codes($info);
            $expiry = \WHMCS\Carbon::createFromTimestampUTC(Lifecycle::expiry($info));
            $status = Lifecycle::registrationStatus($info);
            return (new \WHMCS\Domain\Registrar\Domain())->setDomain($name)->setNameservers($nameservers)
                ->setExpiryDate($expiry)->setRegistrationStatus($status)
                ->setRestorable((bool) array_intersect(['redemptionPeriod', 'pendingRestore'], $statuses))
                ->setTransferLock((bool) array_intersect(['clientTransferProhibited', 'serverTransferProhibited'], $statuses));
        } catch (\Throwable $e) { throw new \RuntimeException($e->getMessage(), 0, $e); }
    }
