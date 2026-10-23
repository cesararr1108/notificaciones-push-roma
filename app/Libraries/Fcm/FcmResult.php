<?php

namespace App\Libraries\Fcm;

final class FcmResult
{
    /**
     * Errores de FCM que indican que el token ya no sirve y debe darse de baja.
     */
    private const TOKEN_INVALIDO = ['UNREGISTERED', 'SENDER_ID_MISMATCH'];

    public function __construct(
        public readonly bool $ok,
        public readonly int $httpStatus,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public static function success(): self
    {
        return new self(true, 200);
    }

    public static function fromResponse(int $status, string $body): self
    {
        if ($status === 200) {
            return self::success();
        }

        $json    = json_decode($body, true);
        $error   = is_array($json) ? ($json['error'] ?? []) : [];
        $code    = $error['status'] ?? null;
        $message = isset($error['message']) ? mb_substr((string) $error['message'], 0, 200) : null;

        // El código específico de FCM viene en details[].errorCode
        foreach ($error['details'] ?? [] as $detail) {
            if (isset($detail['errorCode'])) {
                $code = $detail['errorCode'];
                break;
            }
        }

        return new self(false, $status, $code ?? 'HTTP_' . $status, $message);
    }

    public function tokenInvalido(): bool
    {
        if ($this->ok) {
            return false;
        }
        if (in_array($this->errorCode, self::TOKEN_INVALIDO, true) || $this->httpStatus === 404) {
            return true;
        }

        // INVALID_ARGUMENT también se usa para errores del payload; solo se
        // da de baja el token cuando FCM indica que el problema es el token.
        return $this->errorCode === 'INVALID_ARGUMENT'
            && $this->errorMessage !== null
            && stripos($this->errorMessage, 'registration token') !== false;
    }

    public function reintentable(): bool
    {
        return ! $this->ok && ($this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500);
    }
}
