<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class ApiAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $context = service('authContext');
        $context->clear();
        $actor = service('tokenService')->authenticateBearer($request->getHeaderLine('Authorization'));

        if ($actor === null) {
            return service('response')->setStatusCode(401)->setJSON([
                'status'  => 401,
                'error'   => 'unauthenticated',
                'message' => 'A valid bearer token is required.',
            ]);
        }

        $context->setActor($actor);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        service('authContext')->clear();

        return $response;
    }
}
