<?php

namespace Tests\Feature;

use App\Libraries\NotificationDispatcher;
use App\Models\ApiClientModel;
use App\Models\FcmTokenModel;
use App\Models\NotificacionModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Push;
use Config\Services;
use Tests\Support\Fcm\FakeFcmClient;

abstract class ApiTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    protected FakeFcmClient $fcm;
    protected string $keyRoma;
    protected string $keyGlobal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fcm = new FakeFcmClient();
        Services::injectMock('fcm', $this->fcm);
        Services::injectMock('dispatcher', new NotificationDispatcher(
            $this->fcm,
            model(FcmTokenModel::class),
            model(NotificacionModel::class),
            config(Push::class),
        ));
        Services::injectMock('apiContext', new \App\Libraries\ApiContext());

        $clients         = model(ApiClientModel::class);
        $this->keyRoma   = $clients->createClient('Roma', 'ROMA', [ApiClientModel::SCOPE_TOKENS, ApiClientModel::SCOPE_SEND])[1];
        $this->keyGlobal = $clients->createClient('Global', null, [ApiClientModel::SCOPE_TOKENS, ApiClientModel::SCOPE_SEND])[1];
    }

    protected function api(string $method, string $path, ?array $body, string $key, array $headers = []): TestResponse
    {
        $req = $this->withHeaders(['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'] + $headers);
        if ($body !== null) {
            $req = $req->withBody(json_encode($body));
        }

        return $req->call($method, $path);
    }

    protected function registrar(array $override = [], ?string $key = null): TestResponse
    {
        static $n = 0;
        $n++;

        return $this->api('POST', 'api/v1/tokens', $override + [
            'oficina'        => 'OF01',
            'usuario'        => 'user' . $n,
            'nombre_usuario' => 'Usuario ' . $n,
            'email'          => "user{$n}@example.com",
            'rol'            => 'AGENTE',
            'token'          => 'tok_' . str_repeat('a', 30) . $n,
            'navegador'      => 'Chrome 130',
            'dispositivo'    => 'web',
        ], $key ?? $this->keyRoma);
    }

    protected function json(TestResponse $res): array
    {
        return json_decode($res->getJSON(), true);
    }
}
