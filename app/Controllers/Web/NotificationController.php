<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

class NotificationController extends BaseController
{
    public function index(): string|RedirectResponse
    {
        if (session()->get('isLoggedIn') !== true) {
            return redirect()->to(site_url('login'));
        }

        $userId = (string) session()->get('employee_id');
        $unreadCount = db_connect()->table('notifications')
            ->where('recipient_id', $userId)
            ->where('is_read', 0)
            ->countAllResults();
        $notifications = db_connect()->table('notifications')
            ->where('recipient_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();

        return view('notifications/index', [
            'user' => session()->get(),
            'activePage' => 'notifications',
            'title' => 'Notifications',
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    public function read(int $id): RedirectResponse
    {
        if (session()->get('isLoggedIn') !== true) {
            return redirect()->to(site_url('login'));
        }
        $userId = (string) session()->get('employee_id');
        $notification = db_connect()->table('notifications')->where('id', $id)->where('recipient_id', $userId)->get()->getRowArray();
        if ($notification === null) {
            return redirect()->back()->with('error', 'The notification is unavailable.');
        }
        db_connect()->table('notifications')->where('id', $id)->update(['is_read' => 1]);
        $link = trim((string) ($notification['link'] ?? ''));
        return redirect()->to($link !== '' ? $link : site_url('dashboard'));
    }

    public function readAll(): RedirectResponse
    {
        if (session()->get('isLoggedIn') !== true) {
            return redirect()->to(site_url('login'));
        }

        db_connect()->table('notifications')
            ->where('recipient_id', (string) session()->get('employee_id'))
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return redirect()->back();
    }
}
