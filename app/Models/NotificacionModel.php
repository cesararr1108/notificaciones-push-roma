<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificacionModel extends Model
{
    public const PENDIENTE  = 'pendiente';
    public const PROCESANDO = 'procesando';
    public const COMPLETADO = 'completado';
    public const FALLIDO    = 'fallido';

    protected $table         = 'notificaciones';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'uuid', 'api_client_id', 'organizacion', 'idempotency_key', 'destinatarios',
        'payload', 'estado', 'total', 'exitosos', 'fallidos', 'invalidos',
        'cursor_token_id', 'worker', 'locked_at', 'error', 'completed_at',
    ];

    public static function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Toma atómicamente la siguiente notificación pendiente (o una abandonada
     * por un worker caído). Varios workers pueden correr en paralelo: el
     * UPDATE condicionado garantiza que solo uno gana cada fila.
     *
     * @return array<string, mixed>|null
     */
    public function reclamarSiguiente(string $worker, int $staleSeconds): ?array
    {
        $staleBefore = date('Y-m-d H:i:s', time() - $staleSeconds);

        for ($i = 0; $i < 5; $i++) {
            $candidata = $this->select('id, estado, locked_at')
                ->groupStart()
                    ->where('estado', self::PENDIENTE)
                    ->orGroupStart()
                        ->where('estado', self::PROCESANDO)
                        ->where('locked_at <', $staleBefore)
                    ->groupEnd()
                ->groupEnd()
                ->orderBy('id', 'ASC')
                ->first();

            if ($candidata === null) {
                return null;
            }

            $builder = $this->builder()->where('id', $candidata['id'])->where('estado', $candidata['estado']);
            $candidata['locked_at'] === null
                ? $builder->where('locked_at', null)
                : $builder->where('locked_at', $candidata['locked_at']);

            $builder->update([
                'estado'     => self::PROCESANDO,
                'worker'     => $worker,
                'locked_at'  => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($this->db->affectedRows() === 1) {
                return $this->find($candidata['id']);
            }
            // Otro worker la tomó primero: probamos con la siguiente.
        }

        return null;
    }

    /**
     * Respuesta pública (sin datos internos).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function presentar(array $row): array
    {
        return [
            'id'           => $row['uuid'],
            'organizacion' => $row['organizacion'],
            'estado'       => $row['estado'],
            'total'        => (int) $row['total'],
            'exitosos'     => (int) $row['exitosos'],
            'fallidos'     => (int) $row['fallidos'],
            'invalidos'    => (int) $row['invalidos'],
            'creado'       => $row['created_at'],
            'completado'   => $row['completed_at'],
        ];
    }
}
