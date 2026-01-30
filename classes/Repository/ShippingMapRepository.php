<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class ShippingMapRepository
{
    public function findByCarrierId($idCarrier)
    {
        $sql = new DbQuery();
        $sql->select('*')
            ->from('erli_shipping_map')
            ->where('id_carrier = ' . (int) $idCarrier);

        return Db::getInstance()->getRow($sql);
    }

    /**
     * Zwraca wszystkie mapowania w formie:
     * [id_carrier => erli_tag, ...]
     */
    public function getAllAsArray()
    {
        $sql = new DbQuery();
        $sql->select('id_carrier, erli_tag')
            ->from('erli_shipping_map');

        $rows = Db::getInstance()->executeS($sql);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id_carrier']] = $row['erli_tag'];
        }

        return $result;
    }
}
