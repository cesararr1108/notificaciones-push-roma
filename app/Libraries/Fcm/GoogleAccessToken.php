<?php

namespace App\Libraries\Fcm;

use CodeIgniter\Cache\CacheInterface;
use RuntimeException;

/**
 * Obtiene un access token OAuth2 para FCM HTTP v1 a partir de la cuenta
 * de servicio (flujo JWT bearer, RS256). El token dura 1 h y se guarda en
 * caché para no pedirlo en cada envío.
 */
class GoogleAccessToken
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var array<string, string> */
    private array $credentials;

    public function __construct(string $serviceAccountPath, private readonly CacheInterface $cache)
    {
        if ($serviceAccountPath === '' || ! is_file($serviceAccountPath) || ! is_readable($serviceAccountPath)) {
            throw new RuntimeException('No se encuentra la cuenta de servicio de Firebase (push.serviceAccountPath).');
        }

        $json = json_decode((string) file_get_contents($serviceAccountPath), true);
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('El JSON de la cuenta de servicio de Firebase no es válido.');
        }

        $this->credentials = $json;
    }

    public function projectId(): string
    {
        return (string) ($this->credentials['project_id'] ?? '');
    }

    public function invalidate(): void
    {
        $this->cache->delete($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'fcm_access_token_' . md5($this->credentials['client_email']);
    }

    public function get(): string
    {
        $cacheKey = $this->cacheKey();
        $cached   = $this->cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tokenUri = $this->credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now      = time();

        $jwt = $this->jwt([
            'iss'   => $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $tokenUri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]);

        $ch = curl_init($tokenUri);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($body, true);
        if ($status !== 200 || empty($data['access_token'])) {
            // No se incluye el cuerpo completo para no filtrar información sensible a los logs.
            throw new RuntimeException('No se pudo obtener el access token de Google (HTTP ' . $status . ').');
        }

        $ttl = max(60, (int) ($data['expires_in'] ?? 3600) - 300);
        $this->cache->save($cacheKey, $data['access_token'], $ttl);

        return $data['access_token'];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function jwt(array $claims): string
    {
        $segments = [
            $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->b64(json_encode($claims)),
        ];

        $signature = '';
        if (! openssl_sign(implode('.', $segments), $signature, $this->credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el JWT de la cuenta de servicio.');
        }
        $segments[] = $this->b64($signature);

        return implode('.', $segments);
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
