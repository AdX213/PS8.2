<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class ProductLinkRepository
{
    /**
     * Znajduje powiązanie po external_id (ID zewnętrznym wysłanym do ERLI).
     *
     * Kluczowe dla zamówień: ERLI w itemach zwraca externalProductId,
     * który u nas ma format np. "ps-123" lub "ps-123-456".
     */
    public function findByExternalId($externalId)
    {
        $externalId = trim((string) $externalId);
        if ($externalId === '') {
            return false;
        }

        $sql = new DbQuery();
        $sql->select('*')
            ->from('erli_product_link')
            ->where('`external_id` = "' . pSQL($externalId) . '"');

        return Db::getInstance()->getRow($sql);
    }

    public function findByProduct($idProduct, $idProductAttribute = null)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0) {
            return false;
        }

        $sql = new DbQuery();
        $sql->select('*')
            ->from('erli_product_link')
            ->where('`id_product` = ' . (int) $idProduct);

        if ($idProductAttribute !== null) {
            $sql->where('`id_product_attribute` = ' . (int) $idProductAttribute);
        } else {
            
            $sql->where('`id_product_attribute` IS NULL');
        }


        return Db::getInstance()->getRow($sql);
    }

    public function save($idProduct, $idCombination, $externalId, $payload)
    {
        $idProduct = (int) $idProduct;
        $externalId = trim((string) $externalId);

        if ($idProduct <= 0 || $externalId === '') {
            return false;
        }

        // 0 / '' / '0' traktujemy jako brak kombinacji => NULL
        $idCombination = ($idCombination === '' || $idCombination === 0 || $idCombination === '0')
            ? null
            : (int) $idCombination;

        $existing = $this->findByProduct($idProduct, $idCombination);

        if ($existing) {
            return (bool) Db::getInstance()->update(
                'erli_product_link',
                [
                    'external_id'    => pSQL($externalId),
                    'last_payload'   => pSQL((string) $payload, true),
                    'last_synced_at' => date('Y-m-d H:i:s'),
                    'last_error'     => null,
                ],
                'id_erli_product_link = ' . (int) $existing['id_erli_product_link']
            );
        }

        $data = [
            'id_product'     => (int) $idProduct,
            'external_id'    => pSQL($externalId),
            'last_payload'   => pSQL((string) $payload, true),
            'last_synced_at' => date('Y-m-d H:i:s'),
            'last_error'     => null,
        ];

        // NULL w insert (Db::insert poprawnie wstawi NULL)
        $data['id_product_attribute'] = ($idCombination !== null) ? (int) $idCombination : null;

        return (bool) Db::getInstance()->insert('erli_product_link', $data);
    }

    public function markError($idProduct, $message, $idProductAttribute = null)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0) {
            return false;
        }

        $where = '`id_product` = ' . (int) $idProduct;

        if ($idProductAttribute !== null) {
            $where .= ' AND `id_product_attribute` = ' . (int) $idProductAttribute;
        } else {
            $where .= ' AND `id_product_attribute` IS NULL';
        }

        return (bool) Db::getInstance()->update(
            'erli_product_link',
            ['last_error' => pSQL((string) $message, true)],
            $where
        );
    }
}
