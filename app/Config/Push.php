<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuración del servicio de notificaciones push (FCM HTTP v1).
 *
 * Todos los valores pueden sobrescribirse desde .env, p. ej.:
 *   push.serviceAccountPath = /etc/secrets/firebase-sa.json
 *   push.syncLimit = 200
 */
class Push extends BaseConfig
{
    /**
     * Ruta ABSOLUTA al JSON de la cuenta de servicio de Firebase.
     * Debe estar FUERA del document root (public/) y con permisos 0400.
     */
    public string $serviceAccountPath = '';

    /**
     * ID del proyecto de Firebase. Si se deja vacío se toma del JSON
     * de la cuenta de servicio.
     */
    public string $projectId = '';

    /**
     * URL base de la API de FCM (solo cambiar para pruebas / emuladores).
     */
    public string $fcmBaseUrl = 'https://fcm.googleapis.com';

    /**
     * Si el envío resuelve a <= N tokens se envía en la misma petición
     * (respuesta 200 con el resultado). Por encima se encola y se
     * responde 202; el worker (php spark push:worker) lo procesa.
     */
    public int $syncLimit = 200;

    /**
     * Tokens que el worker lee de la BD por lote.
     */
    public int $chunkSize = 500;

    /**
     * Peticiones HTTP simultáneas hacia FCM (curl_multi).
     */
    public int $concurrency = 50;

    /**
     * Reintentos ante 429 / 5xx de FCM (con backoff exponencial).
     */
    public int $maxRetries = 3;

    /**
     * Timeout (segundos) por petición a FCM.
     */
    public int $timeout = 10;

    /**
     * Máximo de dispositivos (tokens) activos por usuario. Al superar el
     * límite se desactivan los más antiguos.
     */
    public int $maxTokensPerUser = 10;

    /**
     * Máximo de elementos en cada lista de destinatarios (usuarios,
     * oficinas, roles) para una sola petición.
     */
    public int $maxDestinatariosLista = 1000;

    /**
     * Tamaño máximo del cuerpo de la petición (bytes).
     */
    public int $maxBodyBytes = 65536;

    /**
     * Tokens sin actualizar en N días se consideran caducados
     * (Firebase recomienda 270 días). Ver: php spark push:purge
     */
    public int $staleTokenDays = 270;

    /**
     * Una tarea "procesando" sin actividad durante N segundos se
     * considera abandonada (worker caído) y se vuelve a tomar.
     */
    public int $staleJobSeconds = 600;

    /**
     * Intentos fallidos de autenticación permitidos por IP y minuto.
     */
    public int $failedAuthPerMinute = 20;
}
