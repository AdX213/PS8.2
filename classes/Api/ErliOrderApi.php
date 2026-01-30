<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Api/ErliApiClient.php';

class ErliOrderApi
{
    /** @var ErliApiClient */
    private $client;

    public function __construct()
    {
        $this->client = new ErliApiClient(Configuration::get('ERLI_API_KEY'));
    }

    /**
     * Pobiera wydarzenia z inboxa.
     *
     * @param int $limit maksymalna liczba zdarzeń
     * @return array ['code' => int, 'body' => mixed, 'raw' => string]
     */
    public function getInbox($limit = 100)
    {
        return $this->client->get('/inbox', ['limit' => (int) $limit]);
    }

    /**
     * Oznacza wiadomości w inboxie jako przeczytane.
     * Wysyłamy ID ostatniej (najświeższej) wiadomości.
     */
    public function ackInbox($lastMessageId)
    {
        return $this->client->post('/inbox', [
            'lastMessageId' => (string) $lastMessageId,
        ]);
    }

    /**
     * Pobiera szczegóły zamówienia po jego ID (payload.id z inboxa).
     */
    public function getOrder($orderId)
    {
        return $this->client->get('/orders/' . rawurlencode($orderId));
    }
}
