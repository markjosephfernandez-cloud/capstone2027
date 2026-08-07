<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\RolePolicy;

class PassportController extends BaseController
{
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
