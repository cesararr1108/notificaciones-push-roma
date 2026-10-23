<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// API v1. El versionado en la URL permite evolucionar el contrato sin romper clientes.
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function (RouteCollection $routes): void {
    $routes->get('health', 'HealthController::index');

    $routes->post('tokens', 'TokensController::registrar', ['filter' => ['jsonbody', 'apiauth:tokens']]);
    $routes->delete('tokens', 'TokensController::eliminar', ['filter' => ['jsonbody', 'apiauth:tokens']]);

    $routes->post('notificaciones', 'NotificacionesController::enviar', ['filter' => ['jsonbody', 'apiauth:notificaciones']]);
    $routes->get('notificaciones/(:segment)', 'NotificacionesController::estado/$1', ['filter' => 'apiauth:notificaciones']);
});
