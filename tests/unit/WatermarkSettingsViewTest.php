<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class WatermarkSettingsViewTest extends CIUnitTestCase
{
    public function testAutomaticToggleIsNotRenderedOnWatermarkPage(): void
    {
        $html = view('addons/watermarking', [
            'user' => ['employee_id' => 1, 'name' => 'Creative Admin', 'role' => 'admin'],
            'activePage' => 'watermarking',
            'title' => 'Watermarking',
            'settings' => ['enabled' => 1, 'position' => 'bottom_right', 'opacity' => 65, 'resize_percent' => 100],
            'logos' => [['code' => 'FU', 'label' => 'Foundation University']],
            'defaultLogoCode' => 'FU',
            'images' => [],
            'isAdmin' => true,
            'success' => null,
            'error' => null,
        ]);

        $this->assertStringNotContainsString('id="enabled"', $html);
        $this->assertStringNotContainsString('Apply logo automatically on Creative uploads', $html);
        $this->assertStringContainsString('Save default logo', $html);
    }
}
