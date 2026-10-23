<?php

namespace Tests\Support\Fcm;

use App\Libraries\Fcm\FcmClientInterface;
use App\Libraries\Fcm\FcmResult;

/**
 * Cliente FCM falso: registra lo enviado y responde según $respuestas[token].
 */
class FakeFcmClient implements FcmClientInterface
{
    /** @var list<array{token: string, message: array}> */
    public array $enviados = [];

    /** @var array<string, FcmResult> */
    public array $respuestas = [];

    public function sendToTokens(array $tokens, array $message): array
    {
        $out = [];
        foreach ($tokens as $id => $token) {
            $this->enviados[] = ['token' => $token, 'message' => $message];
            $out[$id]         = $this->respuestas[$token] ?? FcmResult::success();
        }

        return $out;
    }
}
