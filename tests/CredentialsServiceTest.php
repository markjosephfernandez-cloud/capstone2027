<?php

use App\Exceptions\MisAuthenticationUnavailable;
use App\Models\UserModel;
use App\Services\CredentialsService;
use App\Services\MisLoginService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Mis;

final class CredentialsServiceTest extends CIUnitTestCase
{
    private function user(): array
    {
        return ['employee_id' => 210428, 'name' => 'Local Name', 'department' => 'Local Department', 'role' => 'admin', 'is_active' => 1, 'password_hash' => password_hash('local-password', PASSWORD_DEFAULT)];
    }

    private function config(bool $enabled = true): Mis
    {
        $config = new Mis();
        $config->loginEnabled = $enabled;
        return $config;
    }

    public function testMisSuccessPreservesLocalPermissionsAndDoesNotSavePassword(): void
    {
        $user = $this->user();
        $users = $this->createMock(UserModel::class);
        $users->method('find')->with(210428)->willReturn($user);
        $users->expects($this->never())->method('update');
        $login = $this->createMock(MisLoginService::class);
        $login->expects($this->once())->method('verify')->with(210428, 'mis-password')->willReturn(true);
        $this->assertSame($user, (new CredentialsService($users, $this->config(), $login))->attempt(210428, 'mis-password'));
    }

    public function testMisRejectionCannotFallBackToMatchingLocalPassword(): void
    {
        $users = $this->createMock(UserModel::class);
        $users->method('find')->willReturn($this->user());
        $users->expects($this->never())->method('update');
        $login = $this->createMock(MisLoginService::class);
        $login->method('verify')->willReturn(false);
        $this->assertNull((new CredentialsService($users, $this->config(), $login))->attempt(210428, 'local-password'));
    }

    public function testUnavailableMisCannotFallBackToLocalPassword(): void
    {
        $users = $this->createMock(UserModel::class);
        $users->method('find')->willReturn($this->user());
        $login = $this->createMock(MisLoginService::class);
        $login->method('verify')->willThrowException(new MisAuthenticationUnavailable());
        $this->expectException(MisAuthenticationUnavailable::class);
        (new CredentialsService($users, $this->config(), $login))->attempt(210428, 'local-password');
    }

    public function testDisabledUserCannotSignIn(): void
    {
        $users = $this->createMock(UserModel::class);
        $users->method('find')->willReturn(array_replace($this->user(), ['is_active' => 0]));
        $login = $this->createMock(MisLoginService::class);
        $login->expects($this->never())->method('verify');
        $this->assertNull((new CredentialsService($users, $this->config(), $login))->attempt(210428, 'mis-password'));
    }

    public function testMissingLocalAccountIsNotAutomaticallyProvisioned(): void
    {
        $users = $this->createMock(UserModel::class);
        $users->method('find')->willReturn(null);
        $users->expects($this->never())->method('insert');
        $login = $this->createMock(MisLoginService::class);
        $login->expects($this->never())->method('verify');
        $this->assertNull((new CredentialsService($users, $this->config(), $login))->attempt(210428, 'mis-password'));
    }

    public function testLocalModeStillChecksLocalPassword(): void
    {
        $user = $this->user();
        $users = $this->createMock(UserModel::class);
        $users->method('find')->willReturn($user);
        $login = $this->createMock(MisLoginService::class);
        $login->expects($this->never())->method('verify');
        $service = new CredentialsService($users, $this->config(false), $login);
        $this->assertSame($user, $service->attempt(210428, 'local-password'));
        $this->assertNull($service->attempt(210428, 'wrong-password'));
    }
}
