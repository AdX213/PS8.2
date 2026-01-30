<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class CategoryMapRepository
{
    public function findByCategoryId($idCategory)
    {
        $sql = new DbQuery();
        $sql->select('*')
            ->from('erli_category_map')
            ->where('id_category = ' . (int) $idCategory);

        return Db::getInstance()->getRow($sql);
    }
}
