<?php
namespace App\Controllers\Web;

use App\Libraries\RolePolicy;
use App\Services\ServerLibraryService;
use App\Services\ServerDistributionPicker;
use App\Services\ServerLibraryTransferService;
use RuntimeException;

class DistributionServerController extends \App\Controllers\BaseController
{
    protected function library(): ServerLibraryService
    {
        return new ServerLibraryService();
    }
    private function access()
    {
        if (session()->get('isLoggedIn') !== true) {
            return $this->response->setStatusCode(401)->setJSON(['message' => 'Sign in again to browse server files.']);
        }
        if (! RolePolicy::allows(session()->get(), 'files.assign') || ! RolePolicy::allows(session()->get(), 'files.view_all')) {
            return $this->response->setStatusCode(403)->setJSON(['message' => 'Your account cannot transmit server files.']);
        }
        return null;
    }
    public function browse()
    {
        if ($denied = $this->access()) {
            return $denied;
        }
        session()->close();
        try {
            $data = (new ServerDistributionPicker($this->library()))->browse((string)$this->request->getGet('folder'), (string)$this->request->getGet('q'), max(1,(int)$this->request->getGet('page')));
            return $this->response->noCache()->setJSON($data);
        } catch (RuntimeException $error) { return $this->response->noCache()->setStatusCode(422)->setJSON(['message'=>$error->getMessage()]); }
    }
    public function selection()
    {
        if ($denied = $this->access()) {
            return $denied;
        }
        $token = csrf_hash();
        session()->close();
        try {
            $data = (new ServerDistributionPicker($this->library()))->selection($this->request->getPost('server_file_ids') ?? [], $this->request->getPost('server_folder_ids') ?? []);
            return $this->response->noCache()->setJSON($data + ['csrfToken'=>$token]);
        } catch (RuntimeException $error) { return $this->response->noCache()->setStatusCode(422)->setJSON(['message'=>$error->getMessage(),'csrfToken'=>$token]); }
    }
    public function direct()
    {
        if ($denied = $this->access()) {
            return $denied;
        }
        try {
            $picker = new ServerDistributionPicker($this->library());
            $paths = $picker->paths($this->request->getPost('server_file_ids') ?? []);
            $folders = $picker->paths($this->request->getPost('server_folder_ids') ?? []);
            $department = trim((string)$this->request->getPost('department'));
            $delivery = (new ServerLibraryTransferService(db_connect(), $this->library(), service('storage')))->direct($paths, $folders, $department, session()->get());
            try { service('audit')->record(session()->get(), $this->request, 'distribution', 'DIRECT_SEND', 'media_request', (int)$delivery['request_id'], 'Sent ' . (int)$delivery['file_count'] . ' file(s) from Server Library.'); }
            catch (\Throwable $error) { log_message('error', 'Direct send saved, but audit recording failed: {message}', ['message'=>$error->getMessage()]); }
            return redirect()->to(site_url('workspace/distribution?tab=direct&request='.(int)$delivery['request_id']))->with('success', 'Server files sent for department review.');
        } catch (\Throwable $error) {
            log_message('error', 'Server direct send failed: {message}', ['message'=>$error->getMessage()]);
            return redirect()->to(site_url('workspace/distribution?tab=direct'))->with('error', $error instanceof RuntimeException ? $error->getMessage() : 'The server files could not be sent. Check the connection and try again.');
        }
    }

    public function transmit(int $requestId)
    {
        if ($denied = $this->access()) {
            return $denied;
        }
        try {
            $picker = new ServerDistributionPicker($this->library());
            $paths = $picker->paths($this->request->getPost('server_file_ids') ?? []);
            $folders = $picker->paths($this->request->getPost('server_folder_ids') ?? []);
            $delivery = (new ServerLibraryTransferService(db_connect(), $this->library(), service('storage')))->transmit($paths, $folders, $requestId, session()->get());
            try { service('audit')->record(session()->get(), $this->request, 'distribution', 'TRANSMITTED', 'media_request', $requestId, 'Transmitted ' . (int)$delivery['file_count'] . ' file(s) from Server Library.'); }
            catch (\Throwable $auditError) { log_message('error', 'Server transmission saved, but audit recording failed: {message}', ['message'=>$auditError->getMessage()]); }
            return redirect()->to(site_url('workspace/distribution?request='.$requestId))->with('success', 'Server files transmitted for department review.');
        } catch (\Throwable $error) {
            log_message('error', 'Server transmission failed: {message}', ['message'=>$error->getMessage()]);
            return redirect()->to(site_url('workspace/distribution?request='.$requestId))->with('error', $error instanceof RuntimeException ? $error->getMessage() : 'The server files could not be transmitted. Check the connection and try again.');
        }
    }
}
