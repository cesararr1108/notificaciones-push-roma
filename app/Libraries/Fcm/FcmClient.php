<?php

namespace App\Libraries\Fcm;

use Config\Push;

/**
 * Cliente FCM HTTP v1.
 *
 * La API v1 acepta un token por petición (el endpoint "batch" fue retirado
 * por Google), así que el rendimiento se obtiene enviando N peticiones en
 * paralelo con curl_multi y reutilizando conexiones HTTP/2.
 */
class FcmClient implements FcmClientInterface
{
    private string $endpoint;

    public function __construct(private readonly GoogleAccessToken $auth, private readonly Push $config)
    {
        $projectId = $config->projectId !== '' ? $config->projectId : $auth->projectId();
        $this->endpoint = rtrim($config->fcmBaseUrl, '/') . '/v1/projects/' . rawurlencode($projectId) . '/messages:send';
    }

    public function sendToTokens(array $tokens, array $message): array
    {
        $results    = [];
        $pendientes = $tokens;

        for ($intento = 0; $pendientes !== [] && $intento <= $this->config->maxRetries; $intento++) {
            if ($intento > 0) {
                // Backoff exponencial con jitter: 1s, 2s, 4s... (+0-500 ms)
                usleep((int) ((2 ** ($intento - 1)) * 1_000_000 + random_int(0, 500_000)));
            }

            $ronda      = $this->enviarParalelo($pendientes, $message);
            $pendientes = [];
            $renovar    = false;

            foreach ($ronda as $id => $result) {
                $results[$id] = $result;
                if ($result->httpStatus === 401) {
                    // Access token caducado o revocado: se pide uno nuevo y se reintenta.
                    $renovar = true;
                }
                if ($result->reintentable() || $result->httpStatus === 401) {
                    $pendientes[$id] = $tokens[$id];
                }
            }

            if ($renovar) {
                $this->auth->invalidate();
            }
        }

        return $results;
    }

    /**
     * @param array<int|string, string> $tokens
     *
     * @return array<int|string, FcmResult>
     */
    private function enviarParalelo(array $tokens, array $message): array
    {
        $accessToken = $this->auth->get();
        $headers     = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=utf-8',
        ];

        $mh = curl_multi_init();
        if (defined('CURLPIPE_MULTIPLEX')) {
            curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        }

        $cola    = $tokens;
        $activos = [];   // spl_object_id(handle) => id_token
        $results = [];

        $agregar = function () use (&$cola, &$activos, $mh, $headers, $message): void {
            while ($cola !== [] && count($activos) < $this->config->concurrency) {
                $id    = array_key_first($cola);
                $token = $cola[$id];
                unset($cola[$id]);

                $msg          = $message;
                $msg['token'] = $token;

                $ch = curl_init($this->endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_POSTFIELDS     => json_encode(['message' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $this->config->timeout,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTP_VERSION   => defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
                ]);
                curl_multi_add_handle($mh, $ch);
                $activos[spl_object_id($ch)] = $id;
            }
        };

        $agregar();

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }

            while ($info = curl_multi_info_read($mh)) {
                $ch       = $info['handle'];
                $id       = $activos[spl_object_id($ch)];
                $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body     = (string) curl_multi_getcontent($ch);

                $results[$id] = $info['result'] === CURLE_OK
                    ? FcmResult::fromResponse($http, $body)
                    : new FcmResult(false, 0, 'NETWORK_ERROR', curl_error($ch));

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($activos[spl_object_id($ch)]);

                $agregar();
                $running = $running || $activos !== [];
            }
        } while (($running || $activos !== []) && $status === CURLM_OK);

        curl_multi_close($mh);

        // Si curl_multi abortó, los tokens sin respuesta se reintentan en la siguiente ronda.
        foreach ($tokens as $id => $_) {
            $results[$id] ??= new FcmResult(false, 0, 'NETWORK_ERROR', 'Sin respuesta');
        }

        return $results;
    }
}
