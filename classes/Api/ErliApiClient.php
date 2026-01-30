<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class ErliApiClient
{
    const BASE_URL_PROD    = 'https://erli.pl/svc/shop-api';
    const BASE_URL_SANDBOX = 'https://sandbox.erli.dev/svc/shop-api';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    public function __construct($apiKey)
    {
        $this->apiKey = trim((string) $apiKey);

        if ($this->apiKey === '') {
            throw new Exception('ERLI API key is empty');
        }

        $useSandbox   = (int) Configuration::get('ERLI_USE_SANDBOX');
        $this->baseUrl = $useSandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PROD;
    }

    public function get($path, array $query = [])
    {
        $url = $this->buildUrl($path, $query);

        return $this->request('GET', $url);
    }

    public function post($path, array $payload = [])
    {
        $url = $this->buildUrl($path);

        return $this->request('POST', $url, $payload);
    }

    public function patch($path, array $payload = [])
    {
        $url = $this->buildUrl($path);

        return $this->request('PATCH', $url, $payload);
    }

    /**
     * Zbudowanie pełnego URL do API.
     */
    private function buildUrl($path, array $query = [])
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * Wykonanie żądania HTTP.
     */
    protected function request($method, $url, array $payload = null)
    {
        $method = strtoupper((string) $method);

        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: ' . $this->buildUserAgent(),
        ];

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?: []));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?: []));
        }

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Curl error: ' . $err);
        }

        curl_close($ch);

        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $body = null;
        }

        return [
            'code' => $code,
            'body' => $body,
            'raw'  => $raw,
        ];
    }

    protected function buildUserAgent()
    {
        return 'PrestaShop-ErliIntegration/1.5';
    }
}
