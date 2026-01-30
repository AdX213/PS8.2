<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class ErliCategoryMap extends ObjectModel
{
    public $id_erli_category_map;
    public $id_category;
    public $erli_category_id;
    public $erli_category_name;

    public static $definition = [
        'table'   => 'erli_category_map',
        'primary' => 'id_erli_category_map',
        'fields'  => [
            'id_category'        => ['type' => self::TYPE_INT,    'required' => true],
            'erli_category_id'   => ['type' => self::TYPE_STRING, 'required' => true,  'size' => 64],
            'erli_category_name' => ['type' => self::TYPE_STRING, 'required' => false, 'size' => 255],
        ],
    ];
}
