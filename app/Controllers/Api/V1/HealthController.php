<?php

namespace App\Controllers\Api\V1;

use Throwable;

class HealthController extends BaseApiController
{
    /**
     * GET /api/v1/health — para el balanceador de carga. No expone detalles internos.
     */
    public function index()
    {
        try {
            db_connect()->query('SELECT 1');
        } catch (Throwable) {
            return $this->respond(['status' => 'error'], 503);
        }

        return $this->respond(['status' => 'ok']);
    }
}
