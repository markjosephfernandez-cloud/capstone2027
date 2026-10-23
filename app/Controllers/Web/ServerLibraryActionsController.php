<?php

namespace App\Controllers\Web;

use App\Libraries\RolePolicy;
use App\Services\ServerLibraryPresentation as Presentation;
use App\Services\ServerLibraryEditor;
use RuntimeException;

/** Translates the Local Library form contract into confined server operations. */
class ServerLibraryActionsController extends ServerLibraryController
{
    public function item(string $token, string $action = 'preview')
    {
        if ($denied = $this->access()) {
            return $denied;
        }
        // media() releases the session lock before resolving or reading the NAS.
        // Do not repeat a blocking NAS check while holding that lock here.
        try { $path = Presentation::path($token); $this->library()->relative($path); }
        catch (RuntimeException $error) { return $this->response->setStatusCode(404)->setBody($error->getMessage()); }
        $query = $this->request->getGet();
        unset($query['duplicate'], $query['download']);
        $query['path'] = $path;
        if ($action === 'download') {
            $query['download'] = '1';
        }
        if ($action === 'video-preview') {
            $query['video_preview'] = '1';
        }
        $this->request->setGlobal('get', $query);
        return $this->media();
    }

    public function action(string $action, string $token = '')
    {
        if ($denied = $this->access()) {
            return $denied;
        }
        try {
            $post = $this->request->getPost();
            $paths = $token === '' ? $this->selectedPaths() : [Presentation::path($token)];
            $post['paths'] = $paths;
            $post['action'] = $action;
            $post['folder'] = (string)($this->request->getGet('folder') ?? $post['parent_id'] ?? ($paths ? dirname($paths[0]) : ''));
            if ($post['folder'] === '.') {
                $post['folder'] = '';
            }
            if ($action === 'tags') {
                if (! is_array($post['tags'] ?? null) || count(array_filter($post['tags'], 'is_string')) !== count($post['tags'])) {
                    throw new RuntimeException('Enter valid tags.');
                }
                $post['tags'] = implode(',', $post['tags']);
            }
            $this->request->setGlobal('post', $post);
            if ($action === 'download') {
                $this->request->setGlobal('get', ['paths'=>$paths]);
                return $this->downloadSelected();
            }
            return $action === 'post' ? $this->post() : $this->edit();
        } catch (RuntimeException $error) {
            return redirect()->to(site_url('workspace/server-library'))->with('error', $error->getMessage());
        }
    }

    public function folderSelection()
    {
        if ($denied = $this->access()) {
            return $denied;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) {
            return $this->response->setStatusCode(403);
        }
        $jsonMove = $this->request->getPost('action') === 'move' && $this->request->isAJAX()
            && str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        if ($jsonMove) {
            session()->close();
        }
        try {
            $action = $this->request->getPost('action');
            if (! in_array($action, ['move', 'trash'], true)) {
                throw new RuntimeException('Choose Move to or Trash.');
            }
            $paths = $this->request->getPost('paths');
            if (! is_array($paths) || ! $paths || count($paths) > 50) {
                throw new RuntimeException('Select between 1 and 50 folders.');
            }
            foreach ($paths as $path) {
                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('Select valid folders.');
                }
                $this->library()->resolve($path, true);
            }
            if ($jsonMove) {
                (new ServerLibraryEditor($this->library()))->move($paths, (string)$this->request->getPost('destination'));
                $this->recordEdit('move', $paths);
                return $this->response->noCache()->setJSON(['ok'=>true, 'message'=>'Selected folders moved.', 'csrfToken'=>csrf_hash()]);
            }
            return $this->edit();
        } catch (RuntimeException $error) {
            if ($jsonMove) {
                return $this->response->noCache()->setStatusCode(422)->setJSON(['ok' => false, 'message' => $error->getMessage(), 'csrfToken' => csrf_hash()]);
            }
            return redirect()->to(site_url('workspace/server-library'))->with('error', $error->getMessage());
        } catch (\Throwable $error) {
            if (! $jsonMove) {
                throw $error;
            }
            log_message('error', 'Folder move failed: {message}', ['message'=>$error->getMessage()]);
            return $this->response->noCache()->setStatusCode(500)->setJSON(['ok'=>false, 'message'=>'The move could not be completed. Check the folder locations before retrying.', 'csrfToken'=>csrf_hash()]);
        }
    }

    private function selectedPaths(): array
    {
        $ids = $this->request->getPost('file_ids') ?? [];
        if (! is_array($ids) || count($ids) > 50 || count(array_filter($ids, 'is_string')) !== count($ids)) {
            throw new RuntimeException('Choose valid server files.');
        }
        return array_map([Presentation::class, 'path'], array_values(array_unique($ids)));
    }

    public function watermarking()
    {
        if ($denied = $this->access()) {
            return $denied;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) {
            return $this->response->setStatusCode(403);
        }
        try {
            $folder = (string)$this->request->getGet('folder');
            $search = trim((string)$this->request->getGet('q'));
            $type = (string)$this->request->getGet('type');
            $item = (string)$this->request->getGet('item');
            if ($item !== '') {
                $path = Presentation::path($item);
                $this->library()->resolve($path);
                $folder = dirname($path) === '.' ? '' : dirname($path);
                $search = basename($path);
            }
            $listing = $this->catalog()->browse($folder, $search, 0, null, $type, max(1, (int)$this->request->getGet('page')), true);
            $data = Presentation::data($listing + ['folder'=>$folder]);
            $images = $data['files'];
            foreach ($data['folders'] as &$listedFolder) {
                $contents = $this->catalog()->browse($listedFolder['id'], '', 0, null, '', 1, true);
                $listedFolder['file_count'] = $contents['file_total'];
                $listedFolder['subfolder_count'] = count(array_filter($contents['items'], static fn ($entry) => $entry['directory']));
            }
            unset($listedFolder);
            $logos = service('watermarking')->availableLogos();
            $addedLogo = session()->getFlashdata('watermark_logo_added');
            $db = db_connect();
            $settings = $db->tableExists('watermark_settings') ? ($db->table('watermark_settings')->orderBy('id')->get(1)->getRowArray() ?? []) : [];
            return view('workspace/server_library_watermarking', array_merge($data, [
                'user'=>session()->get(), 'activePage'=>'watermarking', 'title'=>'Watermarking', 'settings'=>$settings,
                'logos'=>$logos, 'defaultLogoCode'=>is_string($addedLogo) && in_array($addedLogo, array_column($logos, 'code'), true) ? $addedLogo : service('watermarking')->settingsLogoCode($settings),
                'logoUploadName'=>session()->getFlashdata('watermark_logo_name'), 'images'=>$images, 'pagination'=>$listing['pagination'], 'searchQuery'=>$search,
                'type'=>$type, 'parentFolder'=>null, 'isAdmin'=>RolePolicy::isCreativeAdmin(session()->get()),
                'success'=>session()->getFlashdata('success'), 'error'=>session()->getFlashdata('error'),
            ]));
        } catch (RuntimeException $error) { return redirect()->to(site_url('workspace/server-library'))->with('error', $error->getMessage()); }
    }

    public function uploadWatermarkLogo()
    {
        if ($denied = $this->access()) {
            return $denied;
        }
        $actor = session()->get();
        if (! in_array(RolePolicy::role($actor), ['admin', 'super_admin'], true)) {
            return $this->response->setStatusCode(403)->setBody('Only administrators can add watermark logos.');
        }
        $context = [];
        foreach (['folder','q','type','page'] as $key) {
            $value = $this->request->getPost($key);
            if (is_string($value) && $value !== '') {
                $context[$key] = mb_substr($value, 0, 4000);
            }
        }
        $returnUrl = site_url('workspace/server-library/watermarking') . ($context ? '?' . http_build_query($context) : '');
        $name = $this->request->getPost('logo_name');
        try {
            $upload = $this->request->getFile('logo_file');
            if ($upload === null) {
                throw new RuntimeException('Choose a logo image to upload.');
            }
            $logo = service('watermarking')->addLogo($upload, is_string($name) ? $name : '');
        } catch (RuntimeException $error) {
            return redirect()->to($returnUrl)->with('error', $error->getMessage())->with('watermark_logo_name', is_string($name) ? mb_substr($name, 0, 60) : '');
        }
        $this->recordEdit('watermark-logo-upload', [$logo['code']]);
        return redirect()->to($returnUrl)->with('success', 'Logo added. It is selected and ready to preview.')->with('watermark_logo_added', $logo['code']);
    }

    public function watermarkDownload()
    {
        if ($denied = $this->access()) {
            return $denied;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) {
            return $this->response->setStatusCode(403);
        }
        $temporary = [];
        try {
            $paths = $this->selectedPaths();
            if (! $paths) {
                throw new RuntimeException('Select images to download.');
            }
            $position = (string)$this->request->getPost('position');
            $opacity = filter_var($this->request->getPost('opacity'), FILTER_VALIDATE_INT);
            $scale = filter_var($this->request->getPost('resize_percent'), FILTER_VALIDATE_INT);
            if (! in_array($position, ['bottom_right', 'bottom_left', 'top_right', 'top_left', 'center'], true) || $opacity === false || $opacity < 30 || $opacity > 100 || $scale === false || $scale < 10 || $scale > 200) {
                throw new RuntimeException('Choose valid watermark settings.');
            }
            $logo = (string)$this->request->getPost('logo_code');
            foreach ($paths as $path) {
                $result = service('watermarking')->createForServerDownload($this->library()->resolve($path), $logo, ['position'=>$position, 'opacity'=>$opacity, 'resize_percent'=>$scale]);
                if (empty($result['applied'])) {
                    throw new RuntimeException($result['reason'] ?? 'Unable to create the watermarked download.');
                }
                $temporary[] = $result['path'];
            }
            {
                $download = tempnam(WRITEPATH . 'cache', 'server-watermark-');
                if ($download === false) {
                    throw new RuntimeException('Unable to create the download.');
                }
                $temporary[] = $download;
                $zip = new \ZipArchive();
                if ($zip->open($download, \ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Unable to create the download.');
                }
                try { foreach ($paths as $index=>$path) if (!$zip->addFile($temporary[$index], $path)) throw new RuntimeException('Unable to add an image.'); }
                finally { $closed = $zip->close(); }
                if (! $closed) {
                    throw new RuntimeException('Unable to complete the download.');
                }
                $name = 'watermarked-images-' . gmdate('Ymd-His') . '.zip';
            }
            register_shutdown_function(static function () use ($temporary): void { foreach ($temporary as $path) if (is_file($path)) unlink($path); });
            return $this->response->download($download, null)->setFileName($name)->noCache();
        } catch (\Throwable $error) {
            foreach ($temporary as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            return redirect()->to(site_url('workspace/server-library/watermarking'))->with('error', $error instanceof RuntimeException ? $error->getMessage() : 'The download could not be created.');
        }
    }
}
