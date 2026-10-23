<?php

namespace Config;

use App\Libraries\ApiContext;
use App\Libraries\Fcm\FcmClient;
use App\Libraries\Fcm\FcmClientInterface;
use App\Libraries\Fcm\GoogleAccessToken;
use App\Libraries\NotificationDispatcher;
use App\Models\FcmTokenModel;
use App\Models\NotificacionModel;
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
    /**
     * Cliente autenticado de la petición en curso (lo rellena el filtro apiauth).
     */
    public static function apiContext(bool $getShared = true): ApiContext
    {
        if ($getShared) {
            return static::getSharedInstance('apiContext');
        }

        return new ApiContext();
    }

    public static function fcm(bool $getShared = true): FcmClientInterface
    {
        if ($getShared) {
            return static::getSharedInstance('fcm');
        }

        $config = config(Push::class);

        return new FcmClient(new GoogleAccessToken($config->serviceAccountPath, service('cache')), $config);
    }

    public static function dispatcher(bool $getShared = true): NotificationDispatcher
    {
        if ($getShared) {
            return static::getSharedInstance('dispatcher');
        }

        return new NotificationDispatcher(
            service('fcm'),
            model(FcmTokenModel::class),
            model(NotificacionModel::class),
            config(Push::class),
        );
    }
}
