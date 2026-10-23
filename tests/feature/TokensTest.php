<?php

namespace Tests\Feature;

use App\Models\ApiClientModel;

/**
 * @internal
 */
final class TokensTest extends ApiTestCase
{
    public function testRegistraToken(): void
    {
        $res = $this->registrar(['usuario' => 'jperez', 'oficina' => 'of01']);

        $res->assertStatus(201);
        $this->seeInDatabase('fcm_tokens', ['usuario' => 'jperez', 'organizacion' => 'ROMA', 'oficina' => 'OF01', 'activo' => 1]);
    }

    public function testReRegistrarMismoTokenActualizaYReasigna(): void
    {
        $token = 'tok_' . str_repeat('x', 40);
        $this->registrar(['usuario' => 'ana', 'token' => $token])->assertStatus(201);
        $this->registrar(['usuario' => 'luis', 'token' => $token])->assertStatus(200);

        $this->seeNumRecords(1, 'fcm_tokens', ['token_hash' => hash('sha256', $token)]);
        $this->seeInDatabase('fcm_tokens', ['usuario' => 'luis']);
    }

    public function testSinApiKey401(): void
    {
        $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody('{}')
            ->post('api/v1/tokens')
            ->assertStatus(401);
    }

    public function testApiKeyIncorrecta401(): void
    {
        $falsa = substr($this->keyRoma, 0, -4) . 'beef';
        $this->registrar([], $falsa)->assertStatus(401);
    }

    public function testBloqueoPorIntentosFallidos(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->registrar([], 'pk_000000000000.' . str_repeat('0', 64));
        }
        $this->registrar([])->assertStatus(429);
    }

    public function testNoPuedeRegistrarEnOtraOrganizacion(): void
    {
        $this->registrar(['organizacion' => 'OTRA'])->assertStatus(403);
    }

    public function testLlaveGlobalRequiereOrganizacion(): void
    {
        $this->registrar([], $this->keyGlobal)->assertStatus(400);
        $this->registrar(['organizacion' => 'ACME'], $this->keyGlobal)->assertStatus(201);
    }

    public function testValidaciones(): void
    {
        $res = $this->registrar(['oficina' => 'OFICINA', 'email' => 'no-es-email', 'token' => 'abc<script>']);
        $res->assertStatus(400);
        $errores = $this->json($res)['messages'];
        $this->assertArrayHasKey('oficina', $errores);
        $this->assertArrayHasKey('email', $errores);
        $this->assertArrayHasKey('token', $errores);
    }

    public function testScopeInsuficiente(): void
    {
        [, $key] = model(ApiClientModel::class)->createClient('Solo envio', 'ROMA', [ApiClientModel::SCOPE_SEND]);
        $this->registrar([], $key)->assertStatus(403);
    }

    public function testIpNoPermitida(): void
    {
        [, $key] = model(ApiClientModel::class)->createClient('IP', 'ROMA', [ApiClientModel::SCOPE_TOKENS], '10.1.2.0/24');
        $this->registrar([], $key)->assertStatus(403);
    }

    public function testRechazaContentTypeNoJson(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->keyRoma, 'Content-Type' => 'text/plain'])
            ->withBody('{}')
            ->post('api/v1/tokens')
            ->assertStatus(415);
    }

    public function testLimiteDeDispositivosPorUsuario(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->registrar(['usuario' => 'multi', 'token' => 'tok_multi_' . str_repeat('m', 20) . $i]);
        }
        $this->seeNumRecords(10, 'fcm_tokens', ['usuario' => 'multi', 'activo' => 1]);
        $this->seeNumRecords(2, 'fcm_tokens', ['usuario' => 'multi', 'activo' => 0]);
    }

    public function testEliminarToken(): void
    {
        $token = 'tok_' . str_repeat('d', 40);
        $this->registrar(['token' => $token]);

        $this->api('DELETE', 'api/v1/tokens', ['token' => $token], $this->keyRoma)->assertStatus(200);
        $this->seeInDatabase('fcm_tokens', ['token_hash' => hash('sha256', $token), 'activo' => 0]);
        $this->api('DELETE', 'api/v1/tokens', ['token' => $token], $this->keyRoma)->assertStatus(404);
    }
}
