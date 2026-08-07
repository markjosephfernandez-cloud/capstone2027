<?php

namespace App\Controllers\Api;

class HealthController extends ApiController
{
    public function index()
    {
        $database = 'unavailable';
        try {
            $database = db_connect()->tableExists('files') ? 'ready' : 'schema_missing';
        } catch (\Throwable) {
            // Health checks should not expose database credentials or exceptions.
        }

        return $this->success([
            'service'  => 'greyhound-api',
            'database' => $database,
            'time'     => gmdate('c'),
        ], $database === 'ready' ? 200 : 503);
    }
}
