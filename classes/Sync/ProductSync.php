<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Api/ErliProductApi.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Mapper/ProductMapper.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/ProductLinkRepository.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/LogRepository.php';

class ProductSync
{
    /** @var int */
    private $shopId;

    /** @var int */
    private $langId;

    /** @var ErliProductApi */
    private $api;

    /** @var LogRepository|null */
    private $logger;

    /** @var string|null cache taga cennika dostaw ERLI; '' = brak mapowania */
    private $erliShippingTag = null;

    const CURSOR_KEY = 'ERLI_PRODUCT_CURSOR_ID';

    public function __construct()
    {
        $ctx          = Context::getContext();
        $this->shopId = (int) $ctx->shop->id;
        $this->langId = (int) $ctx->language->id;

        $this->api    = new ErliProductApi();

        $this->logger = class_exists('LogRepository') ? new LogRepository() : null;
    }

    /* =======================================================================
     *  LOGOWANIE
     * ===================================================================== */

    private function log($type, $referenceId, $message, $payload = null)
    {
        $type        = (string) $type;
        $referenceId = (string) $referenceId;
        $message     = (string) $message;

        if ($this->logger && method_exists($this->logger, 'add')) {
            try {
                $this->logger->add($type, $referenceId, $message, $payload);
                return;
            } catch (Throwable $e) {
                // nie blokujemy synca
            }
        }

        $line = '[ERLI][' . $type . '][' . $referenceId . '] ' . $message;
        if ($payload !== null) {
            if (!is_string($payload)) {
                $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $line .= ' | payload=' . $payload;
        }
        error_log($line);
    }

    private function logInfo($type, $referenceId, $message, $payload = null)
    {
        $this->log($type, $referenceId, 'INFO: ' . $message, $payload);
    }

    private function logError($type, $referenceId, $message, $payload = null)
    {
        $this->log($type, $referenceId, 'ERROR: ' . $message, $payload);
    }

    /* =======================================================================
     *  SYNC 1 PRODUKTU (WARIANTY)
     * ===================================================================== */

    /**
     * Synchronizuje produkt do ERLI.
     *
     * Zasada:
     * - jeśli produkt ma kombinacje -> wysyłamy KAŻDĄ kombinację jako osobny "produkt" na ERLI
     *   i każdy z nich dostaje externalVariantGroup + externalAttributes
     * - jeśli nie ma kombinacji -> wysyłamy produkt prosty (bez externalVariantGroup)
     */
    public function syncSingle($idProduct, $idProductAttribute = 0)
    {
        $idProduct = (int) $idProduct;
        $idProductAttribute = (int) $idProductAttribute;

        if ($idProduct <= 0) {
            throw new Exception('Niepoprawne ID produktu.');
        }

        $this->logInfo('product_sync_single', $idProduct, 'Start syncSingle, id_product_attribute=' . $idProductAttribute);

        $product = new Product($idProduct, false, $this->langId, $this->shopId);
        if (!Validate::isLoadedObject($product)) {
            $msg = 'Nie znaleziono produktu PS id=' . $idProduct;
            $this->logError('product_sync_single', $idProduct, $msg);
            throw new Exception($msg);
        }

        // NOWE: pomijamy produkty nieaktywne w PrestaShop
        if (!$product->active) {
        $this->logInfo(
            'product_sync_single',
            $idProduct,
            'Produkt nieaktywny w PrestaShop – pomijam wysyłkę do ERLI.'
        );
        return -1;
        }

        // jeśli admin wywoła sync dla konkretnej kombinacji
        if ($idProductAttribute > 0) {
            return (int) $this->syncOneErliItem($product, $idProduct, $idProductAttribute);
        }

        // jeśli produkt ma kombinacje -> wysyłamy WSZYSTKIE
        $combRows = Product::getProductAttributesIds($idProduct);
        if (!empty($combRows) && is_array($combRows)) {
            $this->logInfo('product_sync_single', $idProduct, 'Produkt ma kombinacje: ' . count($combRows) . ' -> sync wariantów');

            $lastCode = 0;
            foreach ($combRows as $r) {
                $idPa = (int) ($r['id_product_attribute'] ?? 0);
                if ($idPa <= 0) {
                    continue;
                }
                $lastCode = (int) $this->syncOneErliItem($product, $idProduct, $idPa);
            }
            return (int) $lastCode;
        }

        // produkt prosty
        return (int) $this->syncOneErliItem($product, $idProduct, null);
    }

    /**
     * Wysyła 1 ofertę do ERLI:
     * - $idProductAttribute = null => produkt prosty
     * - $idProductAttribute > 0 => wariant
     */
    private function syncOneErliItem(Product $product, int $idProduct, $idProductAttribute = null)
    {
        $isVariant = ($idProductAttribute !== null && (int) $idProductAttribute > 0);
        $idPa = $isVariant ? (int) $idProductAttribute : null;

        // externalId musi być unikalne per wariant
        $externalId = $this->buildDefaultExternalId($idProduct, $isVariant ? $idPa : 0);

        $this->logInfo(
            'product_sync_item',
            $externalId,
            'Mapowanie payloadu, ' . ($isVariant ? 'variant=' . $idPa : 'simple')
        );

        // mapowanie produktu -> payload
        $payload = ProductMapper::map($product, $this->langId, $idPa);

        // dopisz mapowanie dostawy (priceList tag) z ps_erli_shipping_map
        $payload = $this->addShippingToPayload($payload);

        // externalId w body (dla debug / spójności) - ERLI i tak identyfikuje po URL
        $payload['externalId'] = (string) $externalId;

        // DEBUG: warianty
        if ($isVariant) {
            $this->logInfo(
                'product_variant_group',
                $externalId,
                'externalVariantGroup + externalAttributes (debug)',
                json_encode([
                    'externalVariantGroup' => $payload['externalVariantGroup'] ?? null,
                    'externalAttributes'   => $payload['externalAttributes'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        // update -> fallback create
        $resp = $this->api->updateProduct($externalId, $payload);
        $code = (int) ($resp['code'] ?? 0);

        if ($code === 404) {
            $this->logInfo('product_sync_item', $externalId, '404 -> createProduct');
            $resp = $this->api->createProduct($externalId, $payload);
            $code = (int) ($resp['code'] ?? 0);
        }

        if ($code >= 200 && $code < 300) {
            $repo = new ProductLinkRepository();
            $repo->save(
                $idProduct,
                $isVariant ? $idPa : null,
                (string) $externalId,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            $this->logInfo('product_sync_item', $externalId, 'OK, HTTP ' . $code);
            return $code;
        }

        $raw = (string) ($resp['raw'] ?? '');
        $msg = 'Erli API error. HTTP ' . $code . ' -> ' . $raw;
        $this->logError('product_sync_item', $externalId, $msg, $raw);

        throw new Exception($msg);
    }

    /* =======================================================================
     *  PRZYGOTOWANIE LINKÓW (SIMPLE + WARIANTY)
     * ===================================================================== */

    /**
     * Tworzy rekordy w erli_product_link:
     * - dla produktów bez kombinacji: 1 rekord (id_product_attribute = NULL)
     * - dla produktów z kombinacjami: rekord dla KAŻDEJ kombinacji (id_product_attribute = ID)
     */
    public function prepareAllProducts()
    {
        $this->logInfo('product_prepare_all', '-', 'Start prepareAllProducts (warianty)');

        $rows = Db::getInstance()->executeS(
            'SELECT p.id_product
             FROM `' . _DB_PREFIX_ . 'product` p
             WHERE p.active = 1
             ORDER BY p.id_product ASC'
        );

        $added = 0;

        foreach ($rows ?: [] as $row) {
            $idProduct = (int) $row['id_product'];
            if ($idProduct <= 0) {
                continue;
            }

            // sprawdzamy kombinacje
            $combRows = Product::getProductAttributesIds($idProduct);

            if (!empty($combRows) && is_array($combRows)) {
                // usuń ewentualny wpis bazowy (NULL/0), żeby nie dublować
                Db::getInstance()->delete(
                    'erli_product_link',
                    'id_product=' . (int) $idProduct . ' AND (id_product_attribute IS NULL OR id_product_attribute = 0)'
                );

                foreach ($combRows as $r) {
                    $idPa = (int) ($r['id_product_attribute'] ?? 0);
                    if ($idPa <= 0) {
                        continue;
                    }

                    $externalId = (string) $this->buildDefaultExternalId($idProduct, $idPa);

                    $data = [
                        'id_product'           => (int) $idProduct,
                        'id_product_attribute' => (int) $idPa,
                        'external_id'          => pSQL($externalId),
                        'last_payload'         => null,
                        'last_synced_at'       => null,
                        'last_error'           => null,
                    ];

                    $ok = Db::getInstance()->insert('erli_product_link', $data, false, true, Db::INSERT_IGNORE);
                    if ($ok && Db::getInstance()->Affected_Rows() > 0) {
                        $added++;
                    }
                }

                continue;
            }

            // produkt prosty - czyścimy ewentualne wpisy z id_product_attribute=0 (stara wersja)
            Db::getInstance()->delete(
                'erli_product_link',
                'id_product=' . (int) $idProduct . ' AND id_product_attribute = 0'
            );

            $externalId = (string) $this->buildDefaultExternalId($idProduct, 0);

            $data = [
                'id_product'           => (int) $idProduct,
                'id_product_attribute' => null, // WAŻNE: NULL dla prostych
                'external_id'          => pSQL($externalId),
                'last_payload'         => null,
                'last_synced_at'       => null,
                'last_error'           => null,
            ];

            $ok = Db::getInstance()->insert('erli_product_link', $data, false, true, Db::INSERT_IGNORE);
            if ($ok && Db::getInstance()->Affected_Rows() > 0) {
                $added++;
            }
        }

        $this->logInfo('product_prepare_all', '-', 'Koniec prepareAllProducts, added=' . $added);

        return $added;
    }

    /* =======================================================================
     *  WSZYSTKIE / PENDING
     * ===================================================================== */

    /**
     * Wysyła WSZYSTKIE rekordy pending (NULL synced lub error), partiami.
     */
    public function syncAllPending($batchSize = 20)
    {
        $batchSize = max(1, (int) $batchSize);

        $this->logInfo('product_sync_all_pending', '-', 'Start syncAllPending, batchSize=' . $batchSize);

        $totalProcessed = 0;
        $this->setCursor(0);

        while (true) {
            $cursor = $this->getCursor();

            $rows = $this->fetchPendingRowsAfterCursor($cursor, $batchSize);
            if (!$rows) {
                break;
            }

            $lastIdProcessed = $cursor;

            foreach ($rows as $row) {
                $lastIdProcessed = (int) $row['id_erli_product_link'];

                try {
                    $this->syncLinkRow($row);
                    $totalProcessed++;
                } catch (Throwable $e) {
                    if (strpos($e->getMessage(), 'HTTP 429') !== false) {
                        $this->logError('product_sync_all_pending', '-', 'Przerwano batch z powodu 429: ' . $e->getMessage());
                        $this->setCursor($lastIdProcessed);
                        break 2;
                    }
                    throw $e;
                }
            }

            $this->setCursor($lastIdProcessed);

            if (count($rows) < $batchSize) {
                break;
            }
        }

        $this->setCursor(0);

        $this->logInfo('product_sync_all_pending', '-', 'Koniec syncAllPending, totalProcessed=' . $totalProcessed);

        return $totalProcessed;
    }

    /**
     * Wysyła wszystkie rekordy z erli_product_link (niezależnie od błędów).
     */
    public function syncAll($batchSize = 20)
    {
        $batchSize = max(1, (int) $batchSize);

        $this->logInfo('product_sync_all', '-', 'Start syncAll, batchSize=' . $batchSize);

        $totalProcessed = 0;
        $this->setCursor(0);

        while (true) {
            $cursor = $this->getCursor();

            $rows = $this->fetchAllRowsAfterCursor($cursor, $batchSize);
            if (!$rows) {
                break;
            }

            $lastIdProcessed = $cursor;

            foreach ($rows as $row) {
                $lastIdProcessed = (int) $row['id_erli_product_link'];

                try {
                    $this->syncLinkRow($row);
                    $totalProcessed++;
                } catch (Throwable $e) {
                    if (strpos($e->getMessage(), 'HTTP 429') !== false) {
                        $this->logError('product_sync_all', '-', 'Przerwano batch z powodu 429: ' . $e->getMessage());
                        $this->setCursor($lastIdProcessed);
                        break 2;
                    }
                    throw $e;
                }
            }

            $this->setCursor($lastIdProcessed);

            if (count($rows) < $batchSize) {
                break;
            }
        }

        $this->setCursor(0);

        $this->logInfo('product_sync_all', '-', 'Koniec syncAll, totalProcessed=' . $totalProcessed);

        return $totalProcessed;
    }

    /* =======================================================================
     *  BAZOWE SELECTY / KURSOR
     * ===================================================================== */

    private function buildDefaultExternalId($idProduct, $idProductAttribute = 0)
    {
        $idProduct = (int) $idProduct;
        $idProductAttribute = (int) $idProductAttribute;

        if ($idProductAttribute > 0) {
            return 'ps-' . $idProduct . '-' . $idProductAttribute;
        }

        return 'ps-' . $idProduct;
    }

    private function getCursor()
    {
        $cursor = (int) Configuration::get(self::CURSOR_KEY);
        return ($cursor < 0) ? 0 : $cursor;
    }

    private function setCursor($id)
    {
        Configuration::updateValue(self::CURSOR_KEY, (int) $id);
    }

    private function fetchPendingRowsAfterCursor($cursor, $limit)
    {
        return Db::getInstance()->executeS(
            'SELECT id_erli_product_link, id_product, id_product_attribute, external_id, last_payload
             FROM `' . _DB_PREFIX_ . 'erli_product_link`
             WHERE (last_synced_at IS NULL OR last_error IS NOT NULL)
               AND external_id IS NOT NULL AND external_id != ""
               AND id_erli_product_link > ' . (int) $cursor . '
             ORDER BY id_erli_product_link ASC
             LIMIT ' . (int) $limit
        );
    }

    private function fetchAllRowsAfterCursor($cursor, $limit)
    {
        return Db::getInstance()->executeS(
            'SELECT id_erli_product_link, id_product, id_product_attribute, external_id, last_payload
             FROM `' . _DB_PREFIX_ . 'erli_product_link`
             WHERE external_id IS NOT NULL AND external_id != ""
               AND id_erli_product_link > ' . (int) $cursor . '
             ORDER BY id_erli_product_link ASC
             LIMIT ' . (int) $limit
        );
    }

    /* =======================================================================
     *  SYNC POJEDYNCZEGO LINKU
     * ===================================================================== */

    private function syncLinkRow(array $row)
    {
        $linkId     = (int) ($row['id_erli_product_link'] ?? 0);
        $idProduct  = (int) ($row['id_product'] ?? 0);
        $externalId = trim((string) ($row['external_id'] ?? ''));

        $idPaRaw = $row['id_product_attribute'] ?? null;
        $idPa = ($idPaRaw === null) ? null : (int) $idPaRaw;
        if ($idPa === 0) {
            $idPa = null;
        }

        if ($linkId <= 0 || $idProduct <= 0 || $externalId === '') {
            throw new Exception('Niepoprawny rekord linku produktu.');
        }

        $product = new Product($idProduct, false, $this->langId, $this->shopId);
        if (!Validate::isLoadedObject($product)) {
            throw new Exception('Nie znaleziono produktu PS id=' . $idProduct);
        }

        // NOWE: pomijamy nieaktywne produkty w batchach
        if (!$product->active) {
            $this->logInfo('product_sync_link', $externalId, 'Produkt nieaktywny w PrestaShop – pomijam update w ERLI.');
            // oznaczamy rekord jako "bez błędu", ale nie aktualizujemy last_synced_at
            $this->updateLinkError($linkId, null);
            return;
        }

        $payload = ProductMapper::map($product, $this->langId, $idPa);

        // dopisz mapowanie dostawy (priceList tag) z ps_erli_shipping_map
        $payload = $this->addShippingToPayload($payload);

        $payload['externalId'] = $externalId;

        // jeśli dane identyczne jak w last_payload -> skip
        $prev = $this->decodeJson((string) ($row['last_payload'] ?? ''));
        if (is_array($prev)) {
            $prevStable = $this->stableJsonEncode($prev);
            $newStable  = $this->stableJsonEncode($payload);
            if ($prevStable === $newStable) {
                $this->updateLinkSuccess($linkId, $payload, true);
                return;
            }
        }

        $resp = $this->api->updateProduct($externalId, $payload);
        $code = (int) ($resp['code'] ?? 0);

        if ($code === 404) {
            $resp = $this->api->createProduct($externalId, $payload);
            $code = (int) ($resp['code'] ?? 0);
        }

        $raw = (string) ($resp['raw'] ?? '');

        if (in_array($code, [200, 201, 202], true)) {
            $this->updateLinkSuccess($linkId, $payload, true);
            return;
        }

        $msg = 'ERLI HTTP ' . $code . ': ' . $raw;
        $this->updateLinkError($linkId, $msg);

        throw new Exception($msg);
    }

    /* =======================================================================
     *  UPDATE LINKU, JSON
     * ===================================================================== */

    private function updateLinkSuccess($linkId, array $payload, $touchSyncedAt)
    {
        $linkId = (int) $linkId;
        if ($linkId <= 0) {
            return false;
        }

        $data = [
            'last_payload' => pSQL($this->stableJsonEncode($payload), true),
            'last_error'   => null,
        ];

        if ($touchSyncedAt) {
            $data['last_synced_at'] = date('Y-m-d H:i:s');
        }

        return Db::getInstance()->update(
            'erli_product_link',
            $data,
            'id_erli_product_link = ' . (int) $linkId
        );
    }

    private function updateLinkError($linkId, $errorMessage)
    {
        $linkId = (int) $linkId;
        if ($linkId <= 0) {
            return false;
        }

        return Db::getInstance()->update(
            'erli_product_link',
            [
                'last_error' => $errorMessage === null
                    ? null
                    : pSQL((string) $errorMessage, true),
            ],
            'id_erli_product_link = ' . (int) $linkId
        );
    }

    private function decodeJson($s)
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        $d = json_decode($s, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $d : null;
    }

    private function stableJsonEncode(array $data)
    {
        $data = $this->normalizeForCompare($data);
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalizeForCompare($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $out = [];
            foreach ($value as $v) {
                $out[] = $this->normalizeForCompare($v);
            }
            return $out;
        }

        foreach ($value as $k => $v) {
            $value[$k] = $this->normalizeForCompare($v);
        }
        ksort($value);

        return $value;
    }

    /* =======================================================================
     *  MAPOWANIE DOSTAW (ERLI PRICE LIST TAG)
     * ===================================================================== */

    /**
     * Zwraca tag cennika ERLI z mapowania dla domyślnego przewoźnika (ERLI_DEFAULT_CARRIER).
     * Cache'uje wynik w $this->erliShippingTag:
     *  - null  => jeszcze nie sprawdzano
     *  - ''    => sprawdzono, brak mapowania
     *  - 'TAG' => poprawny tag
     */
    private function getErliShippingTag()
    {
        if ($this->erliShippingTag !== null) {
            return ($this->erliShippingTag === '') ? null : $this->erliShippingTag;
        }

        $defaultCarrier = (int) Configuration::get('ERLI_DEFAULT_CARRIER');
        if ($defaultCarrier <= 0) {
            $this->erliShippingTag = '';
            return null;
        }

        $tag = Db::getInstance()->getValue(
            'SELECT erli_tag
             FROM `' . _DB_PREFIX_ . 'erli_shipping_map`
             WHERE id_carrier = ' . (int) $defaultCarrier
        );

        if (!$tag) {
            $this->erliShippingTag = '';
            return null;
        }

        $this->erliShippingTag = (string) $tag;

        $this->logInfo('product_shipping_tag', '-', 'Używany cennik ERLI: ' . $this->erliShippingTag);

        return $this->erliShippingTag;
    }

    /**
     * Dokleja do payloadu sekcję "packaging.tags" z tagiem cennika dostaw ERLI.
     * Nie nadpisuje innych pól packaging poza "tags".
     */
    private function addShippingToPayload(array $payload)
    {
        $tag = $this->getErliShippingTag();
        if ($tag === null) {
            return $payload;
        }

        if (!isset($payload['packaging']) || !is_array($payload['packaging'])) {
            $payload['packaging'] = [];
        }

        $payload['packaging']['tags'] = [(string) $tag];

        return $payload;
    }
}
