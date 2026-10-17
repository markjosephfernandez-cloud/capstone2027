<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class ApiCorsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->applyHeaders(service('response')->setStatusCode(204), $request);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Required after filters also run when authentication stops the request.
        // Keep this policy limited to API routes, including subdirectory installs.
        if (! $request instanceof IncomingRequest) {
            return $response;
        }
        $path = trim($request->getUri()->getRoutePath(), '/');
        if ($path !== 'api/v1' && ! str_starts_with($path, 'api/v1/')) {
            return $response;
        }

        return $this->applyHeaders($response, $request);
    }

    private function applyHeaders(ResponseInterface $response, RequestInterface $request): ResponseInterface
    {
        $origin  = $request->getHeaderLine('Origin');
        $allowed = array_filter(array_map('trim', explode(',', (string) env('greyhound.corsOrigins', 'http://localhost:8080,http://localhost'))));

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            $response->setHeader('Access-Control-Allow-Origin', $origin)
                ->setHeader('Vary', 'Origin')
                ->setHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, DELETE, OPTIONS')
                ->setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept')
                ->setHeader('Access-Control-Max-Age', '600');
        }

        return $response;
    }
}
