<?php

namespace App\Libraries;

/**
 * Datos del cliente de API autenticado en la petición actual.
 */
class ApiContext
{
    /** @var array<string, mixed>|null */
    private ?array $client = null;

    /**
     * @param array<string, mixed>|null $client
     */
    public function setClient(?array $client): void
    {
        $this->client = $client;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function client(): ?array
    {
        return $this->client;
    }

    public function clientId(): int
    {
        return (int) ($this->client['id'] ?? 0);
    }

    /**
     * Organización a la que está atada la llave; null = cliente global.
     */
    public function organizacion(): ?string
    {
        return $this->client['organizacion'] ?? null;
    }

    public function hasScope(string $scope): bool
    {
        $scopes = array_map('trim', explode(',', (string) ($this->client['scopes'] ?? '')));

        return in_array($scope, $scopes, true);
    }
}
