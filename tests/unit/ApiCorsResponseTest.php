<?php

use App\Services\TokenService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApiCorsResponseTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const ORIGIN = 'http://localhost:8082';
    private mixed $previousOrigins;

    protected function setUp(): void
    {
        Services::reset();
        parent::setUp();
        $this->previousOrigins = $_ENV['greyhound.corsOrigins'] ?? null;
        $_ENV['greyhound.corsOrigins'] = self::ORIGIN;
        $tokens = $this->createMock(TokenService::class);
        $tokens->method('authenticateBearer')->willReturn(null);
        Services::injectMock('tokenService', $tokens);
    }

    protected function tearDown(): void
    {
        if ($this->previousOrigins === null) unset($_ENV['greyhound.corsOrigins']);
        else $_ENV['greyhound.corsOrigins'] = $this->previousOrigins;
        Services::reset();
        parent::tearDown();
    }

    public static function protectedEndpoints(): array
    {
        return [['api/v1/auth/me'], ['api/v1/mobile/profile'], ['api/v1/mobile/media/26087/engagement']];
    }

    #[DataProvider('protectedEndpoints')]
    public function testAuthenticationRejectionsAreReadableByAllowedOrigins(string $path): void
    {
        $result = $this->withHeaders(['Origin'=>self::ORIGIN, 'Authorization'=>'Bearer expired-test-token'])->get($path);
        $result->assertStatus(401);
        $result->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
        $result->assertJSONFragment(['error'=>'unauthenticated']);
    }

    public function testUnapprovedOriginsRemainBlocked(): void
    {
        $result = $this->withHeaders(['Origin'=>'https://unapproved.example'])->get('api/v1/auth/me');
        $result->assertStatus(401);
        $this->assertSame('', $result->response()->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testPreflightDoesNotRequireAuthentication(): void
    {
        $result = $this->withHeaders([
            'Origin'=>self::ORIGIN,
            'Access-Control-Request-Method'=>'GET',
            'Access-Control-Request-Headers'=>'authorization',
        ])->call('OPTIONS', 'api/v1/mobile/profile');
        $result->assertStatus(204);
        $result->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
        $result->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
    }

    public function testWebResponsesDoNotReceiveApiCorsHeaders(): void
    {
        $result = $this->withHeaders(['Origin'=>self::ORIGIN])->get('login');
        $result->assertStatus(200);
        $this->assertSame('', $result->response()->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
