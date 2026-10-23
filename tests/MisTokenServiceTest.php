<?php

use App\Services\MisTokenService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Mis;

final class MisTokenServiceTest extends CIUnitTestCase
{
    private function config(): Mis
    {
        $config = new Mis();
        $config->apiKey = 'test-api-key';
        $config->baseURL = 'https://mis.example.test/api';
        return $config;
    }

    private function response(array|string $body, int $status = 200): Response
    {
        return (new Response(new App()))->setStatusCode($status)
            ->setBody(is_array($body) ? json_encode($body) : $body);
    }

    public function testInitialExchangeUsesGeneratedRefreshToken(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $calls = [];
        $client->expects($this->exactly(2))->method('post')->willReturnCallback(function ($url, $options) use (&$calls) {
            $calls[] = [$url, $options];
            return count($calls) === 1
                ? $this->response(['status' => 'success', 'data' => ['refresh_token' => 'initial-refresh']])
                : $this->response(['status' => 'success', 'data' => ['access_token' => 'access', 'refresh_token' => 'rotated', 'expires_at' => '2030-01-01T00:00:00Z']]);
        });
        $pair = (new MisTokenService($this->config(), $client))->issue();
        $this->assertSame('rotated', $pair['refresh_token']);
        $this->assertSame('https://mis.example.test/api/tokens/generate', $calls[0][0]);
        $this->assertArrayNotHasKey('Authorization', $calls[0][1]['headers']);
        $this->assertSame('https://mis.example.test/api/tokens/refresh', $calls[1][0]);
        $this->assertSame('Bearer initial-refresh', $calls[1][1]['headers']['Authorization']);
        $this->assertSame('test-api-key', $calls[1][1]['headers']['API-Key']);
        $this->assertFalse($calls[1][1]['allow_redirects']);
        $this->assertTrue($calls[1][1]['verify']);
    }

    public function testProviderErrorsDoNotExposeResponseBody(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->method('post')->willReturn($this->response('secret-provider-debug', 403));
        $this->expectExceptionMessage('MIS token request failed (HTTP 403).');
        (new MisTokenService($this->config(), $client))->generate();
    }

    public function testTransportErrorsDoNotExposeSecrets(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->method('post')->willThrowException(new RuntimeException('secret-api-key'));
        try {
            (new MisTokenService($this->config(), $client))->generate();
            $this->fail('Expected a connection error.');
        } catch (RuntimeException $e) {
            $this->assertSame('Unable to connect to MIS.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testRejectsMissingKeyWithoutNetworkCall(): void
    {
        $config = $this->config();
        $config->apiKey = '';
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->never())->method('post');
        $this->expectExceptionMessage('The MIS API key is not configured correctly.');
        (new MisTokenService($config, $client))->generate();
    }

    public function testRejectsInsecureEndpoint(): void
    {
        $config = $this->config();
        $config->baseURL = 'http://mis.example.test/api';
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->never())->method('post');
        $this->expectExceptionMessage('MIS requires an HTTPS API base URL.');
        (new MisTokenService($config, $client))->generate();
    }

    public function testRejectsMalformedJson(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->method('post')->willReturn($this->response('<html>unavailable</html>'));
        $this->expectExceptionMessage('MIS returned an invalid token response.');
        (new MisTokenService($this->config(), $client))->generate();
    }

    public function testRefreshRequiresRotatedToken(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->method('post')->willReturn($this->response(['access_token' => 'access', 'expires_at' => '2030-01-01T00:00:00Z']));
        $this->expectExceptionMessage('MIS returned an invalid refresh_token.');
        (new MisTokenService($this->config(), $client))->refresh('old-refresh');
    }

    public function testRefreshRejectsInvalidExpiry(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->method('post')->willReturn($this->response(['access_token' => 'access', 'refresh_token' => 'new', 'expires_at' => 'invalid']));
        $this->expectExceptionMessage('MIS returned an invalid token expiry.');
        (new MisTokenService($this->config(), $client))->refresh('old-refresh');
    }

    public function testRefreshRejectsHeaderInjection(): void
    {
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->never())->method('post');
        $this->expectExceptionMessage('A valid MIS refresh token is required.');
        (new MisTokenService($this->config(), $client))->refresh("token\r\nInjected: value");
    }
}
