<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class NotificationViewTest extends CIUnitTestCase
{
    public function testUnreadNotificationsProvideReadAllAction(): void
    {
        $html = view('notifications/index', [
            'user' => ['employee_id' => 0, 'name' => 'Test Admin', 'role' => 'admin'],
            'activePage' => 'notifications',
            'title' => 'Notifications',
            'unreadCount' => 1,
            'notifications' => [[
                'id' => 42,
                'title' => 'Metadata passport generated',
                'message' => 'A metadata passport was generated.',
                'link' => site_url('workspace/passports'),
                'is_read' => 0,
                'created_at' => '2026-08-13 19:06:00',
            ]],
        ]);

        $this->assertStringContainsString('action="' . site_url('notifications/read-all') . '"', $html);
        $this->assertStringContainsString('bi-check2-all', $html);
        $this->assertStringContainsString('>Read all</button>', $html);
    }

    public function testNotificationDropdownUsesViewportBoundedScrollableLayout(): void
    {
        $css = file_get_contents(FCPATH . 'assets/css/style.css');

        $this->assertIsString($css);
        $this->assertStringContainsString('width: min(390px, calc(100vw - 16px));', $css);
        $this->assertStringContainsString('max-height: calc(100dvh - 76px);', $css);
        $this->assertStringContainsString('.header-nav .notifications .notification-scroll', $css);
        $this->assertStringContainsString('overflow-y: auto;', $css);
    }
}
