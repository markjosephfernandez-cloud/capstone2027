<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Mis extends BaseConfig
{
    public string $baseURL = 'https://mis.foundationu.com/sandbox/api';
    public string $apiKey = '';
    public bool $loginEnabled = false;
}
