<?php
namespace WHMCS\Module\Registrar\gateway_srs;

class ReleaseClient
{
    public const REPOSITORY = 'DNS-Africa/WHMCS-Gateway_SRS';
    public const RELEASES_URL = 'https://github.com/' . self::REPOSITORY . '/releases';
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function latest(): array
    {
        $data = json_decode($this->fetch('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest'), true, 512, JSON_THROW_ON_ERROR);
        $tag = $data['tag_name'] ?? '';
        if (!is_string($tag) || !preg_match('/^v?\d+\.\d+\.\d+$/D', $tag)
            || !empty($data['draft']) || !empty($data['prerelease'])) {
            throw new \RuntimeException('The published release metadata is invalid.');
        }
        $assets = [];
        foreach ($data['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? '';
            if (!in_array($name, ['gateway_srs.zip', 'release-manifest.json', 'release-manifest.sig'], true)) {
                continue;
            }
            $expected = 'https://github.com/' . self::REPOSITORY . '/releases/download/' . $tag . '/' . $name;
            if (($asset['browser_download_url'] ?? '') !== $expected || isset($assets[$name])) {
                throw new \RuntimeException('The release contains an unexpected package location.');
            }
            $assets[$name] = $expected;
        }
        return ['version' => ltrim($tag, 'v'), 'tag' => $tag, 'assets' => $assets,
            'url' => self::RELEASES_URL . '/tag/' . $tag,
            'notes' => substr((string) ($data['body'] ?? ''), 0, 20000), 'checked_at' => time()];
    }

    public function fetch(string $url, int $limit = 1048576): string
    {
        for ($redirects = 0; $redirects < 5; $redirects++) {
            self::assertTrustedUrl($url);
            if ($this->transport !== null) {
                $body = ($this->transport)($url);
                if (!is_string($body) || strlen($body) > $limit) {
                    throw new \RuntimeException('The release download exceeded its size limit.');
                }
                return $body;
            }
            $ch = curl_init($url);
            $body = '';
            $location = '';
            try {
                curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90,
                    CURLOPT_USERAGENT => 'GatewaySRS-WHMCS-Updater',
                    CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
                    CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$location) {
                        if (stripos($header, 'Location:') === 0) {
                            $location = trim(substr($header, 9));
                        }
                        return strlen($header);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$body, $limit) {
                        if (strlen($body) + strlen($chunk) > $limit) {
                            return 0;
                        }
                        $body .= $chunk;
                        return strlen($chunk);
                    }]);
                $ok = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($ok === false) {
                    throw new \RuntimeException('Cannot download release data. Check connectivity or use a manual update.');
                }
            } finally {
                unset($ch);
            }
            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = $location;
                continue;
            }
            if ($status !== 200) {
                throw new \RuntimeException('Release service unavailable (HTTP ' . $status . '). Try later or update manually.');
            }
            return $body;
        }
        throw new \RuntimeException('Too many redirects while downloading the release.');
    }

    public static function assertTrustedUrl(string $url): void
    {
        $p = parse_url($url);
        $host = $p['host'] ?? '';
        $path = $p['path'] ?? '';
        $trusted = ($host === 'api.github.com' && strpos($path, '/repos/' . self::REPOSITORY . '/') === 0)
            || ($host === 'github.com' && strpos($path, '/' . self::REPOSITORY . '/releases/download/') === 0)
            || in_array($host, ['release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true);
        if (!$trusted || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass'])
            || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443)) {
            throw new \RuntimeException('Refusing an untrusted release download URL.');
        }
    }
}
