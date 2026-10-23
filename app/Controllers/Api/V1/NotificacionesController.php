<?php

namespace App\Controllers\Api\V1;

use App\Models\NotificacionModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Push;

/**
 * Envío de notificaciones push y consulta de su estado.
 */
class NotificacionesController extends BaseApiController
{
    /**
     * POST /api/v1/notificaciones
     */
    public function enviar()
    {
        $data   = $this->body();
        $config = config(Push::class);

        $org = $this->resolverOrganizacion($data['organizacion'] ?? null);
        if (! is_string($org)) {
            return $org;
        }

        $errores = $this->validarDestinatarios($data['destinatarios'] ?? null, $config->maxDestinatariosLista);

        $rules = [
            'notificacion.titulo' => 'required|string|max_length[150]',
            'notificacion.cuerpo' => 'required|string|max_length[1000]',
            'notificacion.imagen' => 'permit_empty|valid_url_strict[https]|max_length[500]',
            'notificacion.url'    => 'permit_empty|valid_url_strict[https]|max_length[500]',
            'prioridad'           => 'permit_empty|in_list[high,normal]',
            'ttl'                 => 'permit_empty|is_natural|less_than_equal_to[2419200]',
        ];
        if (! $this->validateData($data, $rules)) {
            $errores += $this->validator->getErrors();
        }

        $extra = $data['data'] ?? [];
        if (! is_array($extra) || ($extra !== [] && array_is_list($extra))) {
            $errores['data'] = 'data debe ser un objeto clave/valor.';
        } else {
            foreach ($extra as $k => $v) {
                // Claves reservadas por FCM.
                if (! preg_match('/^[A-Za-z0-9_\-]{1,64}$/', (string) $k) || preg_match('/^(from|notification|message_type|google|gcm)/i', (string) $k)) {
                    $errores['data'] = 'Clave no permitida en data: ' . $k;
                    break;
                }
                if (! is_scalar($v)) {
                    $errores['data'] = 'Los valores de data deben ser texto o número.';
                    break;
                }
            }
        }

        if ($errores !== []) {
            return $this->failValidationErrors($errores);
        }

        $payload = [
            'notificacion' => array_intersect_key($data['notificacion'], array_flip(['titulo', 'cuerpo', 'imagen', 'url'])),
            'data'         => array_map('strval', $extra),
            'prioridad'    => $data['prioridad'] ?? 'high',
            'ttl'          => isset($data['ttl']) ? (int) $data['ttl'] : 86400,
        ];

        // FCM rechaza mensajes de más de 4 KB.
        if (strlen(json_encode($payload, JSON_UNESCAPED_UNICODE)) > 4000) {
            return $this->failValidationErrors(['notificacion' => 'El mensaje excede el límite de 4 KB de FCM.']);
        }

        $idempotencyKey = trim($this->request->getHeaderLine('Idempotency-Key'));
        if ($idempotencyKey !== '') {
            if (! preg_match('/^[A-Za-z0-9_\-]{8,100}$/', $idempotencyKey)) {
                return $this->failValidationErrors(['Idempotency-Key' => 'Formato inválido (8-100 caracteres alfanuméricos, _ o -).']);
            }
            $previa = model(NotificacionModel::class)
                ->where('api_client_id', $this->context()->clientId())
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($previa !== null) {
                return $this->respond(['status' => 200, 'data' => NotificacionModel::presentar($previa), 'idempotente' => true]);
            }
        }

        try {
            $row = service('dispatcher')->crear(
                $this->context()->clientId(),
                $org,
                $this->normalizarDestinatarios($data['destinatarios']),
                $payload,
                $idempotencyKey !== '' ? $idempotencyKey : null,
                (bool) ($data['asincrono'] ?? false),
            );
        } catch (DatabaseException $e) {
            // Dos peticiones simultáneas con la misma Idempotency-Key: gana la primera.
            $previa = $idempotencyKey === '' ? null : model(NotificacionModel::class)
                ->where('api_client_id', $this->context()->clientId())
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($previa === null) {
                throw $e;
            }

            return $this->respond(['status' => 200, 'data' => NotificacionModel::presentar($previa), 'idempotente' => true]);
        }

        $encolado = in_array($row['estado'], [NotificacionModel::PENDIENTE, NotificacionModel::PROCESANDO], true);

        return $this->respond([
            'status' => $encolado ? 202 : 200,
            'data'   => NotificacionModel::presentar($row),
        ], $encolado ? 202 : 200);
    }

    /**
     * GET /api/v1/notificaciones/{uuid}
     */
    public function estado(string $uuid)
    {
        $query = model(NotificacionModel::class)->where('uuid', $uuid);
        if (($org = $this->context()->organizacion()) !== null) {
            $query->where('organizacion', $org);
        }
        $row = $query->first();

        if ($row === null) {
            return $this->failNotFound('Notificación no encontrada.');
        }

        return $this->respond(['status' => 200, 'data' => NotificacionModel::presentar($row)]);
    }

    /**
     * @return array<string, string>
     */
    private function validarDestinatarios(mixed $dest, int $max): array
    {
        if (! is_array($dest)) {
            return ['destinatarios' => 'destinatarios es obligatorio.'];
        }

        $patrones = [
            'usuarios' => '/^[A-Za-z0-9._@\-]{1,64}$/',
            'oficinas' => '/^[A-Za-z0-9]{4}$/',
            'roles'    => '/^[A-Za-z0-9_\-]{1,30}$/',
        ];

        $hayCriterio = ! empty($dest['todos']) && $dest['todos'] === true;

        foreach ($patrones as $campo => $patron) {
            if (! isset($dest[$campo])) {
                continue;
            }
            $lista = $dest[$campo];
            if (! is_array($lista) || ! array_is_list($lista)) {
                return ["destinatarios.{$campo}" => 'Debe ser una lista.'];
            }
            if (count($lista) > $max) {
                return ["destinatarios.{$campo}" => "Máximo {$max} elementos por petición."];
            }
            foreach ($lista as $v) {
                if (! is_string($v) || ! preg_match($patron, $v)) {
                    return ["destinatarios.{$campo}" => 'Valor inválido: ' . (is_string($v) ? mb_substr($v, 0, 64) : gettype($v))];
                }
            }
            $hayCriterio = $hayCriterio || $lista !== [];
        }

        if (isset($dest['todos']) && ! is_bool($dest['todos'])) {
            return ['destinatarios.todos' => 'Debe ser booleano.'];
        }

        return $hayCriterio ? [] : ['destinatarios' => 'Indique usuarios, oficinas, roles o "todos": true.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizarDestinatarios(array $dest): array
    {
        if (($dest['todos'] ?? false) === true) {
            return ['todos' => true];
        }

        $up = static fn (array $l) => array_values(array_unique(array_map('strtoupper', $l)));

        return array_filter([
            'usuarios' => array_values(array_unique($dest['usuarios'] ?? [])),
            'oficinas' => $up($dest['oficinas'] ?? []),
            'roles'    => $up($dest['roles'] ?? []),
        ]);
    }
}
