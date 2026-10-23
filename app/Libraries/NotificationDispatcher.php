<?php

namespace App\Libraries;

use App\Libraries\Fcm\FcmClientInterface;
use App\Models\FcmTokenModel;
use App\Models\NotificacionModel;
use Config\Push;
use Throwable;

/**
 * Resuelve destinatarios, envía por lotes y lleva la contabilidad de cada
 * notificación. Lo usan tanto el endpoint (envíos pequeños, síncronos)
 * como el worker (envíos grandes, en segundo plano).
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly FcmClientInterface $fcm,
        private readonly FcmTokenModel $tokens,
        private readonly NotificacionModel $notificaciones,
        private readonly Push $config,
    ) {
    }

    /**
     * Registra la notificación y, si es pequeña, la envía de inmediato.
     *
     * @param array<string, mixed> $destinatarios
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> Fila de la notificación (actualizada).
     */
    public function crear(int $clientId, string $organizacion, array $destinatarios, array $payload, ?string $idempotencyKey, bool $forzarAsync): array
    {
        $total = $this->tokens->contarDestinatarios($organizacion, $destinatarios);

        $row = [
            'uuid'            => NotificacionModel::uuid(),
            'api_client_id'   => $clientId,
            'organizacion'    => $organizacion,
            'idempotency_key' => $idempotencyKey,
            'destinatarios'   => json_encode($destinatarios, JSON_UNESCAPED_UNICODE),
            'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'estado'          => $total === 0 ? NotificacionModel::COMPLETADO : NotificacionModel::PENDIENTE,
            'total'           => $total,
            'completed_at'    => $total === 0 ? date('Y-m-d H:i:s') : null,
        ];
        $row['id'] = $this->notificaciones->insert($row, true);

        if ($total > 0 && ! $forzarAsync && $total <= $this->config->syncLimit) {
            $this->notificaciones->update($row['id'], [
                'estado'    => NotificacionModel::PROCESANDO,
                'worker'    => 'sync',
                'locked_at' => date('Y-m-d H:i:s'),
            ]);
            $this->procesar($this->notificaciones->find($row['id']));
        }

        return $this->notificaciones->find($row['id']);
    }

    /**
     * Procesa una notificación ya reclamada (estado "procesando").
     * Avanza por lotes y guarda el cursor tras cada uno, de modo que si el
     * proceso muere se reanuda donde quedó (entrega "al menos una vez").
     *
     * @param array<string, mixed> $row
     */
    public function procesar(array $row): void
    {
        $id      = (int) $row['id'];
        $dest    = json_decode($row['destinatarios'], true);
        $message = $this->construirMensaje(json_decode($row['payload'], true));
        $cursor  = (int) $row['cursor_token_id'];

        $exitosos  = (int) $row['exitosos'];
        $fallidos  = (int) $row['fallidos'];
        $invalidos = (int) $row['invalidos'];

        try {
            while (true) {
                $lote = $this->tokens->lote($row['organizacion'], $dest, $cursor, $this->config->chunkSize);
                if ($lote === []) {
                    break;
                }

                $mapa = [];
                foreach ($lote as $t) {
                    $mapa[(int) $t['id']] = $t['token'];
                }

                $resultados = $this->fcm->sendToTokens($mapa, $message);

                $ok = $malos = [];
                foreach ($resultados as $tokenId => $r) {
                    if ($r->ok) {
                        $ok[] = $tokenId;
                    } elseif ($r->tokenInvalido()) {
                        $malos[] = $tokenId;
                    } else {
                        $fallidos++;
                        log_message('warning', 'FCM fallo notif={id} token_id={t} code={c}', ['id' => $id, 't' => $tokenId, 'c' => $r->errorCode]);
                    }
                }

                $exitosos  += count($ok);
                $invalidos += count($malos);
                $this->tokens->marcarEnviados($ok);
                $this->tokens->invalidar($malos, 'fcm_invalido');

                $cursor = (int) array_key_last($mapa);
                $this->notificaciones->update($id, [
                    'exitosos'        => $exitosos,
                    'fallidos'        => $fallidos,
                    'invalidos'       => $invalidos,
                    'cursor_token_id' => $cursor,
                    'locked_at'       => date('Y-m-d H:i:s'),   // latido: el lock sigue vivo
                ]);
            }

            $this->notificaciones->update($id, [
                // El total real puede variar respecto al estimado si se registraron/bajaron tokens mientras tanto.
                'total'        => $exitosos + $fallidos + $invalidos,
                'estado'       => NotificacionModel::COMPLETADO,
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Error procesando notificación {id}: {msg}', ['id' => $id, 'msg' => $e->getMessage()]);
            $this->notificaciones->update($id, [
                'estado' => NotificacionModel::FALLIDO,
                'error'  => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    /**
     * Traduce el payload de la API al formato FCM HTTP v1 (web, Android e iOS).
     *
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    public function construirMensaje(array $p): array
    {
        $n         = $p['notificacion'];
        $data      = array_map('strval', $p['data'] ?? []);
        $prioridad = ($p['prioridad'] ?? 'high') === 'high';
        $ttl       = (int) ($p['ttl'] ?? 86400);

        $notification = ['title' => $n['titulo'], 'body' => $n['cuerpo']];
        if (! empty($n['imagen'])) {
            $notification['image'] = $n['imagen'];
        }
        if (! empty($n['url'])) {
            $data['url'] = $n['url'];
        }

        $message = [
            'notification' => $notification,
            'android'      => [
                'priority' => $prioridad ? 'HIGH' : 'NORMAL',
                'ttl'      => $ttl . 's',
            ],
            'apns' => [
                'headers' => [
                    'apns-priority'   => $prioridad ? '10' : '5',
                    'apns-expiration' => (string) (time() + $ttl),
                ],
            ],
            'webpush' => [
                'headers' => [
                    'Urgency' => $prioridad ? 'high' : 'normal',
                    'TTL'     => (string) $ttl,
                ],
            ],
        ];

        if ($data !== []) {
            $message['data'] = $data;
        }
        if (! empty($n['url'])) {
            $message['webpush']['fcm_options'] = ['link' => $n['url']];
        }

        return $message;
    }
}
