<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\RedirectResponse;

class PrivacyController extends BaseController
{
    public function accept(): RedirectResponse
    {
        if (session()->get('isLoggedIn') !== true) {
            return redirect()->to(site_url('login'));
        }

        $actor = session()->get();
        service('consent')->accept($actor, $this->request);
        service('audit')->record($actor, $this->request, 'privacy', 'CONSENT_ACCEPTED', 'privacy_consent', null, 'Accepted the Greyhound Hub data privacy and media-use notice.');

        return redirect()->to(site_url('dashboard'))->with('success', 'Your data privacy and media-use acknowledgement has been recorded.');
    }
}
