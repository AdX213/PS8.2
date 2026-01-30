<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class LogRepository
{
    public function add($type, $referenceId, $message, $payload = null)
    {
        return $this->addLog($type, $referenceId, $message, $payload);
    }

    public function addLog($type, $referenceId, $message, $payload = null)
    {
        return Db::getInstance()->insert('erli_log', [
            'type'         => pSQL($type),
            'reference_id' => $referenceId !== null ? pSQL($referenceId) : null,
            'message'      => pSQL($message),
            'payload'      => $payload !== null ? pSQL($payload, true) : null,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    public function getLastLogs($limit = 50)
    {
        $sql = new DbQuery();
        $sql->select('*')
            ->from('erli_log')
            ->orderBy('id_erli_log DESC')
            ->limit((int) $limit);

        return Db::getInstance()->executeS($sql);
    }
}

