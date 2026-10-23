<?php

namespace Tests\Feature;

use App\Libraries\Fcm\FcmResult;
use App\Models\NotificacionModel;

/**
 * @internal
 */
final class NotificacionesTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registrar(['usuario' => 'ana', 'oficina' => 'OF01', 'rol' => 'SUPERVISOR', 'token' => 'tok_ana_' . str_repeat('a', 30)]);
        $this->registrar(['usuario' => 'luis', 'oficina' => 'OF01', 'rol' => 'AGENTE', 'token' => 'tok_luis_' . str_repeat('l', 30)]);
        $this->registrar(['usuario' => 'eva', 'oficina' => 'OF02', 'rol' => 'SUPERVISOR', 'token' => 'tok_eva_' . str_repeat('e', 30)]);
        // Otra organización: nunca debe recibir mensajes de ROMA.
        $this->registrar(['organizacion' => 'ACME', 'usuario' => 'ana', 'token' => 'tok_acme_' . str_repeat('z', 30)], $this->keyGlobal);
    }

    private function enviar(array $destinatarios, array $extra = [], ?string $key = null, array $headers = [])
    {
        return $this->api('POST', 'api/v1/notificaciones', $extra + [
            'destinatarios' => $destinatarios,
            'notificacion'  => ['titulo' => 'Hola', 'cuerpo' => 'Mensaje de prueba', 'url' => 'https://roma.example.com/x'],
            'data'          => ['tipo' => 'aviso', 'id' => 15],
        ], $key ?? $this->keyRoma, $headers);
    }

    private function tokensEnviados(): array
    {
        $t = array_column($this->fcm->enviados, 'token');
        sort($t);

        return $t;
    }

    public function testEnvioPorUsuarios(): void
    {
        $res = $this->enviar(['usuarios' => ['ana']]);

        $res->assertStatus(200);
        $data = $this->json($res)['data'];
        $this->assertSame('completado', $data['estado']);
        $this->assertSame(1, $data['exitosos']);
        $this->assertSame(['tok_ana_' . str_repeat('a', 30)], $this->tokensEnviados());

        $msg = $this->fcm->enviados[0]['message'];
        $this->assertSame('Hola', $msg['notification']['title']);
        $this->assertSame('15', $msg['data']['id']);
        $this->assertSame('https://roma.example.com/x', $msg['webpush']['fcm_options']['link']);
    }

    public function testEnvioPorOficinaYRol(): void
    {
        $this->enviar(['oficinas' => ['of01'], 'roles' => ['supervisor']])->assertStatus(200);
        $this->assertCount(1, $this->fcm->enviados);
        $this->assertStringStartsWith('tok_ana_', $this->fcm->enviados[0]['token']);
    }

    public function testEnvioPorRolMasUsuario(): void
    {
        $this->enviar(['roles' => ['SUPERVISOR'], 'usuarios' => ['luis']])->assertStatus(200);
        $this->assertCount(3, $this->fcm->enviados);
    }

    public function testTodosNoCruzaOrganizaciones(): void
    {
        $this->enviar(['todos' => true])->assertStatus(200);
        $this->assertCount(3, $this->fcm->enviados);
        $this->assertNotContains('tok_acme_' . str_repeat('z', 30), $this->tokensEnviados());
    }

    public function testDestinatariosVaciosEsError(): void
    {
        $this->enviar([])->assertStatus(400);
        $this->enviar(['usuarios' => []])->assertStatus(400);
        $this->enviar(['oficinas' => ['DEMASIADO']])->assertStatus(400);
    }

    public function testUrlDebeSerHttps(): void
    {
        $res = $this->api('POST', 'api/v1/notificaciones', [
            'destinatarios' => ['todos' => true],
            'notificacion'  => ['titulo' => 'x', 'cuerpo' => 'y', 'url' => 'javascript:alert(1)'],
        ], $this->keyRoma);
        $res->assertStatus(400);
    }

    public function testDataConClaveReservada(): void
    {
        $this->enviar(['todos' => true], ['data' => ['from' => 'x']])->assertStatus(400);
    }

    public function testTokenInvalidoSeDaDeBaja(): void
    {
        $this->fcm->respuestas['tok_luis_' . str_repeat('l', 30)] = new FcmResult(false, 404, 'UNREGISTERED');

        $data = $this->json($this->enviar(['oficinas' => ['OF01']]))['data'];

        $this->assertSame(1, $data['exitosos']);
        $this->assertSame(1, $data['invalidos']);
        $this->seeInDatabase('fcm_tokens', ['usuario' => 'luis', 'activo' => 0, 'motivo_baja' => 'fcm_invalido']);
    }

    public function testIdempotencia(): void
    {
        $h = ['Idempotency-Key' => 'pedido-12345'];
        $a = $this->json($this->enviar(['usuarios' => ['ana']], [], null, $h));
        $b = $this->json($this->enviar(['usuarios' => ['ana']], [], null, $h));

        $this->assertSame($a['data']['id'], $b['data']['id']);
        $this->assertTrue($b['idempotente']);
        $this->assertCount(1, $this->fcm->enviados);
    }

    public function testEnvioAsincronoYWorker(): void
    {
        $res = $this->enviar(['todos' => true], ['asincrono' => true]);
        $res->assertStatus(202);
        $id = $this->json($res)['data']['id'];
        $this->assertCount(0, $this->fcm->enviados);

        $model = model(NotificacionModel::class);
        $job   = $model->reclamarSiguiente('test', 600);
        $this->assertNotNull($job);
        $this->assertNull($model->reclamarSiguiente('otro', 600), 'Un job no puede ser tomado por dos workers.');

        service('dispatcher')->procesar($job);

        $estado = $this->json($this->api('GET', 'api/v1/notificaciones/' . $id, null, $this->keyRoma))['data'];
        $this->assertSame('completado', $estado['estado']);
        $this->assertSame(3, $estado['exitosos']);
    }

    public function testEstadoNoVisibleDesdeOtraOrganizacion(): void
    {
        $id = $this->json($this->enviar(['usuarios' => ['ana']]))['data']['id'];

        $acme = model(\App\Models\ApiClientModel::class)->createClient('Acme', 'ACME', ['notificaciones'])[1];
        $this->api('GET', 'api/v1/notificaciones/' . $id, null, $acme)->assertStatus(404);
    }
}
