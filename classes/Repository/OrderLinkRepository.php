<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OrderLinkRepository
{
    /**
     * Znajdź powiązanie po ID zamówienia z Erli.
     *
     * @param string $erliOrderId
     * @return array|false
     */
    public function findByErliOrderId($erliOrderId)
    {
        $erliOrderId = trim((string) $erliOrderId);

        if ($erliOrderId === '') {
            return false;
        }

        $sql = new DbQuery();
        $sql->select('*')
            ->from('erli_order_link')
            ->where("`erli_order_id` = '" . pSQL($erliOrderId) . "'");

        return Db::getInstance()->getRow($sql);
    }

    /**
     * Znajdź powiązanie po ID zamówienia w PrestaShop.
     *
     * @param int $idOrder
     * @return array|false
     */
    public function findByPrestashopOrderId($idOrder)
    {
        $idOrder = (int) $idOrder;

        if ($idOrder <= 0) {
            return false;
        }

        $sql = new DbQuery();
        $sql->select('*')
            ->from('erli_order_link')
            ->where('`id_order` = ' . $idOrder);

        return Db::getInstance()->getRow($sql);
    }

    /**
     * Zapisuje (insert / update) powiązanie zamówienia.
     *
     * @param int    $idOrder
     * @param string $erliOrderId
     * @param string $status
     *
     * @return bool
     */
    public function save($idOrder, $erliOrderId, $status)
    {
        $idOrder    = (int) $idOrder;
        $erliOrderId = trim((string) $erliOrderId);

        if ($idOrder <= 0 || $erliOrderId === '') {
            return false;
        }

        $existing = $this->findByErliOrderId($erliOrderId);

        $data = [
            'id_order'      => $idOrder,
            'erli_order_id' => pSQL($erliOrderId),
            'last_status'   => pSQL((string) $status),
        ];

        if ($existing) {
            return (bool) Db::getInstance()->update(
                'erli_order_link',
                $data,
                'id_erli_order_link = ' . (int) $existing['id_erli_order_link']
            );
        }

        $data['created_at'] = date('Y-m-d H:i:s');

        return (bool) Db::getInstance()->insert('erli_order_link', $data);
    }
}
