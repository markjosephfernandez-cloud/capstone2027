<?php
namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\RolePolicy;
use App\Services\VisualMediaAnalysisService;

class VisualAnalysisController extends BaseController
{
    public function analyze()
    {
        $actor = session()->get();
        if (($actor['isLoggedIn'] ?? false) !== true) return $this->response->setStatusCode(401)->setJSON(['message'=>'Sign in to analyze images.']);
        if (!RolePolicy::allows($actor, 'files.manage')) return $this->response->setStatusCode(403)->setJSON(['message'=>'Image analysis is restricted to Creative Staff.']);
        $id = filter_var($this->request->getPost('file_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$id) return $this->response->setStatusCode(422)->setJSON(['message'=>'Select a valid image.']);
        $file = db_connect()->table('files')->where('id', $id)->where('is_deleted', 0)->get()->getRowArray();
        if (!$file) return $this->response->setStatusCode(404)->setJSON(['message'=>'Image unavailable.']);
        session()->close();
        @set_time_limit(180);
        try {
            $result = (new VisualMediaAnalysisService())->analyzeAndStore($file);
            return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['ok'=>true, 'result'=>$result]);
        } catch (\Throwable $error) {
            log_message('error', 'Visual analysis failed for {id}: {message}', ['id'=>$id, 'message'=>$error->getMessage()]);
            if ($error->getCode() === 409) return $this->response->setStatusCode(409)->setJSON(['message'=>$error->getMessage()]);
            return $this->response->setStatusCode(422)->setJSON(['message'=>'Analysis could not finish. Check that the image is accessible and the database migrations are applied, then retry.']);
        }
    }
}
