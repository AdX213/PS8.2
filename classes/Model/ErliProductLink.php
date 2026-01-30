<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class ErliProductLink extends ObjectModel
{
    public $id_erli_product_link;
    public $id_product;
    public $id_product_attribute;
    public $external_id;
    public $last_payload;
    public $last_synced_at;
    public $last_error;

    public static $definition = [
        'table'   => 'erli_product_link',
        'primary' => 'id_erli_product_link',
        'fields'  => [
            'id_product'           => ['type' => self::TYPE_INT,    'required' => true],
            'id_product_attribute' => ['type' => self::TYPE_INT,    'required' => false],
            'external_id'          => ['type' => self::TYPE_STRING, 'required' => false, 'size' => 64],
            'last_payload'         => ['type' => self::TYPE_HTML,   'required' => false],
            'last_synced_at'       => ['type' => self::TYPE_DATE,   'required' => false],
            'last_error'           => ['type' => self::TYPE_STRING, 'required' => false],
        ],
    ];
}
