<?php

namespace App\Controllers\Api\V1;

use App\Libraries\ApiContext;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

abstract class BaseApiController extends Controller
{
    use ResponseTrait;

    protected $format = 'json';

    protected function context(): ApiContext
    {
        return service('apiContext');
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        $json = json_decode((string) $this->request->getBody(), true);

        return is_array($json) ? $json : [];
    }

    /**
     * Determina la organización sobre la que se opera:
     *  - Llave atada a organización: se usa esa. Si el cuerpo trae otra → 403.
     *  - Llave global: la organización es obligatoria en el cuerpo.
     *
     * @return ResponseInterface|string Organización o respuesta de error.
     */
    protected function resolverOrganizacion(?string $solicitada)
    {
        $propia     = $this->context()->organizacion();
        $solicitada = $solicitada !== null ? strtoupper(trim($solicitada)) : null;

        if ($propia !== null) {
            if ($solicitada !== null && $solicitada !== '' && $solicitada !== $propia) {
                return $this->failForbidden('La API key no pertenece a la organización ' . $solicitada . '.');
            }

            return $propia;
        }

        if ($solicitada === null || ! preg_match('/^[A-Z0-9]{4}$/', $solicitada)) {
            return $this->failValidationErrors(['organizacion' => 'La organización (4 caracteres) es obligatoria para llaves globales.']);
        }

        return $solicitada;
    }

    /**
     * Normaliza a mayúsculas los códigos de 4 caracteres.
     */
    protected static function upper(mixed $value): mixed
    {
        return is_string($value) ? strtoupper(trim($value)) : $value;
    }
}
