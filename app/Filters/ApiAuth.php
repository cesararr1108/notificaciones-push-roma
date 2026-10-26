<?php

namespace App\Filters;

use App\Models\ApiClientModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Push;

/**
 * Autenticación por API key + autorización por scope + rate limiting.
 *
 * Uso en rutas: ['filter' => 'apiauth:notificaciones']
 *
 * Cabeceras aceptadas:
 *   Authorization: Bearer pk_xxxxxxxxxxxx.yyyy...
 *   X-API-Key: pk_xxxxxxxxxxxx.yyyy...
 */
class ApiAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        /** @var IncomingRequest $request */
        $config    = config(Push::class);
        $throttler = service('throttler');
        $ip        = $request->getIPAddress();
        $ipKey     = 'authfail_' . md5($ip);

        // Si esta IP ya acumuló demasiados fallos, se bloquea sin consultar la BD.
        if ($this->fallosRecientes($ipKey) >= $config->failedAuthPerMinute) {
            return $this->error(429, 'Demasiados intentos fallidos. Intente más tarde.', 60);
        }

        $client = model(ApiClientModel::class)->findByApiKey($this->extraerLlave($request));

        if ($client === null) {
            $this->registrarFallo($ipKey);

            return $this->error(401, 'API key inválida o ausente.');
        }

        if (! $this->ipPermitida($ip, $client['allowed_ips'] ?? null)) {
            return $this->error(403, 'IP no autorizada para esta API key: ' . $ip);
        }

        service('apiContext')->setClient($client);

        $scope = is_array($arguments) ? ($arguments[0] ?? null) : null;
        if ($scope !== null && $scope !== '' && ! service('apiContext')->hasScope($scope)) {
            return $this->error(403, 'La API key no tiene permiso: ' . $scope);
        }

        // Rate limit por cliente: rate_limit peticiones por minuto (token bucket).
        $limit = max(1, (int) $client['rate_limit']);
        if ($throttler->check('client_' . $client['id'], $limit, MINUTE) === false) {
            return $this->error(429, 'Límite de peticiones excedido.', max(1, $throttler->getTokenTime()));
        }

        $this->tocarUltimoUso((int) $client['id']);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function extraerLlave(IncomingRequest $request): string
    {
        $auth = $request->getHeaderLine('Authorization');
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        return trim($request->getHeaderLine('X-API-Key'));
    }

    private function fallosRecientes(string $key): int
    {
        return (int) (service('cache')->get($key) ?? 0);
    }

    private function registrarFallo(string $key): void
    {
        $cache = service('cache');
        $cache->save($key, $this->fallosRecientes($key) + 1, MINUTE);
    }

    /**
     * Actualiza ultimo_uso como máximo una vez por minuto por cliente para
     * no convertir cada petición en una escritura.
     */
    private function tocarUltimoUso(int $clientId): void
    {
        $cache = service('cache');
        $key   = 'apiclient_used_' . $clientId;
        if ($cache->get($key) === null) {
            $cache->save($key, 1, MINUTE);
            model(ApiClientModel::class)->builder()->where('id', $clientId)->update(['ultimo_uso' => date('Y-m-d H:i:s')]);
        }
    }

    public static function ipPermitida(string $ip, ?string $allowed): bool
    {
        if ($allowed === null || trim($allowed) === '') {
            return true;
        }

        foreach (explode(',', $allowed) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (! str_contains($rule, '/')) {
                if (inet_pton($rule) !== false && inet_pton($rule) === @inet_pton($ip)) {
                    return true;
                }

                continue;
            }

            [$subnet, $bits] = explode('/', $rule, 2);
            $ipBin  = @inet_pton($ip);
            $netBin = @inet_pton($subnet);
            if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
                continue;
            }

            $bits  = (int) $bits;
            $bytes = intdiv($bits, 8);
            $rest  = $bits % 8;
            if (strncmp($ipBin, $netBin, $bytes) !== 0) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    private function error(int $status, string $message, ?int $retryAfter = null): ResponseInterface
    {
        $response = service('response')->setStatusCode($status)->setJSON([
            'status' => $status,
            'error'  => $message,
        ]);
        if ($retryAfter !== null) {
            $response->setHeader('Retry-After', (string) $retryAfter);
        }

        return $response->setHeader('Cache-Control', 'no-store');
    }
}
