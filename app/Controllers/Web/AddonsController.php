<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\RolePolicy;
use App\Models\FileModel;
use RuntimeException;

class AddonsController extends BaseController
{
    public function passports()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! RolePolicy::allows(session()->get(), 'ai.process')) return redirect()->to(site_url('workspace/file-management'))->with('error', 'Metadata Passports are restricted to Creative Staff.');
        $db = db_connect();
        if (! $db->tableExists('passports')) {
            return $this->missingAddon('passports', 'Metadata Passports');
        }

        $passports = $db->table('passports p')
            ->select('p.*, f.id AS file_id, f.display_title, f.filetype')
            ->join('files f', 'f.id = p.media_id', 'left')
            ->orderBy('p.updated_at', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();
        $summary = [
            'total'        => (int) $db->table('passports')->countAllResults(),
            'ai_flags' => (int) $db->table('passports')->where('needs_review', 1)->countAllResults(),
        ];

        return view('addons/passports', $this->pageData('passports', 'Metadata Passports', [
            'passports' => $passports,
            'summary' => $summary,
            'isAdmin' => RolePolicy::isCreativeAdmin(session()->get()),
            'success' => session()->getFlashdata('success'),
            'error' => session()->getFlashdata('error'),
        ]));
    }

    public function watermarking()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) return redirect()->to(site_url('workspace/file-management'))->with('error', 'Watermarking is restricted to Creative Staff.');
        $db = db_connect();
        if (! $db->tableExists('watermark_settings') || ! $db->tableExists('file_watermarks')) {
            return $this->missingAddon('watermarking', 'Watermarking');
        }

        $settings = $db->table('watermark_settings')->orderBy('id', 'ASC')->get(1)->getRowArray() ?? [];
        $logos = service('watermarking')->availableLogos();
        $images   = $db->table('files f')
            ->select('f.id, f.display_title, f.filename, f.filetype, f.dateupload, fw.watermarked_path, fw.applied_at, fw.settings_snapshot')
            ->join('file_watermarks fw', 'fw.media_id = f.id', 'left')
            ->where('f.is_deleted', 0)
            ->whereIn('f.filetype', ['jpg', 'jpeg', 'png', 'webp'])
            ->orderBy('f.dateupload', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();
        foreach ($images as &$image) {
            $snapshot = json_decode((string) ($image['settings_snapshot'] ?? ''), true);
            $image['watermark_logo_code'] = is_array($snapshot) ? ($snapshot['logo_code'] ?? null) : null;
        }
        unset($image);

        return view('addons/watermarking', $this->pageData('watermarking', 'Watermarking', [
            'settings' => $settings,
            'logos'    => $logos,
            'defaultLogoCode' => service('watermarking')->settingsLogoCode($settings),
            'images'   => $images,
            'isAdmin'  => RolePolicy::isCreativeAdmin(session()->get()),
            'success'  => session()->getFlashdata('success'),
            'error'    => session()->getFlashdata('error'),
        ]));
    }

    public function updateWatermarking()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! RolePolicy::isCreativeAdmin($actor)) {
            return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Only administrators can change watermark settings.');
        }

        $db = db_connect();
        if (! $db->tableExists('watermark_settings')) {
            return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Watermark settings are not installed yet.');
        }

        $logoCode = strtoupper(trim((string) $this->request->getPost('logo_code')));
        $position = (string) $this->request->getPost('position');
        $opacity = filter_var($this->request->getPost('opacity'), FILTER_VALIDATE_INT);
        $resizePercent = filter_var($this->request->getPost('resize_percent'), FILTER_VALIDATE_INT);
        $positions = ['bottom_right', 'bottom_left', 'top_right', 'top_left', 'center'];
        $logoCodes = array_column(service('watermarking')->availableLogos(), 'code');
        if (! in_array($logoCode, $logoCodes, true) || ! in_array($position, $positions, true) || $opacity === false || $opacity < 30 || $opacity > 100 || $resizePercent === false || $resizePercent < 50 || $resizePercent > 200) {
            return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Check the selected logo, position, opacity, and scale values.');
        }

        $payload = [
            'enabled'        => $this->request->getPost('enabled') === '1' ? 1 : 0,
            'logo_path'      => $logoCode,
            'position'       => $position,
            'opacity'        => $opacity,
            'resize_percent' => $resizePercent,
            'updated_by'     => (string) ($actor['employee_id'] ?? ''),
        ];
        $existing = $db->table('watermark_settings')->orderBy('id', 'ASC')->get(1)->getRowArray();
        if ($existing === null) {
            $payload['id'] = 1;
            $db->table('watermark_settings')->insert($payload);
            $settingsId = (int) $db->insertID();
        } else {
            $settingsId = (int) $existing['id'];
            $db->table('watermark_settings')->where('id', $settingsId)->update($payload);
        }

        service('audit')->record($actor, $this->request, 'watermark', 'WATERMARK_SETTINGS_UPDATE', 'settings', $settingsId, 'Updated the automatic watermark to the ' . $logoCode . ' logo.');

        return redirect()->to(site_url('workspace/watermarking'))->with('success', 'Watermark settings updated. ' . $logoCode . ' is now the default logo.');
    }

    public function watermarkLogo(string $code)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        try {
            $path = service('watermarking')->logoPath($code);
        } catch (RuntimeException) {
            return $this->response->setStatusCode(404)->setBody('Logo not found.');
        }
        return $this->response->download($path, null, true)->setFileName(strtoupper($code) . '.png')->setContentType('image/png')->inline()->noCache();
    }

    public function rebuildDefaultWatermarks()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        $actor = session()->get();
        if (! RolePolicy::isCreativeAdmin($actor)) {
            return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Only administrators can rebuild automatic watermark copies.');
        }
        $files = (new FileModel())->where('is_deleted', 0)->whereIn('filetype', ['jpg', 'jpeg', 'png', 'webp'])->findAll(250);
        $rebuilt = 0;
        $failed = 0;
        foreach ($files as $file) {
            $result = service('watermarking')->applyDefaultToExisting($file, $actor);
            empty($result['applied']) ? ++$failed : ++$rebuilt;
        }
        service('audit')->record($actor, $this->request, 'watermark', 'DEFAULT_LOGO_REBUILD', 'settings', 1, 'Rebuilt ' . $rebuilt . ' default logo derivative(s); ' . $failed . ' failed.');
        return redirect()->to(site_url('workspace/watermarking'))->with($rebuilt > 0 ? 'success' : 'error', $rebuilt . ' image watermark(s) rebuilt with the current default logo.' . ($failed > 0 ? ' ' . $failed . ' could not be rebuilt.' : ''));
    }

    public function downloadWithLogo(int $fileId)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Your role is not allowed to create department-watermarked downloads.');
        }
        $file = (new FileModel())->where('is_deleted', 0)->find($fileId);
        if ($file === null) {
            return redirect()->to(site_url('workspace/watermarking'))->with('error', 'The selected image is unavailable.');
        }
        $logoCode = strtoupper(trim((string) $this->request->getPost('logo_code')));
        $result = service('watermarking')->createForDownload($file, $actor, $logoCode);
        if (empty($result['applied']) || empty($result['path'])) {
            return redirect()->to(site_url('workspace/watermarking'))->with('error', (string) ($result['reason'] ?? 'The watermarked download could not be created.'));
        }

        $path = (string) $result['path'];
        $downloadName = pathinfo((string) $file['filename'], PATHINFO_FILENAME) . '_' . strtolower($logoCode) . '_watermarked.' . strtolower((string) $file['filetype']);
        service('audit')->record($actor, $this->request, 'watermark', 'DEPARTMENT_LOGO_DOWNLOAD', 'file', $fileId, 'Downloaded "' . $file['filename'] . '" with the ' . $logoCode . ' department logo.');
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $this->response->download($path, null, true)->setFileName($downloadName)->noCache();
    }

    public function auditLogs()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! RolePolicy::isSuperAdmin(session()->get())) return redirect()->to(site_url('dashboard'))->with('error', 'Audit Logs require Super Admin access.');
        $db = db_connect();
        if (! $db->tableExists('audit_logs')) {
            return $this->missingAddon('audit-logs', 'Audit Logs');
        }

        $filterUser   = trim(mb_substr((string) $this->request->getGet('user'), 0, 100));
        $module = trim(mb_substr((string) $this->request->getGet('module'), 0, 50));
        $logs   = $db->table('audit_logs')->orderBy('created_at', 'DESC');
        if ($filterUser !== '') {
            $logs->groupStart()->like('user_name', $filterUser)->orLike('user_id', $filterUser)->groupEnd();
        }
        if ($module !== '') {
            $logs->where('module', $module);
        }
        $logs    = $logs->limit(200)->get()->getResultArray();
        $modules = $db->table('audit_logs')->select('module')->where('module IS NOT NULL', null, false)->groupBy('module')->orderBy('module')->get()->getResultArray();

        return view('addons/audit_logs', $this->pageData('audit-logs', 'Audit Logs', compact('logs', 'modules', 'filterUser', 'module')));
    }

    private function missingAddon(string $activePage, string $title)
    {
        return view('addons/missing', $this->pageData($activePage, $title, ['title' => $title]));
    }

    /** @return \CodeIgniter\HTTP\RedirectResponse|null */
    private function requireLogin(): ?\CodeIgniter\HTTP\RedirectResponse
    {
        return session()->get('isLoggedIn') === true ? null : redirect()->to(site_url('login'));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function pageData(string $activePage, string $title, array $data): array
    {
        helper('dashboard');

        return array_merge($data, ['user' => session()->get(), 'activePage' => $activePage, 'title' => $title]);
    }
}
