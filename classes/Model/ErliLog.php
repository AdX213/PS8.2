<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class ErliLog extends ObjectModel
{
    public $id_erli_log;
    public $type;
    public $reference_id;
    public $message;
    public $payload;
    public $created_at;

    public static $definition = [
        'table'   => 'erli_log',
        'primary' => 'id_erli_log',
        'fields'  => [
            'type'         => ['type' => self::TYPE_STRING, 'required' => true,  'size' => 32],
            'reference_id' => ['type' => self::TYPE_STRING, 'required' => false, 'size' => 64],
            'message'      => ['type' => self::TYPE_STRING, 'required' => false],
            'payload'      => ['type' => self::TYPE_HTML,   'required' => false],
            'created_at'   => ['type' => self::TYPE_DATE,   'required' => true],
        ],
    ];
}
