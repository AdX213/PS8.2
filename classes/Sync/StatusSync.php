<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Api/ErliOrderApi.php';
//
// DO POPRAWY 
//
//
//
class StatusSync
{
    const CURSOR_KEY = 'ERLI_STATUS_CURSOR_ID';

    /** @var ErliOrderApi */
    private $api;

    public function __construct()
    {
        $this->api = new ErliOrderApi();
    }

    public function syncAll($limit = 200)
    {
        $limit = max(1, (int) $limit);

        $cursor = (int) Configuration::get(self::CURSOR_KEY);
        if ($cursor < 0) {
            $cursor = 0;
        }

        $rows = $this->fetchRowsAfterCursor($cursor, $limit);
        if (!$rows) {
            $cursor = 0;
            $rows = $this->fetchRowsAfterCursor($cursor, $limit);
        }

        if (!$rows) {
            return 0;
        }

        $checked = 0;
        $lastIdProcessed = $cursor;

        foreach ($rows as $row) {
            $checked++;
            $lastIdProcessed = (int) $row['id_erli_order_link'];

            $linkId = (int) $row['id_erli_order_link'];
            $idOrder = (int) $row['id_order'];
            $erliOrderId = (string) $row['erli_order_id'];
            $lastStatus = (string) ($row['last_status'] ?? '');

            try {
                $resp = $this->api->getOrder($erliOrderId);
                $code = (int) ($resp['code'] ?? 0);

                if (!in_array($code, [200, 202], true)) {
                    // 404: zamówienie nie istnieje / usunięte w ERLI – log i pomijamy
                    $raw = (string) ($resp['raw'] ?? '');
                    throw new Exception('ERLI HTTP ' . $code . ': ' . $raw);
                }

                $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
                $currentStatus = '';
                if (isset($body['status'])) {
                    $currentStatus = (string) $body['status'];
                } elseif (isset($body['state'])) {
                    $currentStatus = (string) $body['state'];
                } elseif (isset($body['orderStatus'])) {
                    $currentStatus = (string) $body['orderStatus'];
                }

                if ($currentStatus === '') {
                    continue;
                }

                if ($lastStatus !== '' && $this->norm($lastStatus) === $this->norm($currentStatus)) {
                    continue;
                }

                $idOrderState = $this->mapErliToPsState($currentStatus);
                if ($idOrderState > 0) {
                    $this->applyPsOrderState($idOrder, $idOrderState);
                }

                Db::getInstance()->update(
                    'erli_order_link',
                    [
                        'last_status' => pSQL($currentStatus),
                    ],
                    'id_erli_order_link=' . (int) $linkId
                );
            } catch (Throwable $e) {
                Db::getInstance()->insert('erli_log', [
                    'type'         => pSQL('status_sync'),
                    'reference_id' => pSQL($erliOrderId),
                    'message'      => pSQL($e->getMessage(), true),
                    'payload'      => null,
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        }

        Configuration::updateValue(self::CURSOR_KEY, (int) $lastIdProcessed);

        return $checked;
    }

    private function fetchRowsAfterCursor($cursor, $limit)
    {
        return Db::getInstance()->executeS(
            'SELECT id_erli_order_link, id_order, erli_order_id, last_status
             FROM `' . _DB_PREFIX_ . 'erli_order_link`
             WHERE erli_order_id IS NOT NULL AND erli_order_id != ""
               AND id_erli_order_link > ' . (int) $cursor . '
             ORDER BY id_erli_order_link ASC
             LIMIT ' . (int) $limit
        );
    }

    private function norm($s)
    {
        return Tools::strtolower(trim((string) $s));
    }

    private function mapErliToPsState($erliStatus)
    {
        $s = $this->norm($erliStatus);

        $pending   = (int) Configuration::get('ERLI_STATE_PENDING');
        $paid      = (int) Configuration::get('ERLI_STATE_PAID');
        $cancelled = (int) Configuration::get('ERLI_STATE_CANCELLED');

        if (strpos($s, 'cancel') !== false || strpos($s, 'anul') !== false) {
            return $cancelled;
        }
        if (strpos($s, 'paid') !== false || strpos($s, 'opłac') !== false || strpos($s, 'payment') !== false) {
            return $paid;
        }
        if (strpos($s, 'new') !== false || strpos($s, 'pending') !== false || strpos($s, 'oczek') !== false) {
            return $pending;
        }

        return 0;
    }

    private function applyPsOrderState($idOrder, $idOrderState)
    {
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order)) {
            throw new Exception('Nie znaleziono zamówienia PS id=' . (int) $idOrder);
        }

        $history = new OrderHistory();
        $history->id_order = (int) $idOrder;
        $history->changeIdOrderState((int) $idOrderState, $order, true);
        $history->addWithemail(true);
    }
}
