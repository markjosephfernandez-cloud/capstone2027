<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function incomingrequest(?App $config = null, bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('incomingrequest', $config);
        }

        $request = \CodeIgniter\Config\Services::incomingrequest($config, false);
        // PHP uses zero for unlimited POST size. This framework version instead
        // leaves the literal stream name as the body of nonempty requests.
        // Multipart uploads must stay with PHP's normal streaming/file handling.
        if ((string) ini_get('post_max_size') === '0'
            && $request->getBody() === 'php://input'
            && !str_contains(strtolower($request->getHeaderLine('Content-Type')), 'multipart/form-data')) {
            $body = file_get_contents('php://input');
            $request->setBody($body === false || $body === '' ? null : $body);
        }

        return $request;
    }

    public static function authContext(bool $getShared = true): \App\Libraries\AuthContext
    {
        if ($getShared) {
            return static::getSharedInstance('authContext');
        }

        return new \App\Libraries\AuthContext();
    }

    public static function tokenService(bool $getShared = true): \App\Services\TokenService
    {
        if ($getShared) {
            return static::getSharedInstance('tokenService');
        }

        return new \App\Services\TokenService();
    }

    public static function storage(bool $getShared = true): \App\Services\StorageService
    {
        if ($getShared) {
            return static::getSharedInstance('storage');
        }

        return new \App\Services\StorageService();
    }

    public static function serverScan(bool $getShared = true): \App\Services\ServerScanService
    {
        if ($getShared) {
            return static::getSharedInstance('serverScan');
        }

        return new \App\Services\ServerScanService();
    }

    public static function audit(bool $getShared = true): \App\Services\AuditService
    {
        if ($getShared) {
            return static::getSharedInstance('audit');
        }

        return new \App\Services\AuditService(db_connect());
    }

    public static function credentials(bool $getShared = true): \App\Services\CredentialsService
    {
        if ($getShared) {
            return static::getSharedInstance('credentials');
        }

        return new \App\Services\CredentialsService();
    }

    public static function eventDetection(bool $getShared = true): \App\Services\EventDetectionService
    {
        if ($getShared) {
            return static::getSharedInstance('eventDetection');
        }

        return new \App\Services\EventDetectionService();
    }

    public static function passports(bool $getShared = true): \App\Services\PassportService
    {
        if ($getShared) {
            return static::getSharedInstance('passports');
        }

        return new \App\Services\PassportService();
    }

    public static function watermarking(bool $getShared = true): \App\Services\WatermarkService
    {
        if ($getShared) {
            return static::getSharedInstance('watermarking');
        }

        return new \App\Services\WatermarkService();
    }

    public static function distribution(bool $getShared = true): \App\Services\DistributionService
    {
        if ($getShared) {
            return static::getSharedInstance('distribution');
        }
        return new \App\Services\DistributionService();
    }

    public static function imageSimilarity(bool $getShared = true): \App\Services\ImageSimilarityService
    {
        if ($getShared) {
            return static::getSharedInstance('imageSimilarity');
        }

        return new \App\Services\ImageSimilarityService(db_connect());
    }

    public static function consent(bool $getShared = true): \App\Services\ConsentService
    {
        if ($getShared) {
            return static::getSharedInstance('consent');
        }

        return new \App\Services\ConsentService();
    }
    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
}
