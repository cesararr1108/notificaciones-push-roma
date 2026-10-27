<?php

namespace App\Controllers\Api\V1;

use App\Models\FcmTokenModel;
use App\Models\NotificacionModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Push;

/**
 * Envío de notificaciones push y consulta de su estado.
 */
class NotificacionesController extends BaseApiController
{
    /**
     * Máximo de mensajes personalizados por petición de lote.
     */
    private const MAX_MENSAJES_LOTE = 100;

    /**
     * POST /api/v1/notificaciones — un mensaje para uno o varios destinatarios.
     */
    public function enviar()
    {
        $data = $this->body();

        $org = $this->resolverOrganizacion($data['organizacion'] ?? null);
        if (! is_string($org)) {
            return $org;
        }

        [$errores, $mensaje] = $this->validarMensaje($data);
        if ($errores !== []) {
            return $this->failValidationErrors($errores);
        }

        $idempotencyKey = trim($this->request->getHeaderLine('Idempotency-Key'));
        if ($idempotencyKey !== '' && ! preg_match('/^[A-Za-z0-9_\-]{8,100}$/', $idempotencyKey)) {
            return $this->failValidationErrors(['Idempotency-Key' => 'Formato inválido (8-100 caracteres alfanuméricos, _ o -).']);
        }

        [$row, $repetida] = $this->crear($org, $mensaje, $idempotencyKey !== '' ? $idempotencyKey : null, (bool) ($data['asincrono'] ?? false));

        if ($repetida) {
            return $this->respond(['status' => 200, 'data' => NotificacionModel::presentar($row), 'idempotente' => true]);
        }

        $encolado = self::encolado($row);

        return $this->respond([
            'status' => $encolado ? 202 : 200,
            'data'   => NotificacionModel::presentar($row),
        ], $encolado ? 202 : 200);
    }

    /**
     * POST /api/v1/notificaciones/lote — varios mensajes personalizados
     * (cada uno con sus destinatarios, texto e imagen) en una sola petición.
     *
     * Se validan todos antes de enviar: si alguno tiene errores no se envía ninguno.
     */
    public function lote()
    {
        $data   = $this->body();
        $config = config(Push::class);

        $org = $this->resolverOrganizacion($data['organizacion'] ?? null);
        if (! is_string($org)) {
            return $org;
        }

        $lista = $data['mensajes'] ?? null;
        if (! is_array($lista) || ! array_is_list($lista) || $lista === []) {
            return $this->failValidationErrors(['mensajes' => 'mensajes debe ser una lista con al menos un mensaje.']);
        }
        if (count($lista) > self::MAX_MENSAJES_LOTE) {
            return $this->failValidationErrors(['mensajes' => 'Máximo ' . self::MAX_MENSAJES_LOTE . ' mensajes por petición.']);
        }

        $errores  = [];
        $mensajes = [];
        foreach ($lista as $i => $m) {
            if (! is_array($m)) {
                $errores["mensajes.{$i}"] = 'Cada mensaje debe ser un objeto.';

                continue;
            }
            [$err, $mensaje] = $this->validarMensaje($m);
            foreach ($err as $campo => $msg) {
                $errores["mensajes.{$i}.{$campo}"] = $msg;
            }
            if (isset($m['referencia']) && (! is_string($m['referencia']) || mb_strlen($m['referencia']) > 100)) {
                $errores["mensajes.{$i}.referencia"] = 'referencia debe ser texto de hasta 100 caracteres.';
            }
            $mensaje['referencia'] = $m['referencia'] ?? null;
            $mensajes[$i]          = $mensaje;
        }
        if ($errores !== []) {
            return $this->failValidationErrors($errores);
        }

        $idempotencyKey = trim($this->request->getHeaderLine('Idempotency-Key'));
        if ($idempotencyKey !== '' && ! preg_match('/^[A-Za-z0-9_\-]{8,90}$/', $idempotencyKey)) {
            return $this->failValidationErrors(['Idempotency-Key' => 'Formato inválido (8-90 caracteres alfanuméricos, _ o -).']);
        }

        // Si el lote completo supera el límite síncrono, todo se encola para el worker
        // y la petición responde de inmediato.
        $tokens = model(FcmTokenModel::class);
        $total  = 0;
        foreach ($mensajes as $m) {
            $total += $tokens->contarDestinatarios($org, $m['destinatarios']);
        }
        $async = (bool) ($data['asincrono'] ?? false) || $total > $config->syncLimit;

        $resultados = [];
        $encolados  = false;
        foreach ($mensajes as $i => $m) {
            [$row] = $this->crear($org, $m, $idempotencyKey !== '' ? $idempotencyKey . '-' . $i : null, $async);
            $encolados    = $encolados || self::encolado($row);
            $resultados[] = ['indice' => $i, 'referencia' => $m['referencia']] + NotificacionModel::presentar($row);
        }

        $status = $encolados ? 202 : 200;

        return $this->respond([
            'status'  => $status,
            'resumen' => [
                'mensajes'  => count($resultados),
                'total'     => array_sum(array_column($resultados, 'total')),
                'exitosos'  => array_sum(array_column($resultados, 'exitosos')),
                'fallidos'  => array_sum(array_column($resultados, 'fallidos')),
                'invalidos' => array_sum(array_column($resultados, 'invalidos')),
            ],
            'data' => $resultados,
        ], $status);
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
     * Registra (y envía si es pequeña) una notificación, respetando la idempotencia.
     *
     * @param array{destinatarios: array, payload: array} $mensaje
     *
     * @return array{0: array<string, mixed>, 1: bool} [fila, ya_existía]
     */
    private function crear(string $org, array $mensaje, ?string $idempotencyKey, bool $async): array
    {
        $buscarPrevia = fn () => $idempotencyKey === null ? null : model(NotificacionModel::class)
            ->where('api_client_id', $this->context()->clientId())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (($previa = $buscarPrevia()) !== null) {
            return [$previa, true];
        }

        try {
            $row = service('dispatcher')->crear(
                $this->context()->clientId(),
                $org,
                $mensaje['destinatarios'],
                $mensaje['payload'],
                $idempotencyKey,
                $async,
            );
        } catch (DatabaseException $e) {
            // Dos peticiones simultáneas con la misma Idempotency-Key: gana la primera.
            if (($previa = $buscarPrevia()) === null) {
                throw $e;
            }

            return [$previa, true];
        }

        return [$row, false];
    }

    private static function encolado(array $row): bool
    {
        return in_array($row['estado'], [NotificacionModel::PENDIENTE, NotificacionModel::PROCESANDO], true);
    }

    /**
     * Valida un mensaje (destinatarios + notificación + data) y lo normaliza.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: array<string, string>, 1: array{destinatarios?: array, payload?: array}}
     */
    private function validarMensaje(array $data): array
    {
        $errores = $this->validarDestinatarios($data['destinatarios'] ?? null, config(Push::class)->maxDestinatariosLista);

        $validation = service('validation', null, false);
        $validation->setRules([
            'notificacion.titulo' => 'required|string|max_length[150]',
            'notificacion.cuerpo' => 'required|string|max_length[1000]',
            'notificacion.imagen' => 'permit_empty|valid_url_strict[https]|max_length[500]',
            'notificacion.url'    => 'permit_empty|valid_url_strict[https]|max_length[500]',
            // Nombre del archivo de sonido incluido en la app móvil ("default" = sonido del sistema).
            'notificacion.sonido'        => 'permit_empty|string|max_length[64]|regex_match[/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9]{2,4})?$/]',
            'notificacion.sonido_url'    => 'permit_empty|valid_url_strict[https]|max_length[500]',
            'notificacion.canal_android' => 'permit_empty|string|max_length[64]|regex_match[/^[A-Za-z0-9_\-.]+$/]',
            'prioridad'           => 'permit_empty|in_list[high,normal]',
            'ttl'                 => 'permit_empty|is_natural|less_than_equal_to[2419200]',
        ]);
        if (! $validation->run($data)) {
            $errores += $validation->getErrors();
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
            return [$errores, []];
        }

        $payload = [
            'notificacion' => array_intersect_key($data['notificacion'], array_flip(['titulo', 'cuerpo', 'imagen', 'url', 'sonido', 'sonido_url', 'canal_android'])),
            'data'         => array_map('strval', $extra),
            'prioridad'    => $data['prioridad'] ?? 'high',
            'ttl'          => isset($data['ttl']) ? (int) $data['ttl'] : 86400,
        ];

        // FCM rechaza mensajes de más de 4 KB.
        if (strlen(json_encode($payload, JSON_UNESCAPED_UNICODE)) > 4000) {
            return [['notificacion' => 'El mensaje excede el límite de 4 KB de FCM.'], []];
        }

        return [[], [
            'destinatarios' => $this->normalizarDestinatarios($data['destinatarios']),
            'payload'       => $payload,
        ]];
    }

    /**
     * @return array<string, string>
     */
    private function validarDestinatarios(mixed $dest, int $max): array
    {
        if (! is_array($dest)) {
            return ['destinatarios' => 'destinatarios es obligatorio.'];
        }

        $validadores = [
            'emails'   => static fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false && strlen($v) <= 190,
            'usuarios' => static fn ($v) => (bool) preg_match('/^[A-Za-z0-9._@\-]{1,190}$/', $v),
            'oficinas' => static fn ($v) => (bool) preg_match('/^[A-Za-z0-9]{4}$/', $v),
            'roles'    => static fn ($v) => (bool) preg_match('/^[A-Za-z0-9_\-]{1,30}$/', $v),
        ];

        $hayCriterio = ($dest['todos'] ?? false) === true;

        foreach ($validadores as $campo => $valido) {
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
                if (! is_string($v) || ! $valido(trim($v))) {
                    return ["destinatarios.{$campo}" => 'Valor inválido: ' . (is_string($v) ? mb_substr($v, 0, 64) : gettype($v))];
                }
            }
            $hayCriterio = $hayCriterio || $lista !== [];
        }

        if (isset($dest['todos']) && ! is_bool($dest['todos'])) {
            return ['destinatarios.todos' => 'Debe ser booleano.'];
        }

        return $hayCriterio ? [] : ['destinatarios' => 'Indique emails, oficinas, roles, usuarios o "todos": true.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizarDestinatarios(array $dest): array
    {
        if (($dest['todos'] ?? false) === true) {
            return ['todos' => true];
        }

        $norm = static fn (array $l, callable $f) => array_values(array_unique(array_map(static fn ($v) => $f(trim($v)), $l)));

        return array_filter([
            'emails'   => $norm($dest['emails'] ?? [], 'strtolower'),
            'usuarios' => $norm($dest['usuarios'] ?? [], static fn ($v) => $v),
            'oficinas' => $norm($dest['oficinas'] ?? [], 'strtoupper'),
            'roles'    => $norm($dest['roles'] ?? [], 'strtoupper'),
        ]);
    }
}
