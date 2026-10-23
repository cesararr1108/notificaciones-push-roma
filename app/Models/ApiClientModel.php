<?php

namespace App\Models;

use CodeIgniter\Model;

class ApiClientModel extends Model
{
    public const SCOPE_TOKENS = 'tokens';
    public const SCOPE_SEND   = 'notificaciones';

    protected $table          = 'api_clients';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $allowedFields  = [
        'nombre', 'organizacion', 'key_prefix', 'key_hash', 'scopes',
        'allowed_ips', 'rate_limit', 'activo', 'ultimo_uso',
    ];

    /**
     * Crea un cliente y devuelve [fila, llave_en_claro].
     * La llave en claro NO se guarda: hay que entregarla al cliente en ese momento.
     *
     * @param list<string> $scopes
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function createClient(string $nombre, ?string $organizacion, array $scopes, ?string $allowedIps = null, int $rateLimit = 120): array
    {
        $prefix = bin2hex(random_bytes(6));          // 12 caracteres, público
        $secret = bin2hex(random_bytes(32));         // 256 bits de entropía
        $key    = 'pk_' . $prefix . '.' . $secret;

        $data = [
            'nombre'       => $nombre,
            'organizacion' => $organizacion,
            'key_prefix'   => $prefix,
            'key_hash'     => hash('sha256', $secret),
            'scopes'       => implode(',', $scopes),
            'allowed_ips'  => $allowedIps,
            'rate_limit'   => $rateLimit,
            'activo'       => 1,
        ];
        $data['id'] = $this->insert($data, true);

        return [$data, $key];
    }

    /**
     * Valida una llave "pk_<prefijo>.<secreto>" en tiempo constante.
     *
     * @return array<string, mixed>|null
     */
    public function findByApiKey(string $apiKey): ?array
    {
        if (! preg_match('/^pk_([a-f0-9]{12})\.([a-f0-9]{64})$/', $apiKey, $m)) {
            return null;
        }

        $cache    = service('cache');
        $cacheKey = 'apiclient_' . $m[1];
        $client   = $cache->get($cacheKey);

        if (! is_array($client)) {
            $client = $this->where('key_prefix', $m[1])->first();
            if ($client === null) {
                return null;
            }
            // Cache corto: revocar una llave tarda como máximo 60 s en surtir efecto.
            $cache->save($cacheKey, $client, 60);
        }

        if (! hash_equals($client['key_hash'], hash('sha256', $m[2])) || (int) $client['activo'] !== 1) {
            return null;
        }

        return $client;
    }
}
