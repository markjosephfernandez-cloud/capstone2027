<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;

class FeatureController extends BaseController
{
    /** @var array<string, array{title: string, legacy: string}> */
    private array $features = [
        'file-management' => ['title' => 'File Management', 'legacy' => 'directory-listing.php'],
        'recent'          => ['title' => 'Recent Files', 'legacy' => 'recent.php'],
        'ifound'          => ['title' => 'iFound AI', 'legacy' => 'ifound.php'],
        'trash'           => ['title' => 'Trash', 'legacy' => 'trash.php'],
        'passports'       => ['title' => 'Metadata Passports', 'legacy' => 'passports.php'],
        'watermarking'    => ['title' => 'Watermarking', 'legacy' => 'watermark_settings.php'],
        'audit-logs'      => ['title' => 'Audit Logs', 'legacy' => 'audit_logs.php'],
    ];

    public function show(string $feature)
    {
        if (session()->get('isLoggedIn') !== true) {
            return redirect()->to(site_url('login'));
        }
        if (! isset($this->features[$feature])) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('feature_pending', [
            'user'       => session()->get(),
            'activePage' => $feature,
            'feature'    => $this->features[$feature],
        ]);
    }
}
