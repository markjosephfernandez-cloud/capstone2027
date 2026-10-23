<?php

use App\Services\MisEmployeeService;
use App\Services\MisTokenService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Mis;

final class MisEmployeeServiceTest extends CIUnitTestCase
{
    private function lookup(array $rows, int $status = 200): MisEmployeeService
    {
        $config = new Mis();
        $config->baseURL = 'https://mis.example.test/api';
        $tokens = $this->createMock(MisTokenService::class);
        $tokens->method('issue')->willReturn(['access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_at' => '2030-01-01']);
        $client = $this->createMock(CURLRequest::class);
        $client->expects($this->once())->method('get')->with(
            'https://mis.example.test/api/v1/employees/210428',
            $this->callback(fn ($options) => $options['headers']['Authorization'] === 'Bearer test-access'
                && $options['verify'] === true && $options['allow_redirects'] === false),
        )->willReturn((new Response(new App()))->setStatusCode($status)->setJSON(['data' => ['data' => $rows]]));
        return new MisEmployeeService($config, $tokens, $client);
    }

    public function testReturnsOnlyProfileFieldsForExactIdentity(): void
    {
        $employee = $this->lookup([['employee_id' => '210428', 'first_name' => 'Juliet', 'last_name' => 'Licaros', 'role' => 'admin', 'password' => 'not-returned']])->find(210428);
        $this->assertSame('Juliet', $employee['first_name']);
        $this->assertArrayNotHasKey('role', $employee);
        $this->assertArrayNotHasKey('password', $employee);
    }

    public function testEmptyResultIsNotAnEmployee(): void
    {
        $this->assertNull($this->lookup([])->find(210428));
    }

    public function testNotFoundIsNotAnEmployee(): void
    {
        $this->assertNull($this->lookup([], 404)->find(210428));
    }

    public function testRejectsOtherEmployee(): void
    {
        $service = $this->lookup([['employee_id' => '999']]);
        $this->expectExceptionMessage('MIS returned an unexpected employee identity.');
        $service->find(210428);
    }

    public function testRejectsAmbiguousResult(): void
    {
        $service = $this->lookup([['employee_id' => '210428'], ['employee_id' => '999']]);
        $this->expectExceptionMessage('MIS returned an unexpected employee identity.');
        $service->find(210428);
    }

    public function testUpstreamFailureIsNotAnEmployee(): void
    {
        $service = $this->lookup([], 503);
        $this->expectExceptionMessage('MIS employee request failed (HTTP 503).');
        $service->find(210428);
    }
}
