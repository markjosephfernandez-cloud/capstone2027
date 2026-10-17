<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\RolePolicy;

class PassportController extends BaseController
{
    private function scanAccess()
    {
        if (session()->get('isLoggedIn') !== true) return $this->scanResponse(['ok'=>false, 'message'=>'Sign in again to scan image metadata.'], 401);
        if (!RolePolicy::isCreativeAdmin(session()->get())) return $this->scanResponse(['ok'=>false, 'message'=>'Only administrators can generate missing passports.'], 403);
        return null;
    }

    private function scanResponse(array $data, int $status = 200)
    {
        return $this->response->noCache()->setStatusCode($status)->setJSON($data + ['csrfToken'=>csrf_hash(), 'csrfTokenName'=>csrf_token()]);
    }

    public function scanToken()
    {
        if ($denied = $this->scanAccess()) return $denied;
        return $this->scanResponse(['ok'=>true]);
    }

    public function browseScan()
    {
        if ($denied = $this->scanAccess()) return $denied;
        session()->close();
        try {
            $folder = (string) $this->request->getGet('folder');
            return $this->scanResponse(service('serverScan')->browse($folder, mb_substr(trim((string) $this->request->getGet('q')), 0, 120), (int) $this->request->getGet('page')));
        } catch (\Throwable $error) {
            return $this->scanResponse(['ok'=>false, 'message'=>$error instanceof \RuntimeException ? $error->getMessage() : 'The image library is unavailable. Please retry.'], 422);
        }
    }

    public function scanImage()
    {
        if ($denied = $this->scanAccess()) return $denied;
        $actor = session()->get();
        session()->close();
        try {
            $input = $this->request->getPost('file_path');
            if (!is_string($input) || $input === '') throw new \RuntimeException('Select a valid image.');
            $fileId = service('serverScan')->passportFile($input, (int) ($actor['employee_id'] ?? 0));
            $result = service('passports')->generateMissingImage($fileId, $actor);
            if ($result['status'] === 'complete') {
                try { service('audit')->record($actor, $this->request, 'metadata', 'PASSPORT_GENERATED', 'file', $fileId, 'Scanned image metadata and generated its missing passport.'); }
                catch (\Throwable $error) { log_message('error', 'Passport scan audit: {error}', ['error'=>$error->getMessage()]); }
            }
            return $this->scanResponse(['ok'=>true] + $result);
        } catch (\Throwable $error) {
            return $this->scanResponse(['ok'=>false, 'message'=>$error instanceof \RuntimeException ? $error->getMessage() : 'Metadata could not be scanned. Please retry.'], 422);
        }
    }

    public function backfill()
    {
        if (session()->get('isLoggedIn') !== true) {
            return redirect()->to(site_url('login'));
        }
        $actor = session()->get();
        if (! RolePolicy::isCreativeAdmin($actor)) {
            return redirect()->to(site_url('workspace/passports'))->with('error', 'Only administrators can generate missing passports.');
        }

        $created = service('passports')->backfillMissing($actor);
        service('audit')->record($actor, $this->request, 'metadata', 'PASSPORT_BACKFILL', 'passport', null, 'Generated ' . $created . ' missing metadata passport(s).');

        return redirect()->to(site_url('workspace/passports'))->with('success', $created === 0 ? 'All active media files already have metadata passports.' : 'Generated ' . $created . ' missing metadata passport(s).');
    }

    public function reanalyze()
    {
        if (session()->get('isLoggedIn') !== true) {
            return redirect()->to(site_url('login'));
        }
        $actor = session()->get();
        if (! RolePolicy::isCreativeAdmin($actor)) {
            return redirect()->to(site_url('workspace/passports'))->with('error', 'Only administrators can run AI analysis for existing media.');
        }

        $result = service('passports')->reanalyzeMissingAi($actor, 20);
        service('audit')->record($actor, $this->request, 'metadata', 'AI_REANALYZE_BATCH', 'passport', null, 'Re-analyzed ' . $result['processed'] . ' existing image passport(s); ' . $result['classified'] . ' received an AI confidence score.');

        if ($result['processed'] === 0) {
            return redirect()->to(site_url('workspace/passports'))->with('success', 'No active image passports are waiting for AI analysis.');
        }

        $message = $result['classified'] . ' image' . ($result['classified'] === 1 ? '' : 's') . ' received AI confidence and auto tags.';
        if ($result['unavailable'] > 0) {
            $message .= ' ' . $result['unavailable'] . ' image' . ($result['unavailable'] === 1 ? ' was' : 's were') . ' not classified. Confirm the Greyhound Hub 2.0 AI service is running, then try again.';
        }

        return redirect()->to(site_url('workspace/passports'))->with('success', $message);
    }
}
