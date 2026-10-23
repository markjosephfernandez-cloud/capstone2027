<?php

namespace App\Filters;

use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\RequestInterface;

/** Background view logging must not invalidate a user's open upload/edit form. */
final class RecentViewCsrf extends CSRF
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $config = config('Security');
        $regenerate = $config->regenerate;
        $config->regenerate = false;
        try {
            return parent::before($request, $arguments);
        } finally {
            $config->regenerate = $regenerate;
        }
    }
}
