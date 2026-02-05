<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/LogRepository.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Sync/ProductSync.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Sync/OrderSync.php';
require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Api/ErliApiClient.php';

class AdminErliIntegrationController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign([
            'output' => '',
        ]);

        // Linki nawigacyjne + AJAX dla mapping.tpl
        $this->assignNavigationLinks();

        // POST z panelu modułu (zapis mapowania / itp.)
        $this->handlePostActionsAndRedirect();

        $view = (string) Tools::getValue('view', 'configure');

        switch ($view) {
            case 'dashboard':
                $this->renderDashboard();
                $this->setTemplate('dashboard.tpl');
                break;

            case 'mapping':
                $this->renderMapping();
                $this->setTemplate('mapping.tpl');
                break;

            case 'products':
                $this->renderProducts();
                $this->setTemplate('products.tpl');
                break;

            case 'orders':
                $this->renderOrders();
                $this->setTemplate('orders.tpl');
                break;

            case 'logs':
                $this->renderLogs();
                $this->setTemplate('logs.tpl');
                break;

            case 'configure':
            default:
                $this->renderConfigure();
                $this->setTemplate('configure.tpl');
                break;
        }
    }

    /**
     * AJAX:
     * index.php?controller=AdminErliIntegration&token=...&ajax=1&erli_action=...
     */
    public function displayAjax()
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $action = (string) Tools::getValue('erli_action');

            // ====== ORDERS ======
            if ($action === 'import_orders') {
                $sync = new OrderSync();
                set_time_limit(0);
                $sync->processInbox(50, 2);
                die(json_encode(['success' => true, 'message' => 'Pobrano inbox z ERLI.']));
            }

            // ====== PRODUCTS ======
            if ($action === 'sync_all_products') {
                $sync = new ProductSync();
                $prepared = (int) $sync->prepareAllProducts();
                $synced   = (int) $sync->syncAllPending(20);

                die(json_encode([
                    'success' => true,
                    'message' => 'Przygotowano ' . $prepared . ' i zsynchronizowano ' . $synced . ' produktów.',
                ]));
            }

            if ($action === 'sync_product') {
            $idProduct = (int) Tools::getValue('id_product');
            if ($idProduct <= 0) {
                die(json_encode([
                    'success' => false,
                    'message' => 'Brak poprawnego id_product'
                ]));
            }

            $sync = new ProductSync();
            $httpCode = (int) $sync->syncSingle($idProduct);

            // NOWE: produkt nieaktywny w PrestaShop
            if ($httpCode === -1) {
                die(json_encode([
                    'success' => false,
                    'message' => 'Produkt ID ' . $idProduct . ' jest nieaktywny w PrestaShop – nie wysłano do ERLI.',
                    'http'    => 0,
                ]));
            }

            // Sukces / błąd wysyłki
            $ok = ($httpCode >= 200 && $httpCode < 300);

            die(json_encode([
                'success' => $ok,
                'message' => $ok
                    ? ('Wysłano produkt ID ' . $idProduct . ' (HTTP ' . $httpCode . ')')
                    : ('Błąd wysyłania produktu ID ' . $idProduct . ' (HTTP ' . $httpCode . ')'),
                'http'    => $httpCode,
            ]));
        }


            // ====== MAPPING: POBIERZ SŁOWNIK KATEGORII ERLI DO BAZY ======
            if ($action === 'fetch_erli_categories') {
                $result = $this->fetchErliCategoriesAndStoreAll(200); // pageSize=200

                $total = (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'erli_category_dictionary`'
                );

                die(json_encode([
                    'success' => true,
                    'message' => 'Pobrano i zapisano słownik kategorii ERLI.',
                    'fetched' => (int) $result['fetched'],
                    'pages'   => (int) $result['pages'],
                    'total_in_db' => $total,
                ]));
            }

            // ====== MAPPING: WYSZUKAJ KATEGORIE Z BAZY (autocomplete) ======
            if ($action === 'search_erli_categories') {
                $q = trim((string) Tools::getValue('q'));
                $limit = (int) Tools::getValue('limit', 30);
                $onlyLeaf = (int) Tools::getValue('leaf', 1);

                if ($limit < 5) $limit = 5;
                if ($limit > 100) $limit = 100;

                if (Tools::strlen($q) < 2) {
                    die(json_encode(['success' => true, 'count' => 0, 'items' => []]));
                }

                $where = [];
                if ($onlyLeaf) {
                    $where[] = 'leaf = 1';
                }

                $like = '%' . pSQL($q) . '%';
                $where[] = '(name LIKE "' . $like . '" OR breadcrumb_json LIKE "' . $like . '")';

                $sql = '
                    SELECT 
                        erli_id,
                        name,
                        leaf,
                        breadcrumb_json
                    FROM `' . _DB_PREFIX_ . 'erli_category_dictionary`
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY
                        (name LIKE "' . pSQL($q) . '%") DESC,
                        erli_id ASC
                    LIMIT ' . (int)$limit;

                $rows = Db::getInstance()->executeS($sql);

                $items = [];
                foreach ($rows ?: [] as $r) {
                    $items[] = [
                        'id' => (string) $r['erli_id'],
                        'name' => (string) $r['name'],
                        'leaf' => ((int) $r['leaf'] === 1),
                        'breadcrumb' => (string) ($r['breadcrumb_json'] ?? ''),
                    ];
                }

                die(json_encode([
                    'success' => true,
                    'count' => count($items),
                    'items' => $items,
                ]));
            }

            die(json_encode(['success' => false, 'message' => 'Nieznana akcja: ' . $action]));
        } catch (Throwable $e) {
            die(json_encode(['success' => false, 'message' => $e->getMessage()]));
        }
    }

    private function assignNavigationLinks()
    {
        $base = $this->context->link->getAdminLink('AdminErliIntegration');

        // WYMUSZ HTTPS jeśli przychodzi http://
        // if (strpos($base, 'http://') === 0) {
        //     $base = 'https://' . substr($base, 7);
        // }

        //protokół URL do bieżącego żądania (HTTPS / HTTP / localhost)
        $base = preg_replace(
            '#^https?://#i',
            (Tools::usingSecureMode() ? 'https://' : 'http://'),
            $base
        );

        // dołóż ajax=1 z uwzględnieniem ?/&
        if (strpos($base, '?') === false) {
            $ajaxBase = $base . '?ajax=1';
        } else {
            $ajaxBase = $base . '&ajax=1';
        }

        $this->context->smarty->assign([
            'link_dashboard' => $base . '&view=dashboard',
            'link_products'  => $base . '&view=products',
            'link_orders'    => $base . '&view=orders',
            'link_logs'      => $base . '&view=logs',
            'link_configure' => $base . '&view=configure',
            'link_mapping'   => $base . '&view=mapping',

            // mapping.tpl używa tych endpointów:
            'link_ajax_fetch_erli_categories'  => $ajaxBase . '&erli_action=fetch_erli_categories',
            'link_ajax_search_erli_categories' => $ajaxBase . '&erli_action=search_erli_categories',
        ]);
    }
    private function msgOk($text)
    {
        if ($this->module && method_exists($this->module, 'displayConfirmation')) {
            return $this->module->displayConfirmation($text);
        }
        return '<div class="alert alert-success">' . Tools::safeOutput($text) . '</div>';
    }

    private function msgErr($text)
    {
        if ($this->module && method_exists($this->module, 'displayError')) {
            return $this->module->displayError($text);
        }
        return '<div class="alert alert-danger">' . Tools::safeOutput($text) . '</div>';
    }

    private function handlePostActionsAndRedirect()
    {
        
        $flash = (string) $this->context->cookie->__get('erli_flash');
        if ($flash !== '') {
            $this->context->smarty->assign(['output' => $flash]);
            $this->context->cookie->__set('erli_flash', '');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $redirectView = (string) Tools::getValue('view', 'configure');
        $conf = null;

        $messageHtml = '';

        try {
            
            // MAPOWANIE KATEGORII
            if (Tools::isSubmit('submitErliSaveCategoryMapping')) {
                $this->saveCategoryMapping();

                $messageHtml  = $this->msgOk('Zapisano mapowanie kategorii.');
                $redirectView = 'mapping';
            }
            elseif (Tools::isSubmit('submitErliSaveShippingMapping')) { // MAPOWANIE DOSTAW
            $this->saveShippingMapping();

            $messageHtml  = $this->msgOk('Zapisano mapowanie metod dostawy.');
            $redirectView = 'mapping';
            }

            // ZAPIS USTAWIEŃ (SIDEBAR)
            elseif (Tools::isSubmit('submitErliIntegration')) {
                $apiKey     = (string) Tools::getValue('ERLI_API_KEY');
                $cronToken  = (string) Tools::getValue('ERLI_CRON_TOKEN');
                $useSandbox = (int) Tools::getValue('ERLI_USE_SANDBOX');

                $defaultCarrier = (int) Tools::getValue('ERLI_DEFAULT_CARRIER');
                $defaultOrderSt = (int) Tools::getValue('ERLI_DEFAULT_ORDER_STATE');
                $statePending   = (int) Tools::getValue('ERLI_STATE_PENDING');
                $statePaid      = (int) Tools::getValue('ERLI_STATE_PAID');
                $stateCancelled = (int) Tools::getValue('ERLI_STATE_CANCELLED');

                Configuration::updateValue('ERLI_API_KEY', trim($apiKey));
                Configuration::updateValue('ERLI_CRON_TOKEN', $cronToken ?: Tools::passwdGen(32));
                Configuration::updateValue('ERLI_USE_SANDBOX', $useSandbox ? 1 : 0);
                Configuration::updateValue('ERLI_DEFAULT_CARRIER', $defaultCarrier);
                Configuration::updateValue('ERLI_DEFAULT_ORDER_STATE', $defaultOrderSt);
                Configuration::updateValue('ERLI_STATE_PENDING', $statePending);
                Configuration::updateValue('ERLI_STATE_PAID', $statePaid);
                Configuration::updateValue('ERLI_STATE_CANCELLED', $stateCancelled);

                $conf = 4;

                $redirectView = 'configure';
            }

        } catch (Throwable $e) {
            // błąd → pokaż jako flash i wróć na ten sam widok
            $messageHtml = $this->msgErr($e->getMessage());
        }

        // 3) zapisz flash
        if ($messageHtml !== '') {
            $this->context->cookie->__set('erli_flash', $messageHtml);
        }

        // 4) redirect (zawsze poprawny token przez getAdminLink)
        $url = $this->context->link->getAdminLink('AdminErliIntegration', true)
            . '&view=' . urlencode($redirectView);

        if ($conf !== null) {
            $url .= '&conf=' . (int) $conf;
        }

        Tools::redirectAdmin($url);
    }

    /**
     * ZAPIS MAPOWANIA:
     * - zapis do ps_erli_category_map
     * - działa niezależnie od tego czy masz UNIQUE na id_category (robimy update/insert)
     */
    private function saveCategoryMapping()
    {
        $data = Tools::getValue('category');
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $idCategory => $row) {
            $idCategory = (int) $idCategory;
            if ($idCategory <= 0 || !is_array($row)) {
                continue;
            }

            $erliId   = trim((string) ($row['erli_category_id'] ?? ''));
            $erliName = trim((string) ($row['erli_category_name'] ?? ''));

            // Bez ID ERLI: usuń mapowanie (jeśli istnieje)
            if ($erliId === '') {
                Db::getInstance()->delete('erli_category_map', 'id_category=' . (int)$idCategory);
                continue;
            }

            // Sprawdź czy rekord istnieje
            $exists = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'erli_category_map` WHERE id_category=' . (int)$idCategory
            );

            if ($exists > 0) {
                // UPDATE
                Db::getInstance()->update(
                    'erli_category_map',
                    [
                        'erli_category_id' => pSQL($erliId),
                        'erli_category_name' => pSQL($erliName),
                    ],
                    'id_category=' . (int)$idCategory
                );
            } else {
                // INSERT
                Db::getInstance()->insert(
                    'erli_category_map',
                    [
                        'id_category' => (int)$idCategory,
                        'erli_category_id' => pSQL($erliId),
                        'erli_category_name' => pSQL($erliName),
                    ]
                );
            }
        }
    }
    
    private function saveShippingMapping()
    {
        $data = Tools::getValue('shipping'); // shipping[id_carrier] = erli_tag
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $idCarrier => $erliTag) {
            $idCarrier = (int)$idCarrier;
            $erliTag   = trim((string)$erliTag);

            if ($idCarrier <= 0) {
                continue;
            }

            if ($erliTag === '') {
                // usuń mapowanie, jeśli było
                Db::getInstance()->delete(
                    'erli_shipping_map',
                    'id_carrier = ' . (int)$idCarrier
                );
                continue;
            }

            $exists = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*)
                FROM `' . _DB_PREFIX_ . 'erli_shipping_map`
                WHERE id_carrier = ' . (int)$idCarrier
            );

            $row = [
                'id_carrier' => (int)$idCarrier,
                'erli_tag'   => pSQL($erliTag),
                'erli_name'  => pSQL($erliTag), // na razie nazwa = tag, bo API zwraca same stringi
            ];

            if ($exists > 0) {
                Db::getInstance()->update('erli_shipping_map', $row, 'id_carrier = ' . (int)$idCarrier);
            } else {
                Db::getInstance()->insert('erli_shipping_map', $row);
            }
        }
    }


    /// KATEGORIE ERLI: POBIERZ I ZAPISZ DO BAZY ///
    private function fetchErliCategoriesAndStoreAll($pageSize = 200)
    {
        $apiKey = (string) Configuration::get('ERLI_API_KEY');
        if (trim($apiKey) === '') {
            throw new Exception('Brak ustawionego ERLI_API_KEY.');
        }

        $client = new ErliApiClient($apiKey);

        $after = null;
        $pages = 0;
        $fetched = 0;

        while (true) {
            $payload = ['limit' => (int) $pageSize];
            if ($after !== null && (int)$after > 0) {
                $payload['after'] = (int) $after;
            }

            $resp = $client->post('/dictionaries/category/_search', $payload);
            $code = (int) ($resp['code'] ?? 0);

            if ($code === 429) {
                sleep(2);
                continue;
            }

            if ($code < 200 || $code >= 300) {
                $raw = (string) ($resp['raw'] ?? '');
                throw new Exception('Błąd pobierania kategorii ERLI. HTTP ' . $code . ' ' . $raw);
            }

            $body = $resp['body'] ?? null;
            if (!is_array($body) || empty($body)) {
                break;
            }

            $pages++;

            foreach ($body as $c) {
                if (!is_array($c) || !isset($c['id'])) {
                    continue;
                }

                $id = (int) $c['id'];
                $name = (string) ($c['name'] ?? '');
                $leaf = !empty($c['leaf']) ? 1 : 0;

                $crumb = '';
                if (!empty($c['breadcrumb']) && is_array($c['breadcrumb'])) {
                    $parts = [];
                    foreach ($c['breadcrumb'] as $b) {
                        if (is_array($b) && isset($b['name'])) {
                            $parts[] = (string)$b['name'];
                        }
                    }
                    $crumb = implode(' > ', $parts);
                }

                $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'erli_category_dictionary`
                    (`erli_id`, `name`, `leaf`, `breadcrumb_json`)
                VALUES
                    (' . (int)$id . ', "' . pSQL($name) . '", ' . (int)$leaf . ', "' . pSQL($crumb) . '")
                ON DUPLICATE KEY UPDATE
                    `name`=VALUES(`name`),
                    `leaf`=VALUES(`leaf`),
                    `breadcrumb_json`=VALUES(`breadcrumb_json`)';

                Db::getInstance()->execute($sql);
                $fetched++;
            }

            $last = end($body);
            $lastId = (is_array($last) && isset($last['id'])) ? (int) $last['id'] : null;
            if (!$lastId) {
                break;
            }

            $after = $lastId;

            if (count($body) < (int) $pageSize) {
                break;
            }
        }

        return ['pages' => $pages, 'fetched' => $fetched];
    }
    // Pobieranie dostaw erli 
    private function getErliPriceLists()
    {
        $apiKey = (string) Configuration::get('ERLI_API_KEY');
        if (trim($apiKey) === '') {
            throw new Exception('Brak ustawionego ERLI_API_KEY.');
        }

        $useSandbox = (int) Configuration::get('ERLI_USE_SANDBOX') === 1;
        $client     = new ErliApiClient($apiKey, $useSandbox);

        // GET /delivery/priceLists -> ["string","string2",...]
        $resp = $client->get('/delivery/priceLists'); // musisz mieć w ErliApiClient metodę get()

        $code = (int) ($resp['code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            $raw = (string) ($resp['raw'] ?? '');
            throw new Exception('Błąd pobierania cenników dostaw ERLI. HTTP ' . $code . ' ' . $raw);
        }

        $body = $resp['body'] ?? null;
        if (!is_array($body)) {
            return [];
        }

        $tags = [];
        foreach ($body as $tag) {
            $tag = trim((string)$tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }


    // ===================== RENDERERS =====================

    protected function renderConfigure()
    {
        $module = Module::getInstanceByName('erliintegration');

        $formHtml = '';
        $base = $this->context->link->getAdminLink('AdminErliIntegration');

        if ($module && method_exists($module, 'renderForm')) {
            $formHtml = $module->renderForm([
                'controller'   => 'AdminErliIntegration',
                'token'        => Tools::getAdminTokenLite('AdminErliIntegration'),
                'currentIndex' => $base . '&view=configure',
            ]);
        }

        $cronToken = (string) Configuration::get('ERLI_CRON_TOKEN');
        $cronUrl   = $this->context->link->getModuleLink('erliintegration', 'cron', ['token' => $cronToken]);

        $this->context->smarty->assign([
            'form_html' => $formHtml,
            'cron_url'  => $cronUrl,
        ]);
    }

    protected function renderDashboard()
    {
        $logRepo = new LogRepository();

        $totalProducts  = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`');
        $syncedProducts = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'erli_product_link`');

        $totalOrders = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`');
        $erliOrders  = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'erli_order_link`');

        $lastSync = Db::getInstance()->getValue(
            'SELECT MAX(`last_synced_at`) FROM `' . _DB_PREFIX_ . 'erli_product_link`'
        );

        $this->context->smarty->assign([
            'total_products'  => $totalProducts,
            'synced_products' => $syncedProducts,
            'total_orders'    => $totalOrders,
            'erli_orders'     => $erliOrders,
            'last_sync'       => $lastSync,
            'last_logs'       => $logRepo->getLastLogs(20),
        ]);
    }

    protected function renderProducts()
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;

        $rows = Db::getInstance()->executeS(
            'SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                sa.quantity,
                i.id_image,
                epl.external_id,
                epl.last_synced_at,
                epl.last_error
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . ' AND pl.id_shop = ' . (int) $idShop . ')
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON (sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . (int) $idShop . ')
             LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` ish
                ON (ish.id_product = p.id_product AND ish.cover = 1 AND ish.id_shop = ' . (int) $idShop . ')
             LEFT JOIN `' . _DB_PREFIX_ . 'image` i
                ON (i.id_image = ish.id_image)
             LEFT JOIN `' . _DB_PREFIX_ . 'erli_product_link` epl
                ON (epl.id_product = p.id_product)
             ORDER BY p.id_product DESC
             LIMIT 200'
        );

        $products = [];
        foreach ($rows ?: [] as $r) {
            $imgUrl = '';
            if (!empty($r['id_image'])) {
                $imgUrl = $this->context->link->getImageLink(
                    (string) $r['link_rewrite'],
                    (int) $r['id_image'],
                    ImageType::getFormattedName('small_default')
                );
            }

            $priceGross = (float) Product::getPriceStatic((int) $r['id_product'], true);

            $products[] = [
                'id_product'     => (int) $r['id_product'],
                'name'           => (string) $r['name'],
                'price'          => $priceGross,
                'quantity'       => (int) ($r['quantity'] ?? 0),
                'image'          => $imgUrl,
                'external_id'    => (string) ($r['external_id'] ?? ''),
                'last_synced_at' => (string) ($r['last_synced_at'] ?? ''),
                'last_error'     => (string) ($r['last_error'] ?? ''),
            ];
        }

        $this->context->smarty->assign([
            'products' => $products,
        ]);
    }

    protected function renderOrders()
    {
        $adminOrdersToken = Tools::getAdminTokenLite('AdminOrders');

        $orders = Db::getInstance()->executeS(
            'SELECT
                eol.id_order,
                eol.erli_order_id,
                eol.last_status,
                eol.created_at
             FROM `' . _DB_PREFIX_ . 'erli_order_link` eol
             ORDER BY eol.id_erli_order_link DESC
             LIMIT 200'
        );

        $this->context->smarty->assign([
            'orders' => $orders ?: [],
            'admin_orders_token' => $adminOrdersToken,
        ]);
    }

    /**
     * RENDER MAPOWANIA:
     * - ładuje wszystkie kategorie Prestashop (id_category > 1)
     * - dołącza zapisane mapowania z ps_erli_category_map
     * - buduje category_rows dla mapping.tpl
     */
    protected function renderMapping()
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;

        $categories = Db::getInstance()->executeS(
            'SELECT c.id_category, cl.name
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
               ON (cl.id_category = c.id_category AND cl.id_lang=' . (int)$idLang . ' AND cl.id_shop=' . (int)$idShop . ')
             WHERE c.id_category > 1
             ORDER BY c.id_category ASC'
        );

        $mapped = Db::getInstance()->executeS(
            'SELECT id_category, erli_category_id, erli_category_name
             FROM `' . _DB_PREFIX_ . 'erli_category_map`'
        );

        $map = [];
        foreach ($mapped ?: [] as $m) {
            $map[(int)$m['id_category']] = [
                'erli_category_id' => (string)$m['erli_category_id'],
                'erli_category_name' => (string)($m['erli_category_name'] ?? ''),
            ];
        }

        $rows = [];
        foreach ($categories ?: [] as $c) {
            $idCategory = (int)$c['id_category'];

            $rows[] = [
                'id_category' => $idCategory,
                'category_name' => (string)$c['name'],
                'erli_category_id' => isset($map[$idCategory]) ? $map[$idCategory]['erli_category_id'] : '',
                'erli_category_name' => isset($map[$idCategory]) ? $map[$idCategory]['erli_category_name'] : '',
            ];
        }
        // ------- DOSTAWY: przewoźnicy + mapowanie z DB -------
        $carriers = Carrier::getCarriers(
            $idLang,
            false,
            false,
            false,
            null,
            Carrier::ALL_CARRIERS
        );

        $shippingRows = Db::getInstance()->executeS(
            'SELECT id_carrier, erli_tag, erli_name
            FROM `' . _DB_PREFIX_ . 'erli_shipping_map`'
        ) ?: [];

        $shippingMap = [];
        foreach ($shippingRows as $r) {
            $shippingMap[(int)$r['id_carrier']] = [
                'erli_tag'  => (string)$r['erli_tag'],
                'erli_name' => (string)($r['erli_name'] ?? ''),
            ];
        }

        
        $priceLists = [];
        try {
            $priceLists = $this->getErliPriceLists(); 
        } catch (Throwable $e) {
            
        }

        $this->context->smarty->assign([
            'category_rows'     => $rows,          
            'carriers'          => $carriers,      
            'shipping_map'      => $shippingMap,   
            'erli_price_lists'  => $priceLists,    
        ]);

    }

    protected function renderLogs()
    {
        $logRepo = new LogRepository();
        $this->context->smarty->assign([
            'logs' => $logRepo->getLastLogs(200),
        ]);
    }
}
