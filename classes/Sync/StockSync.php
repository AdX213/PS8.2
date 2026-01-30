<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Api/ErliProductApi.php';
//
// do poprawy 
//
//
//
class StockSync
{
    /** Cursor key w Configuration */
    const CURSOR_KEY = 'ERLI_STOCK_CURSOR_ID';

    /** @var int */
    private $shopId;

    /** @var int */
    private $langId;

    /** @var ErliProductApi */
    private $api;

    public function __construct()
    {
        $ctx = Context::getContext();
        $this->shopId = (int) $ctx->shop->id;
        $this->langId = (int) $ctx->language->id;

        $this->api = new ErliProductApi();
    }

    public function syncAll($limit = 300)
    {
        $limit = max(1, (int) $limit);

        $cursor = (int) Configuration::get(self::CURSOR_KEY);
        if ($cursor < 0) {
            $cursor = 0;
        }

        // 1) Najpierw próbujemy "po cursorze"
        $rows = $this->fetchRowsAfterCursor($cursor, $limit);

        // 2) Jeśli nic nie ma, zawijamy do początku i reset cursor
        if (!$rows) {
            $cursor = 0;
            $rows = $this->fetchRowsAfterCursor($cursor, $limit);
        }

        if (!$rows) {
            return 0;
        }

        $processed = 0;
        $lastIdProcessed = $cursor;

        foreach ($rows as $row) {
            $processed++;
            $lastIdProcessed = (int) $row['id_erli_product_link'];

            $linkId    = (int) $row['id_erli_product_link'];
            $idProduct = (int) $row['id_product'];
            $idAttr    = (int) ($row['id_product_attribute'] ?? 0);
            $externalId = (string) $row['external_id'];

            try {
                $product = new Product($idProduct, false, $this->langId, $this->shopId);
                if (!Validate::isLoadedObject($product)) {
                    throw new Exception('Nie znaleziono produktu PS id=' . $idProduct);
                }

                $qty = (int) StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr, $this->shopId);

                $status = 'active';
                if (!(bool) $product->active || $qty <= 0) {
                    $status = 'inactive';
                }

                $payload = [
                    'externalId' => $externalId,
                    'status'     => $status,
                    'stock'      => $qty,
                ];

                $resp = $this->api->updateProduct($externalId, $payload);
                $code = (int) ($resp['code'] ?? 0);

                if (!in_array($code, [200, 201, 202], true)) {
                    $raw = (string) ($resp['raw'] ?? '');
                    throw new Exception('ERLI HTTP ' . $code . ': ' . $raw);
                }

                Db::getInstance()->update(
                    'erli_product_link',
                    [
                        'last_synced_at' => date('Y-m-d H:i:s'),
                        'last_error'     => null,
                    ],
                    'id_erli_product_link=' . (int) $linkId
                );
            } catch (Throwable $e) {
                Db::getInstance()->update(
                    'erli_product_link',
                    [
                        'last_error' => pSQL($e->getMessage(), true),
                    ],
                    'id_erli_product_link=' . (int) $linkId
                );

                Db::getInstance()->insert('erli_log', [
                    'type'         => pSQL('stock_sync'),
                    'reference_id' => pSQL($externalId),
                    'message'      => pSQL($e->getMessage(), true),
                    'payload'      => null,
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Zapis cursor na końcu
        Configuration::updateValue(self::CURSOR_KEY, (int) $lastIdProcessed);

        return $processed;
    }

    private function fetchRowsAfterCursor($cursor, $limit)
    {
        return Db::getInstance()->executeS(
            'SELECT id_erli_product_link, id_product, id_product_attribute, external_id
             FROM `' . _DB_PREFIX_ . 'erli_product_link`
             WHERE external_id IS NOT NULL AND external_id != ""
               AND id_erli_product_link > ' . (int) $cursor . '
             ORDER BY id_erli_product_link ASC
             LIMIT ' . (int) $limit
        );
    }
}
