<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class ErliOrderLink extends ObjectModel
{
    public $id_erli_order_link;
    public $id_order;
    public $erli_order_id;
    public $last_status;
    public $created_at;

    public static $definition = [
        'table'   => 'erli_order_link',
        'primary' => 'id_erli_order_link',
        'fields'  => [
            'id_order'      => ['type' => self::TYPE_INT,    'required' => true],
            'erli_order_id' => ['type' => self::TYPE_STRING, 'required' => true, 'size' => 64],
            'last_status'   => ['type' => self::TYPE_STRING, 'required' => false, 'size' => 64],
            'created_at'    => ['type' => self::TYPE_DATE,   'required' => false],
        ],
    ];
}
