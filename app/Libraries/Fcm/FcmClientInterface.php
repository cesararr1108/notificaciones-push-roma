<?php

namespace App\Libraries\Fcm;

interface FcmClientInterface
{
    /**
     * Envía el mismo mensaje a varios tokens.
     *
     * @param array<int|string, string> $tokens  [id_token => token]
     * @param array<string, mixed>      $message Mensaje FCM v1 sin el campo "token".
     *
     * @return array<int|string, FcmResult> Resultado por id_token.
     */
    public function sendToTokens(array $tokens, array $message): array;
}
