<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Api/ErliApiClient.php';

class ErliProductApi
{
    /** @var ErliApiClient */
    private $client;

    public function __construct()
    {
        $this->client = new ErliApiClient(Configuration::get('ERLI_API_KEY'));
    }

    /**
     * CREATE: POST /products/{productId}
     */
    public function createProduct($productId, array $payload)
    {
        return $this->client->post('/products/' . rawurlencode((string)$productId), $payload);
    }

    /**
     * UPDATE: PATCH /products/{productId}
     */
    public function updateProduct($productId, array $payload)
    {
        return $this->client->patch('/products/' . rawurlencode((string)$productId), $payload);
    }

    /**
     * GET /products/{productId}
     */
    public function getProduct($productId)
    {
        return $this->client->get('/products/' . rawurlencode((string)$productId));
    }
}
