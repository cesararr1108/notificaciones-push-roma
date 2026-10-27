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

        $this->registrar(['usuario' => 'ana', 'email' => 'ana@example.com', 'oficina' => 'OF01', 'rol' => 'SUPERVISOR', 'token' => 'tok_ana_' . str_repeat('a', 30)]);
        $this->registrar(['usuario' => 'luis', 'email' => 'luis@example.com', 'oficina' => 'OF01', 'rol' => 'AGENTE', 'token' => 'tok_luis_' . str_repeat('l', 30)]);
        $this->registrar(['usuario' => 'eva', 'email' => 'eva@example.com', 'oficina' => 'OF02', 'rol' => 'SUPERVISOR', 'token' => 'tok_eva_' . str_repeat('e', 30)]);
        // Otra organización: nunca debe recibir mensajes de ROMA.
        $this->registrar(['organizacion' => 'ACME', 'usuario' => 'ana', 'email' => 'ana@example.com', 'token' => 'tok_acme_' . str_repeat('z', 30)], $this->keyGlobal);
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

    public function testEnvioPorEmail(): void
    {
        $this->enviar(['emails' => ['ANA@example.com', 'eva@example.com']])->assertStatus(200);
        $this->assertCount(2, $this->fcm->enviados);
        $this->assertNotContains('tok_acme_' . str_repeat('z', 30), $this->tokensEnviados());
    }

    public function testEmailInvalido(): void
    {
        $this->enviar(['emails' => ['no-es-correo']])->assertStatus(400);
    }

    public function testImagenSeEnviaEnTodasLasPlataformas(): void
    {
        $this->api('POST', 'api/v1/notificaciones', [
            'destinatarios' => ['emails' => ['ana@example.com']],
            'notificacion'  => ['titulo' => 'x', 'cuerpo' => 'y', 'imagen' => 'https://cdn.example.com/a.png'],
        ], $this->keyRoma)->assertStatus(200);

        $msg = $this->fcm->enviados[0]['message'];
        $this->assertSame('https://cdn.example.com/a.png', $msg['notification']['image']);
        $this->assertSame('https://cdn.example.com/a.png', $msg['webpush']['notification']['image']);
        $this->assertSame('https://cdn.example.com/a.png', $msg['apns']['fcm_options']['image']);
    }

    public function testSonidoPersonalizado(): void
    {
        $this->api('POST', 'api/v1/notificaciones', [
            'destinatarios' => ['emails' => ['ana@example.com']],
            'notificacion'  => [
                'titulo'        => 'x',
                'cuerpo'        => 'y',
                'imagen'        => 'https://cdn.example.com/a.png',
                'sonido'        => 'notificacion',
                'sonido_url'    => 'https://cdn.example.com/notificacion.mp3',
                'canal_android' => 'notificaciones',
            ],
        ], $this->keyRoma)->assertStatus(200);

        $msg = $this->fcm->enviados[0]['message'];
        $this->assertSame('notificacion', $msg['android']['notification']['sound']);
        $this->assertSame('notificaciones', $msg['android']['notification']['channel_id']);
        $this->assertSame('notificacion.caf', $msg['apns']['payload']['aps']['sound']);
        $this->assertSame(1, $msg['apns']['payload']['aps']['mutable-content']);
        $this->assertSame('https://cdn.example.com/notificacion.mp3', $msg['data']['sonido_url']);
    }

    public function testSonidoPorDefectoDesdeConfig(): void
    {
        $config                         = config(\Config\Push::class);
        $config->sonidoPorDefecto       = 'notificacion';
        $config->sonidoUrlPorDefecto    = 'https://cdn.example.com/n.mp3';
        $config->canalAndroidPorDefecto = 'general';

        $this->enviar(['emails' => ['ana@example.com']])->assertStatus(200);

        $msg = $this->fcm->enviados[0]['message'];
        $this->assertSame('notificacion', $msg['android']['notification']['sound']);
        $this->assertSame('general', $msg['android']['notification']['channel_id']);
        $this->assertSame('https://cdn.example.com/n.mp3', $msg['data']['sonido_url']);
    }

    public function testSonidoInvalido(): void
    {
        $this->api('POST', 'api/v1/notificaciones', [
            'destinatarios' => ['emails' => ['ana@example.com']],
            'notificacion'  => ['titulo' => 'x', 'cuerpo' => 'y', 'sonido' => '../../etc/passwd', 'sonido_url' => 'http://inseguro.com/a.mp3'],
        ], $this->keyRoma)->assertStatus(400);
    }

    public function testLoteMensajesPersonalizados(): void
    {
        $res = $this->api('POST', 'api/v1/notificaciones/lote', ['mensajes' => [
            [
                'referencia'    => 'msg-ana',
                'destinatarios' => ['emails' => ['ana@example.com']],
                'notificacion'  => ['titulo' => 'Hola Ana', 'cuerpo' => 'Tu pedido salió', 'imagen' => 'https://cdn.example.com/ana.png'],
            ],
            [
                'referencia'    => 'msg-of02',
                'destinatarios' => ['oficinas' => ['OF02']],
                'notificacion'  => ['titulo' => 'Oficina 2', 'cuerpo' => 'Reunión a las 10'],
            ],
            [
                'destinatarios' => ['todos' => true],
                'notificacion'  => ['titulo' => 'Todos', 'cuerpo' => 'Aviso general'],
            ],
        ]], $this->keyRoma);

        $res->assertStatus(200);
        $body = $this->json($res);
        $this->assertSame(3, $body['resumen']['mensajes']);
        $this->assertSame(5, $body['resumen']['exitosos']);
        $this->assertSame('msg-ana', $body['data'][0]['referencia']);
        $this->assertSame(1, $body['data'][1]['exitosos']);

        $porTitulo = [];
        foreach ($this->fcm->enviados as $e) {
            $porTitulo[$e['message']['notification']['title']][] = $e['token'];
        }
        $this->assertSame(['tok_ana_' . str_repeat('a', 30)], $porTitulo['Hola Ana']);
        $this->assertSame('https://cdn.example.com/ana.png', $this->fcm->enviados[0]['message']['notification']['image']);
        $this->assertSame(['tok_eva_' . str_repeat('e', 30)], $porTitulo['Oficina 2']);
        $this->assertCount(3, $porTitulo['Todos']);
    }

    public function testLoteConUnMensajeInvalidoNoEnviaNada(): void
    {
        $res = $this->api('POST', 'api/v1/notificaciones/lote', ['mensajes' => [
            ['destinatarios' => ['emails' => ['ana@example.com']], 'notificacion' => ['titulo' => 'ok', 'cuerpo' => 'ok']],
            ['destinatarios' => ['emails' => ['ana@example.com']], 'notificacion' => ['titulo' => 'sin cuerpo']],
        ]], $this->keyRoma);

        $res->assertStatus(400);
        $this->assertArrayHasKey('mensajes.1.notificacion.cuerpo', $this->json($res)['messages']);
        $this->assertCount(0, $this->fcm->enviados);
    }

    public function testLoteIdempotente(): void
    {
        $body = ['mensajes' => [
            ['destinatarios' => ['emails' => ['ana@example.com']], 'notificacion' => ['titulo' => 'a', 'cuerpo' => 'a']],
            ['destinatarios' => ['emails' => ['eva@example.com']], 'notificacion' => ['titulo' => 'b', 'cuerpo' => 'b']],
        ]];
        $h = ['Idempotency-Key' => 'lote-000123'];
        $this->api('POST', 'api/v1/notificaciones/lote', $body, $this->keyRoma, $h)->assertStatus(200);
        $this->api('POST', 'api/v1/notificaciones/lote', $body, $this->keyRoma, $h)->assertStatus(200);

        $this->assertCount(2, $this->fcm->enviados);
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
        $this->dontSeeInDatabase('fcm_tokens', ['usuario' => 'luis']);
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
