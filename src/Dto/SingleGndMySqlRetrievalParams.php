<?php

namespace Efi\Gnd\Dto;

class SingleGndMySqlRetrievalParams
{
    private string $dsn;

    public function __construct(
        public string $dbHost,
        public string $dbName,
        public string $username,
        public string $password,
    )
    {
        $this->dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    }

    public function getDsn()
    {
        return $this->dsn;
    }
}
