<?php

use App\Exceptions\MisAuthenticationUnavailable;
use App\Services\MisLoginService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Mis;

final class MisLoginServiceTest extends CIUnitTestCase
{
    private function service(array|string $payload, int $status = 200): MisLoginService
    {
        $config = new Mis();
        $config->apiKey = 'test-key';
        $config->baseURL = 'https://mis.example.test/api';
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())->method('post')->with(
            'https://mis.example.test/api/v1/employees/login',
            $this->callback(fn ($options) => $options['json'] === ['employee_id' => '210428', 'password' => 'test-password']
                && $options['headers']['API-Key'] === 'test-key'
                && !isset($options['headers']['Authorization'])
                && $options['verify'] === true && $options['allow_redirects'] === false),
        )->willReturn((new Response(new App()))->setStatusCode($status)
            ->setBody(is_array($payload) ? json_encode($payload) : $payload));
        return new MisLoginService($config, $client);
    }

    public function testAcceptsExplicitSuccessWithMatchingIdentity(): void
    {
        $this->assertTrue($this->service(['status' => 'success', 'data' => ['employee_id' => '210428']])->verify(210428, 'test-password'));
    }

    public function testRejectsInvalidCredentials(): void
    {
        $this->assertFalse($this->service(['status' => 'error', 'message' => 'Invalid credentials.'], 403)->verify(210428, 'test-password'));
    }

    public function testDoesNotTreatHttp200AloneAsSuccess(): void
    {
        $service = $this->service(['message' => 'Success']);
        $this->expectException(MisAuthenticationUnavailable::class);
        $service->verify(210428, 'test-password');
    }

    public function testRejectsUnexpectedEmployee(): void
    {
        $service = $this->service(['status' => 'success', 'data' => ['employee_id' => '999']]);
        $this->expectException(MisAuthenticationUnavailable::class);
        $service->verify(210428, 'test-password');
    }

    public function testRejectsMissingIdentity(): void
    {
        $service = $this->service(['status' => 'success']);
        $this->expectException(MisAuthenticationUnavailable::class);
        $service->verify(210428, 'test-password');
    }

    public function testUnavailableForMalformedResponse(): void
    {
        $service = $this->service('<html>error</html>');
        $this->expectException(MisAuthenticationUnavailable::class);
        $service->verify(210428, 'test-password');
    }

    public function testServerFailureCannotAuthenticate(): void
    {
        $service = $this->service(['status' => 'success', 'data' => ['employee_id' => '210428']], 500);
        $this->expectException(MisAuthenticationUnavailable::class);
        $service->verify(210428, 'test-password');
    }

    public function testNetworkExceptionsDoNotExposeSecrets(): void
    {
        $config = new Mis();
        $config->apiKey = 'test-key';
        $client = $this->createMock(CURLRequest::class);
        $client->method('post')->willThrowException(new RuntimeException('password=test-password'));
        try {
            (new MisLoginService($config, $client))->verify(210428, 'test-password');
            $this->fail('Expected sanitized exception.');
        } catch (MisAuthenticationUnavailable $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('test-password', $e->getMessage());
        }
    }

    public function testMissingConfigurationMakesNoNetworkRequest(): void
    {
        $config = new Mis();
        $config->apiKey = '';
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->never())->method('post');
        $this->expectException(MisAuthenticationUnavailable::class);
        (new MisLoginService($config, $client))->verify(210428, 'test-password');
    }
}
