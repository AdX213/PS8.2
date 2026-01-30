<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class ErliIntegration extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'erliintegration';
        $this->tab = 'administration';
        $this->version = '2.2';
        $this->author = 'Adrian';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Integracja Erli.pl');
        $this->description = $this->l('Integracja PrestaShop z Erli.pl (produkty + zamówienia + CRON).');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->installSql()) {
            return false;
        }

        if (!$this->registerAdminTab()) {
            return false;
        }

        if (
            !$this->registerHook('actionProductSave') ||
            !$this->registerHook('actionUpdateQuantity') ||
            !$this->registerHook('displayBackOfficeHeader')
        ) {
            return false;
        }

        Configuration::updateValue('ERLI_API_KEY', '');
        Configuration::updateValue('ERLI_CRON_TOKEN', Tools::passwdGen(32));

        Configuration::updateValue('ERLI_USE_SANDBOX', 0);

        Configuration::updateValue('ERLI_DEFAULT_CARRIER', 0);
        Configuration::updateValue('ERLI_DEFAULT_ORDER_STATE', (int) Configuration::get('PS_OS_PAYMENT'));

        Configuration::updateValue('ERLI_STATE_PENDING', (int) Configuration::get('PS_OS_BANKWIRE'));
        Configuration::updateValue('ERLI_STATE_PAID', (int) Configuration::get('PS_OS_PAYMENT'));
        Configuration::updateValue('ERLI_STATE_CANCELLED', (int) Configuration::get('PS_OS_CANCELED'));

        return true;
    }

    public function uninstall()
    {
        $this->removeAdminTab();

        if (!$this->uninstallSql()) {
            return false;
        }

        Configuration::deleteByName('ERLI_API_KEY');
        Configuration::deleteByName('ERLI_CRON_TOKEN');
        Configuration::deleteByName('ERLI_USE_SANDBOX');

        Configuration::deleteByName('ERLI_DEFAULT_CARRIER');
        Configuration::deleteByName('ERLI_DEFAULT_ORDER_STATE');
        Configuration::deleteByName('ERLI_STATE_PENDING');
        Configuration::deleteByName('ERLI_STATE_PAID');
        Configuration::deleteByName('ERLI_STATE_CANCELLED');

        return parent::uninstall();
    }

    protected function installSql()
    {
        $sqlFile = dirname(__FILE__) . '/Sql/install.sql';
        return $this->executeSqlFile($sqlFile);
    }

    protected function uninstallSql()
    {
        $sqlFile = dirname(__FILE__) . '/Sql/uninstall.sql';
        return $this->executeSqlFile($sqlFile);
    }

    protected function executeSqlFile($file)
    {
        if (!file_exists($file)) {
            return true;
        }

        $sqlContent = file_get_contents($file);
        $sqlContent = str_replace(['PREFIX_'], [_DB_PREFIX_], $sqlContent);
        $queries = preg_split("/;\s*[\r\n]+/", $sqlContent);

        foreach ($queries as $query) {
            $query = trim($query);
            if ($query !== '') {
                if (!Db::getInstance()->execute($query)) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function registerAdminTab()
    {
        // Parent sekcja ERLI na dole lewego menu
        $parentClass = 'AdminErli';
        $parentId = (int) Tab::getIdFromClassName($parentClass);

        if (!$parentId) {
            $parent = new Tab();
            $parent->active = 1;
            $parent->class_name = $parentClass;
            $parent->module = $this->name;
            $parent->id_parent = 0;

            foreach (Language::getLanguages(false) as $lang) {
                $parent->name[(int)$lang['id_lang']] = 'ERLI';
            }

            if (!$parent->add()) {
                return false;
            }

            $parentId = (int) Tab::getIdFromClassName($parentClass);
        }

        // Child: Integracja
        $childClass = 'AdminErliIntegration';
        $childId = (int) Tab::getIdFromClassName($childClass);
        if ($childId) {
            return true;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $childClass;
        $tab->module = $this->name;
        $tab->id_parent = (int) $parentId;

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int)$lang['id_lang']] = 'Integracja';
        }

        return (bool) $tab->add();
    }

    protected function removeAdminTab()
    {
        $childId = (int) Tab::getIdFromClassName('AdminErliIntegration');
        if ($childId) {
            (new Tab($childId))->delete();
        }

        $parentId = (int) Tab::getIdFromClassName('AdminErli');
        if ($parentId) {
            (new Tab($parentId))->delete();
        }

        return true;
    }

    /**
     * Standardowa konfiguracja modułu (Moduły -> Konfiguruj)
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitErliIntegration')) {
            $apiKey          = (string) Tools::getValue('ERLI_API_KEY');
            $cronToken       = (string) Tools::getValue('ERLI_CRON_TOKEN');
            $useSandbox      = (int) Tools::getValue('ERLI_USE_SANDBOX');

            $defaultCarrier  = (int) Tools::getValue('ERLI_DEFAULT_CARRIER');
            $defaultOrderSt  = (int) Tools::getValue('ERLI_DEFAULT_ORDER_STATE');
            $statePending    = (int) Tools::getValue('ERLI_STATE_PENDING');
            $statePaid       = (int) Tools::getValue('ERLI_STATE_PAID');
            $stateCancelled  = (int) Tools::getValue('ERLI_STATE_CANCELLED');

            Configuration::updateValue('ERLI_API_KEY', trim($apiKey));
            Configuration::updateValue('ERLI_CRON_TOKEN', $cronToken ?: Tools::passwdGen(32));
            Configuration::updateValue('ERLI_USE_SANDBOX', $useSandbox ? 1 : 0);

            Configuration::updateValue('ERLI_DEFAULT_CARRIER', $defaultCarrier);
            Configuration::updateValue('ERLI_DEFAULT_ORDER_STATE', $defaultOrderSt);
            Configuration::updateValue('ERLI_STATE_PENDING', $statePending);
            Configuration::updateValue('ERLI_STATE_PAID', $statePaid);
            Configuration::updateValue('ERLI_STATE_CANCELLED', $stateCancelled);

            $output .= $this->displayConfirmation($this->l('Ustawienia zapisane.'));
        }

        if (Tools::isSubmit('submitErliTestConnection')) {
            $output .= $this->testConnectionHtml();
        }

        $output .= $this->renderForm(); // domyślny AdminModules

        $linkToPanel = $this->context->link->getAdminLink('AdminErliIntegration');

        $output .= '
        <div class="panel">
            <h3><i class="icon-refresh"></i> ' . $this->l('Panel integracji Erli.pl') . '</h3>
            <p>' . $this->l('Przejdź do panelu integracji, aby zobaczyć narzędzia synchronizacji i logi.') . '</p>
            <a href="' . htmlspecialchars($linkToPanel, ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary">
                <i class="icon-external-link"></i> ' . $this->l('Otwórz panel Erli') . '
            </a>
        </div>';

        $cronToken = (string) Configuration::get('ERLI_CRON_TOKEN');
        $cronUrl   = $this->context->link->getModuleLink('erliintegration', 'cron', ['token' => $cronToken]);

        $output .= '
        <div class="panel">
            <h3><i class="icon-clock-o"></i> ' . $this->l('CRON – automatyczna synchronizacja') . '</h3>
            <p>' . $this->l('Wywołuj cyklicznie ten URL:') . '</p>
            <pre style="user-select:all;">' . htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8') . '</pre>
            <p>' . $this->l('Przykład (co 5 minut, Linux cron):') . '</p>
            <pre>*/5 * * * * curl -s "' . htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8') . '" >/dev/null 2>&1</pre>
        </div>';

        return $output;
    }

    /**
     * Renderuje formularz konfiguracji.
     * Poprawka: pozwala wyrenderować formę również w AdminErliIntegrationController
     * (sidebar ERLI -> Integracja).
     *
     * @param array $opts ['controller' => 'AdminModules'|'AdminErliIntegration', 'token'=>..., 'currentIndex'=>...]
     */
    public function renderForm(array $opts = [])
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $carriers = Carrier::getCarriers(
            $defaultLang,
            true,
            false,
            false,
            null,
            Carrier::ALL_CARRIERS
        );
        $orderStates = OrderState::getOrderStates($defaultLang);

        $isSandbox = (int) Configuration::get('ERLI_USE_SANDBOX') ? 1 : 0;
        $endpoint = $isSandbox ? 'https://sandbox.erli.dev/svc/shop-api' : 'https://erli.pl/svc/shop-api';

        $fieldsForm[0]['form'] = [
            'legend' => [
                'title' => $this->l('Ustawienia Erli'),
            ],
            'input'  => [
                [
                    'type'     => 'text',
                    'label'    => $this->l('API key'),
                    'name'     => 'ERLI_API_KEY',
                    'size'     => 80,
                    'required' => true,
                    'desc'     => $this->l('Klucz API wygenerowany w panelu Erli.'),
                ],
                [
                    'type'    => 'switch',
                    'label'   => $this->l('Tryb Sandbox'),
                    'name'    => 'ERLI_USE_SANDBOX',
                    'is_bool' => true,
                    'values'  => [
                        ['id' => 'erli_sandbox_on',  'value' => 1, 'label' => $this->l('Włączony')],
                        ['id' => 'erli_sandbox_off', 'value' => 0, 'label' => $this->l('Wyłączony')],
                    ],
                    'desc' => $this->l('Aktywny endpoint: ') . $endpoint,
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('CRON token'),
                    'name'     => 'ERLI_CRON_TOKEN',
                    'size'     => 64,
                    'required' => true,
                    'desc'     => $this->l('Losowy token zabezpieczający URL CRON.'),
                ],
                [
                    'type'   => 'select',
                    'label'  => $this->l('Domyślny przewoźnik dla zamówień z Erli'),
                    'name'   => 'ERLI_DEFAULT_CARRIER',
                    'options'=> [
                        'query' => $carriers,
                        'id'    => 'id_carrier',
                        'name'  => 'name',
                    ],
                ],
                [
                    'type'   => 'select',
                    'label'  => $this->l('Domyślny status zamówienia z Erli'),
                    'name'   => 'ERLI_DEFAULT_ORDER_STATE',
                    'options'=> [
                        'query' => $orderStates,
                        'id'    => 'id_order_state',
                        'name'  => 'name',
                    ],
                ],
                [
                    'type'   => 'select',
                    'label'  => $this->l('Status dla Erli: pending'),
                    'name'   => 'ERLI_STATE_PENDING',
                    'options'=> [
                        'query' => $orderStates,
                        'id'    => 'id_order_state',
                        'name'  => 'name',
                    ],
                ],
                [
                    'type'   => 'select',
                    'label'  => $this->l('Status dla Erli: purchased'),
                    'name'   => 'ERLI_STATE_PAID',
                    'options'=> [
                        'query' => $orderStates,
                        'id'    => 'id_order_state',
                        'name'  => 'name',
                    ],
                ],
                [
                    'type'   => 'select',
                    'label'  => $this->l('Status dla Erli: cancelled'),
                    'name'   => 'ERLI_STATE_CANCELLED',
                    'options'=> [
                        'query' => $orderStates,
                        'id'    => 'id_order_state',
                        'name'  => 'name',
                    ],
                ],
            ],
            'submit' => [
                'title' => $this->l('Zapisz'),
                'class' => 'btn btn-default pull-right',
            ],
            'buttons' => [
                [
                    'title' => $this->l('Test połączenia'),
                    'icon'  => 'process-icon-refresh',
                    'type'  => 'submit',
                    'name'  => 'submitErliTestConnection',
                    'class' => 'btn btn-primary',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module                     = $this;
        $helper->name_controller            = $this->name;

        // ważne: token/currentIndex zależnie skąd renderujemy
        $controller = !empty($opts['controller']) ? (string)$opts['controller'] : 'AdminModules';

        $helper->token = !empty($opts['token'])
            ? (string)$opts['token']
            : Tools::getAdminTokenLite($controller);

        $helper->currentIndex = !empty($opts['currentIndex'])
            ? (string)$opts['currentIndex']
            : (AdminController::$currentIndex . '&configure=' . $this->name);

        $helper->default_form_language      = $defaultLang;
        $helper->allow_employee_form_lang   = $defaultLang;
        $helper->title                      = $this->displayName;
        $helper->show_toolbar               = false;
        $helper->toolbar_scroll             = false;
        $helper->submit_action              = 'submitErliIntegration';

        $helper->fields_value['ERLI_API_KEY']             = (string) Configuration::get('ERLI_API_KEY');
        $helper->fields_value['ERLI_USE_SANDBOX']         = (int) Configuration::get('ERLI_USE_SANDBOX');
        $helper->fields_value['ERLI_CRON_TOKEN']          = (string) Configuration::get('ERLI_CRON_TOKEN');

        $helper->fields_value['ERLI_DEFAULT_CARRIER']     = (int) Configuration::get('ERLI_DEFAULT_CARRIER');
        $helper->fields_value['ERLI_DEFAULT_ORDER_STATE'] = (int) Configuration::get('ERLI_DEFAULT_ORDER_STATE');
        $helper->fields_value['ERLI_STATE_PENDING']       = (int) Configuration::get('ERLI_STATE_PENDING');
        $helper->fields_value['ERLI_STATE_PAID']          = (int) Configuration::get('ERLI_STATE_PAID');
        $helper->fields_value['ERLI_STATE_CANCELLED']     = (int) Configuration::get('ERLI_STATE_CANCELLED');

        return $helper->generateForm($fieldsForm);
    }

    /**
     * HTML wynik testu do getContent() (Moduły -> Konfiguruj)
     */
    protected function testConnectionHtml()
    {
        try {
            $apiKey = (string) Configuration::get('ERLI_API_KEY');
            if (trim($apiKey) === '') {
                throw new Exception($this->l('Brak ustawionego API key.'));
            }

            require_once __DIR__ . '/classes/Api/ErliApiClient.php';
            $client = new ErliApiClient($apiKey);

            $response = $client->get('inbox', ['limit' => 1]);

            if ((int)$response['code'] >= 200 && (int)$response['code'] < 300) {
                $mode = (int) Configuration::get('ERLI_USE_SANDBOX') ? 'SANDBOX' : 'PROD';
                return $this->displayConfirmation(
                    $this->l('Połączenie z Erli działa. Tryb: ') . $mode . ' | HTTP: ' . (int)$response['code']
                );
            }

            return $this->displayError(
                $this->l('Błąd połączenia z Erli. Kod HTTP: ') . (int)$response['code'] .
                ' | RAW: ' . htmlspecialchars((string)$response['raw'])
            );
        } catch (Exception $e) {
            return $this->displayError($e->getMessage());
        }
    }

    /**
     * INLINE przyciski dla PS9 (Sell).
     * AJAX (bez przekierowań do panelu modułu).
     * js nie działał w views/js 
     */
    public function hookDisplayBackOfficeHeader($params)
    {
        $base = $this->context->link->getAdminLink('AdminErliIntegration', true);

        $syncAllProductsUrl = $base . '&erli_action=sync_all_products';
        $syncOneProductBase = $base . '&erli_action=sync_product&id_product=';
        $importOrdersUrl    = $base . '&erli_action=import_orders';

        $js = '
        (function(){
          try {
            window.ERLI_SYNC_ALL_PRODUCTS_URL = ' . $this->jsonForJs($syncAllProductsUrl) . ';
            window.ERLI_SYNC_ONE_PRODUCT_BASE = ' . $this->jsonForJs($syncOneProductBase) . ';
            window.ERLI_IMPORT_ORDERS_URL     = ' . $this->jsonForJs($importOrdersUrl) . ';

            function path(){ return (location.pathname || "").toLowerCase(); }
            function isProductsPage(){ return path().indexOf("/sell/catalog/products") !== -1; }
            function isOrdersPage(){ return path().indexOf("/sell/orders") !== -1; }

            if (window.__ERLI_INLINE_BOOT__) return;
            window.__ERLI_INLINE_BOOT__ = true;
        
            function ajaxify(url){ 
            try {
        
                var u = new URL(url, window.location.origin);

                
                var path = u.pathname + (u.search || "");

                if (path.indexOf("?") === -1) {
                path += "?ajax=1";
                } else {
                path += "&ajax=1";
                }

                return path;
            } catch (e) {
            
                if (url.indexOf("?") === -1) {
                return url + "?ajax=1";
                }
                return url + "&ajax=1";
                }
            }

            function callErli(url, btn){
              if (btn) {
                btn.dataset.oldText = btn.textContent || "";
                btn.textContent = "…";
                btn.style.pointerEvents = "none";
                btn.style.opacity = "0.7";
              }

              return fetch(ajaxify(url), { credentials: "same-origin" })
                .then(function(r){ return r.json(); })
                .then(function(json){
                  alert((json && json.message) ? json.message : "OK");
                  return json;
                })
                .catch(function(err){
                  alert("Błąd ERLI (AJAX): " + err);
                  throw err;
                })
                .finally(function(){
                  if (btn) {
                    btn.textContent = btn.dataset.oldText || "ERLI";
                    btn.style.pointerEvents = "";
                    btn.style.opacity = "";
                  }
                });
            }

            function injectProductsTopButton(){
              var addBtn = document.querySelector("a.btn.new-product-button");
              if (!addBtn) return false;

              if (!document.getElementById("erli-sync-products-btn")) {
                var erliBtn = document.createElement("a");
                erliBtn.id = "erli-sync-products-btn";
                erliBtn.className = "btn btn-outline-primary";
                erliBtn.style.marginLeft = "8px";
                erliBtn.textContent = "Wyślij produkty do ERLI";
                erliBtn.href = "javascript:void(0)";
                erliBtn.addEventListener("click", function(e){
                  e.preventDefault();
                  callErli(window.ERLI_SYNC_ALL_PRODUCTS_URL, erliBtn);
                });
                addBtn.parentElement.appendChild(erliBtn);
              }
              return true;
            }

            function injectProductRowButtons(){
              if (!window.ERLI_SYNC_ONE_PRODUCT_BASE) return;

              var tbody = document.querySelector("table tbody");
              if (!tbody) return;

              var rows = tbody.querySelectorAll("tr");
              if (!rows || !rows.length) return;

              rows.forEach(function(tr){
                if (tr.querySelector(".erli-row-btn")) return;

                var idProduct = null;

                var cb = tr.querySelector("input[type=\\"checkbox\\"][value]");
                if (cb && cb.value && /^\\d+$/.test(cb.value)) {
                  idProduct = cb.value;
                }

                if (!idProduct) {
                  var firstTd = tr.querySelector("td");
                  if (firstTd) {
                    var t = (firstTd.textContent || "").trim();
                    if (/^\\d+$/.test(t)) idProduct = t;
                  }
                }

                if (!idProduct) return;

                var tds = tr.querySelectorAll("td");
                if (!tds || !tds.length) return;

                var actionsTd = tds[tds.length - 1];
                if (!actionsTd) return;

                var a = document.createElement("a");
                a.className = "btn btn-sm btn-outline-secondary erli-row-btn";
                a.textContent = "ERLI";
                a.title = "Wyślij produkt do ERLI";
                a.style.marginRight = "6px";
                a.href = "javascript:void(0)";
                a.addEventListener("click", function(e){
                  e.preventDefault();
                  callErli(window.ERLI_SYNC_ONE_PRODUCT_BASE + encodeURIComponent(String(idProduct)), a);
                });

                actionsTd.insertBefore(a, actionsTd.firstChild);
              });
            }

            function injectOrdersTopButton(){
              if (!window.ERLI_IMPORT_ORDERS_URL) return false;
              if (document.getElementById("erli-import-orders-btn")) return true;

              var addOrderBtn = null;
              var links = document.querySelectorAll("a.btn,button.btn");

              for (var i=0; i<links.length; i++){
                var t = (links[i].getAttribute("title") || "").trim();
                var txt = (links[i].textContent || "").trim();
                if (t === "Dodaj nowe zamówienie" || txt === "Dodaj nowe zamówienie") {
                  addOrderBtn = links[i];
                  break;
                }
              }

              if (!addOrderBtn) return false;
              if (!addOrderBtn.parentElement) return false;

              var btn = document.createElement("a");
              btn.id = "erli-import-orders-btn";
              btn.className = "btn btn-outline-primary";
              btn.style.marginLeft = "8px";
              btn.textContent = "Pobierz zamówienia z ERLI";
              btn.href = "javascript:void(0)";
              btn.addEventListener("click", function(e){
                e.preventDefault();
                callErli(window.ERLI_IMPORT_ORDERS_URL, btn);
              });

              addOrderBtn.parentElement.insertBefore(btn, addOrderBtn.nextSibling);
              return true;
            }

            function tick(){
              if (isProductsPage()) {
                injectProductsTopButton();
                injectProductRowButtons();
              } else if (isOrdersPage()) {
                injectOrdersTopButton();
              }
            }

            if (document.readyState === "loading") {
              document.addEventListener("DOMContentLoaded", tick);
            } else {
              tick();
            }

            var target = document.body || document.documentElement;
            if (target) {
            var mo = new MutationObserver(tick);
            mo.observe(target, { childList:true, subtree:true });
            }

          } catch(e) {
            console && console.warn && console.warn("[ERLI] inline error", e);
          }
        })();
        ';

        $css = '.erli-row-btn{min-width:52px;}';

        return '<style>' . $css . '</style><script>' . $js . '</script>';
    }

    private function jsonForJs($value)
    {
        return json_encode((string)$value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function hookActionProductSave($params)
    {
        if (empty($params['id_product'])) {
            return;
        }

        $apiKey = (string) Configuration::get('ERLI_API_KEY');
        if (trim($apiKey) === '') {
            return;
        }

        $idProduct = (int) $params['id_product'];

        $this->markProductPending($idProduct, null);
    }

    public function hookActionUpdateQuantity($params)
    {
        if (empty($params['id_product'])) {
            return;
        }

        $apiKey = (string) Configuration::get('ERLI_API_KEY');
        if (trim($apiKey) === '') {
            return;
        }

        $idProduct = (int) $params['id_product'];
        $idAttr = !empty($params['id_product_attribute']) ? (int)$params['id_product_attribute'] : null;

        $this->markProductPending($idProduct, $idAttr);
    }

    private function markProductPending($idProduct, $idProductAttribute = null)
    {
        $idProduct = (int) $idProduct;

        // jeśli produkt ma kombinacje i nie mamy id attr -> oznacz wszystkie warianty
        if ($idProductAttribute === null) {
            $combRows = Product::getProductAttributesIds($idProduct);
            if (!empty($combRows) && is_array($combRows)) {
                foreach ($combRows as $r) {
                    $idPa = (int) ($r['id_product_attribute'] ?? 0);
                    if ($idPa > 0) {
                        $this->markProductPending($idProduct, $idPa);
                    }
                }
                return;
            }
        }

        $idAttr = ($idProductAttribute !== null) ? (int)$idProductAttribute : null;

        // poprawny external_id jak w ProductSync
        $externalId = ($idAttr !== null && $idAttr > 0)
            ? 'ps-' . $idProduct . '-' . $idAttr
            : 'ps-' . $idProduct;

        $where = 'id_product=' . (int)$idProduct;
        if ($idAttr !== null && $idAttr > 0) {
            $where .= ' AND id_product_attribute=' . (int)$idAttr;
        } else {
            $where .= ' AND (id_product_attribute IS NULL OR id_product_attribute=0)';
        }

        $exists = (int) Db::getInstance()->getValue(
            'SELECT id_erli_product_link FROM `' . _DB_PREFIX_ . 'erli_product_link` WHERE ' . $where
        );

        $data = [
            'id_product' => (int)$idProduct,
            'external_id' => pSQL($externalId),
            'last_synced_at' => null,
            'last_error' => null,
        ];

        $data['id_product_attribute'] = ($idAttr !== null && $idAttr > 0) ? (int)$idAttr : null;

        if ($exists) {
            Db::getInstance()->update('erli_product_link', $data, 'id_erli_product_link='.(int)$exists);
        } else {
            Db::getInstance()->insert('erli_product_link', $data);
        }
    }
}
