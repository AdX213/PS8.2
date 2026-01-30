<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'erliintegration/classes/Repository/ShippingMapRepository.php';

class ShippingMapper
{
    /**
     * Zwraca tagi wysyłki ERLI dla produktu.
     * Minimalna poprawna logika: bierzemy tag dla ERLI_DEFAULT_CARRIER.
     *
     * @return string[]
     */
    public static function mapTagsForProduct(Product $product, $idLang)
    {
        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        $idCarrier = (int) Configuration::get('ERLI_DEFAULT_CARRIER');
        if ($idCarrier <= 0) {
            return [];
        }

        $repo = new ShippingMapRepository();
        $row  = $repo->findByCarrierId($idCarrier);

        if (!$row || empty($row['erli_tag'])) {
            return [];
        }

        $tag = trim((string) $row['erli_tag']);
        if ($tag === '') {
            return [];
        }

        return [$tag];
    }
}
