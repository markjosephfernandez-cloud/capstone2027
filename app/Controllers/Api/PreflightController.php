<?php

namespace App\Controllers\Api;

class PreflightController extends ApiController
{
    public function index()
    {
        return $this->response->setStatusCode(204);
    }
}
