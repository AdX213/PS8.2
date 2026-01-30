<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class ErliShippingMap extends ObjectModel
{
    public $id_erli_shipping_map;
    public $id_carrier;
    public $erli_tag;
    public $erli_name;

    public static $definition = [
        'table'   => 'erli_shipping_map',
        'primary' => 'id_erli_shipping_map',
        'fields'  => [
            'id_carrier' => ['type' => self::TYPE_INT,    'required' => true],
            'erli_tag'   => ['type' => self::TYPE_STRING, 'required' => true,  'size' => 64],
            'erli_name'  => ['type' => self::TYPE_STRING, 'required' => false, 'size' => 255],
        ],
    ];
}
