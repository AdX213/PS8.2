<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class ErliintegrationCronModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        parent::initContent();

        // Walidacja tokenu z konfiguracji modułu
        $token = Tools::getValue('token');
        $expected = (string) Configuration::get('ERLI_CRON_TOKEN');

        if (!$expected || $token !== $expected) {
            header('HTTP/1.1 403 Forbidden');
            die('Bad token');
        }

        // Wczytujemy klasy synchronizacji
        require_once _PS_MODULE_DIR_.'erliintegration/classes/Sync/ProductSync.php';
        require_once _PS_MODULE_DIR_.'erliintegration/classes/Sync/OrderSync.php';

         // 1) pobierz nowe zamówienia
        $orderSync = new OrderSync();
        $orderSync->processInbox();

        // 2) wyślij pending produkty
        $productSync = new ProductSync();
        $syncedProducts = $productSync->syncAllPending(20);

       

        // Prosty output dla CRONa / przeglądarki
        header('Content-Type: text/plain; charset=utf-8');
        echo 'OK - syncedProducts='.$syncedProducts.PHP_EOL;
        exit;
    }
}
