<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Push;

/**
 * Rechaza cuerpos demasiado grandes o que no sean JSON en POST/PUT/PATCH/DELETE.
 */
class JsonBody implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $method = strtoupper($request->getMethod());
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $max  = config(Push::class)->maxBodyBytes;
        $body = (string) $request->getBody();

        if ((int) $request->getHeaderLine('Content-Length') > $max || strlen($body) > $max) {
            return $this->error(413, 'El cuerpo de la petición excede ' . $max . ' bytes.');
        }

        if ($body === '' && $method === 'DELETE') {
            return null;
        }

        if (stripos($request->getHeaderLine('Content-Type'), 'application/json') !== 0) {
            return $this->error(415, 'Content-Type debe ser application/json.');
        }

        json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error(400, 'JSON mal formado.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return service('response')->setStatusCode($status)->setJSON(['status' => $status, 'error' => $message]);
    }
}
