<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class PassportViewTest extends CIUnitTestCase
{
    public function testPassportPageRendersWithSharedApplicationLayout(): void
    {
        $html = view('addons/passports', [
            'user'       => ['employee_id' => 0, 'name' => 'Test User', 'role' => 'admin'],
            'activePage' => 'passports',
            'title'      => 'Metadata Passports',
            'passports'  => [],
            'summary'    => ['total' => 0, 'ai_flags' => 0],
            'isAdmin'    => false,
            'success'    => null,
            'error'      => null,
        ]);

        $this->assertStringContainsString('<title>Metadata Passports</title>', $html);
        $this->assertStringContainsString('<main id="main" class="main">', $html);
        $this->assertStringContainsString('No passports have been generated yet.', $html);
    }
}
