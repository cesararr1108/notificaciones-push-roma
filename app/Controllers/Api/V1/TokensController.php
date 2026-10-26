<?php

namespace App\Controllers\Api\V1;

use App\Models\FcmTokenModel;
use Config\Push;

/**
 * Registro y baja de tokens FCM.
 */
class TokensController extends BaseApiController
{
    /**
     * POST /api/v1/tokens
     */
    public function registrar()
    {
        $data = $this->body();

        $org = $this->resolverOrganizacion($data['organizacion'] ?? null);
        if (! is_string($org)) {
            return $org;
        }

        $data['organizacion'] = $org;
        $data['oficina']      = self::upper($data['oficina'] ?? null);
        if (isset($data['rol'])) {
            $data['rol'] = self::upper($data['rol']);
        }
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        $rules = [
            'oficina'        => 'required|string|regex_match[/^[A-Z0-9]{4}$/]',
            'usuario'        => 'permit_empty|string|max_length[190]|regex_match[/^[A-Za-z0-9._@\-]+$/]',
            'nombre_usuario' => 'required|string|max_length[150]',
            'email'          => 'required|valid_email|max_length[190]',
            'rol'            => 'permit_empty|string|max_length[30]|regex_match[/^[A-Z0-9_\-]+$/]',
            // Los tokens FCM son ASCII seguro (base64url + ":"), ~150-300 caracteres.
            'token'          => 'required|string|min_length[20]|max_length[4096]|regex_match[/^[A-Za-z0-9_:\-]+$/]',
            'navegador'      => 'permit_empty|string|max_length[100]',
            'dispositivo'    => 'permit_empty|string|max_length[100]',
        ];

        if (! $this->validateData($data, $rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $limpio = $this->validator->getValidated();
        $limpio['organizacion'] = $org;
        foreach (['nombre_usuario', 'navegador', 'dispositivo'] as $campo) {
            if (isset($limpio[$campo])) {
                // Se guarda texto plano; quien lo muestre en HTML debe escaparlo igualmente.
                $limpio[$campo] = trim(strip_tags($limpio[$campo]));
            }
        }

        $res = model(FcmTokenModel::class)->registrar($limpio, config(Push::class)->maxTokensPerUser);

        return $this->respond([
            'status' => $res['nuevo'] ? 201 : 200,
            'data'   => [
                'id'           => $res['id'],
                'organizacion' => $org,
                'oficina'      => $limpio['oficina'],
                'email'        => $limpio['email'],
                'registrado'   => $res['nuevo'],
            ],
        ], $res['nuevo'] ? 201 : 200);
    }

    /**
     * DELETE /api/v1/tokens  {"token": "..."}
     * Para llamar al cerrar sesión en el dispositivo.
     */
    public function eliminar()
    {
        $data = $this->body();

        $org = $this->resolverOrganizacion($data['organizacion'] ?? null);
        if (! is_string($org)) {
            return $org;
        }

        if (! $this->validateData($data, ['token' => 'required|string|max_length[4096]'])) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        if (! model(FcmTokenModel::class)->darDeBaja($org, $data['token'])) {
            return $this->failNotFound('Token no encontrado.');
        }

        return $this->respondDeleted(['status' => 200, 'data' => ['eliminado' => true]]);
    }
}
