<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

class FcmTokenModel extends Model
{
    protected $table         = 'fcm_tokens';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'organizacion', 'oficina', 'usuario', 'nombre_usuario', 'email', 'rol',
        'token', 'token_hash', 'navegador', 'dispositivo', 'activo', 'motivo_baja',
        'fecha_registro', 'fecha_actualizacion', 'ultimo_envio',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Registra o actualiza un token. Un token identifica a un dispositivo:
     * si ya existía (aunque fuera de otro usuario u organización) se
     * reasigna al usuario actual, porque es quien inició sesión en él.
     *
     * @param array<string, mixed> $data
     *
     * @return array{id: int, nuevo: bool}
     */
    public function registrar(array $data, int $maxPorUsuario): array
    {
        $now  = date('Y-m-d H:i:s');
        $hash = self::hashToken($data['token']);

        $row = [
            'organizacion'        => $data['organizacion'],
            'oficina'             => $data['oficina'],
            'usuario'             => $data['usuario'] ?? null,
            'nombre_usuario'      => $data['nombre_usuario'],
            'email'               => $data['email'],
            'rol'                 => $data['rol'] ?? null,
            'token'               => $data['token'],
            'token_hash'          => $hash,
            'navegador'           => $data['navegador'] ?? null,
            'dispositivo'         => $data['dispositivo'] ?? null,
            'activo'              => 1,
            'motivo_baja'         => null,
            'fecha_actualizacion' => $now,
        ];

        $this->db->transStart();

        $existing = $this->select('id')->where('token_hash', $hash)->first();

        if ($existing !== null) {
            $this->builder()->where('id', $existing['id'])->update($row);
            $id    = (int) $existing['id'];
            $nuevo = false;
        } else {
            $row['fecha_registro'] = $now;
            $this->builder()->insert($row);
            $id    = (int) $this->db->insertID();
            $nuevo = true;
        }

        $this->limitarDispositivos($data['organizacion'], $data['email'], $maxPorUsuario);

        $this->db->transComplete();

        return ['id' => $id, 'nuevo' => $nuevo];
    }

    /**
     * Borra los tokens más antiguos de un usuario (email) por encima del límite.
     */
    private function limitarDispositivos(string $organizacion, string $email, int $max): void
    {
        $sobrantes = $this->select('id')
            ->where(['organizacion' => $organizacion, 'email' => $email])
            ->orderBy('fecha_actualizacion', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll(1000, $max);

        $this->eliminar(array_map('intval', array_column($sobrantes, 'id')));
    }

    /**
     * Baja solicitada por el cliente (cierre de sesión): se borra el token.
     */
    public function darDeBaja(string $organizacion, string $token): bool
    {
        $this->builder()
            ->where('organizacion', $organizacion)
            ->where('token_hash', self::hashToken($token))
            ->delete();

        return $this->db->affectedRows() > 0;
    }

    /**
     * Borra tokens que ya no sirven (rechazados por FCM, sobrantes o caducados)
     * para que no se acumulen registros que nunca se van a usar.
     *
     * @param list<int> $ids
     */
    public function eliminar(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->builder()->whereIn('id', $ids)->delete();
    }

    /**
     * @param list<int> $ids
     */
    public function marcarEnviados(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->builder()->whereIn('id', $ids)->update(['ultimo_envio' => date('Y-m-d H:i:s')]);
    }

    /**
     * Construye la consulta de destinatarios.
     *
     * Semántica:
     *  - "todos": true                  → todos los tokens activos de la organización.
     *  - "emails": [...] / "usuarios"   → esas personas (todos sus dispositivos).
     *  - "oficinas" y/o "roles"         → segmento; si vienen ambos se combinan con AND
     *                                     (p. ej. rol SUPERVISOR de la oficina 0001).
     *  - personas + segmento            → unión (OR) de ambos.
     *
     * @param array{todos?: bool, emails?: list<string>, usuarios?: list<string>, oficinas?: list<string>, roles?: list<string>} $dest
     */
    public function destinatariosQuery(string $organizacion, array $dest): BaseBuilder
    {
        $builder = $this->db->table($this->table)
            ->where('organizacion', $organizacion)
            ->where('activo', 1);

        if (! empty($dest['todos'])) {
            return $builder;
        }

        $emails   = $dest['emails'] ?? [];
        $usuarios = $dest['usuarios'] ?? [];
        $oficinas = $dest['oficinas'] ?? [];
        $roles    = $dest['roles'] ?? [];

        $builder->groupStart();

        if ($emails !== []) {
            $builder->orWhereIn('email', $emails);
        }
        if ($usuarios !== []) {
            $builder->orWhereIn('usuario', $usuarios);
        }

        if ($oficinas !== [] || $roles !== []) {
            $builder->orGroupStart();
            if ($oficinas !== []) {
                $builder->whereIn('oficina', $oficinas);
            }
            if ($roles !== []) {
                $builder->whereIn('rol', $roles);
            }
            $builder->groupEnd();
        }

        return $builder->groupEnd();
    }

    public function contarDestinatarios(string $organizacion, array $dest): int
    {
        return $this->destinatariosQuery($organizacion, $dest)->countAllResults();
    }

    /**
     * Lote de destinatarios con paginación por cursor (id > $afterId),
     * que a diferencia de OFFSET mantiene el coste constante en tablas grandes.
     *
     * @return list<array{id: int|string, token: string}>
     */
    public function lote(string $organizacion, array $dest, int $afterId, int $limit): array
    {
        return $this->destinatariosQuery($organizacion, $dest)
            ->select('id, token')
            ->where('id >', $afterId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    /**
     * Borra tokens sin actualizar en N días (FCM los considera caducados).
     */
    public function purgarCaducados(int $dias): int
    {
        $limite = date('Y-m-d H:i:s', time() - $dias * 86400);
        $this->builder()->where('fecha_actualizacion <', $limite)->delete();

        return $this->db->affectedRows();
    }
}
