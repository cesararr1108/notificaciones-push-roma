<?php

namespace Tests\Unit;

use App\Filters\ApiAuth;
use App\Libraries\Fcm\FcmResult;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class FcmResultTest extends CIUnitTestCase
{
    public function testUnregisteredEsTokenInvalido(): void
    {
        $body = json_encode(['error' => [
            'code'    => 404,
            'status'  => 'NOT_FOUND',
            'message' => 'Requested entity was not found.',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
        ]]);
        $r = FcmResult::fromResponse(404, $body);

        $this->assertSame('UNREGISTERED', $r->errorCode);
        $this->assertTrue($r->tokenInvalido());
        $this->assertFalse($r->reintentable());
    }

    public function testQuotaEsReintentable(): void
    {
        $r = FcmResult::fromResponse(429, '{"error":{"status":"RESOURCE_EXHAUSTED"}}');
        $this->assertTrue($r->reintentable());
        $this->assertFalse($r->tokenInvalido());
    }

    public function testInvalidArgumentSoloSiEsElToken(): void
    {
        $this->assertTrue(FcmResult::fromResponse(400, '{"error":{"status":"INVALID_ARGUMENT","message":"The registration token is not a valid FCM registration token"}}')->tokenInvalido());
        $this->assertFalse(FcmResult::fromResponse(400, '{"error":{"status":"INVALID_ARGUMENT","message":"Invalid JSON payload"}}')->tokenInvalido());
    }

    public function testIpPermitida(): void
    {
        $this->assertTrue(ApiAuth::ipPermitida('1.2.3.4', null));
        $this->assertTrue(ApiAuth::ipPermitida('10.1.2.77', '192.168.0.1, 10.1.2.0/24'));
        $this->assertFalse(ApiAuth::ipPermitida('10.1.3.1', '10.1.2.0/24'));
        $this->assertTrue(ApiAuth::ipPermitida('10.1.3.1', '10.0.0.0/8'));
        $this->assertTrue(ApiAuth::ipPermitida('172.16.5.5', '172.16.0.0/12'));
        $this->assertFalse(ApiAuth::ipPermitida('172.32.0.1', '172.16.0.0/12'));
        $this->assertTrue(ApiAuth::ipPermitida('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse(ApiAuth::ipPermitida('1.2.3.4', '1.2.3.5'));
    }
}
