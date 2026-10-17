<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\MediaStreamResponse;
use App\Libraries\RolePolicy;
use App\Services\ServerLibraryService;
use App\Services\ServerLibraryEditor;
use App\Services\ServerLibraryTransferService;
use App\Services\ThumbnailService;
use App\Services\VideoPreviewService;
use RuntimeException;

class ServerLibraryController extends BaseController
{
    // Every server page (including the actions controller) formats file sizes.
    protected $helpers = ['dashboard'];

    protected function library(): ServerLibraryService { return new ServerLibraryService(); }

    protected function catalog(): ServerLibraryService { return new \App\Services\ServerLibraryCatalog(); }

    protected function fileManagementCatalog(): ServerLibraryService
    {
        $catalog = $this->catalog();
        $catalog->searchTaggedImagesOnly(in_array(RolePolicy::role(session()->get()), ['admin', 'super_admin'], true), (int) $this->request->getGet('folder_page'));
        return $catalog;
    }

    protected function uploads(): \App\Services\ServerLibraryUploadService { return new \App\Services\ServerLibraryUploadService($this->library()); }

    public function viewed(string $token)
    {
        if ($denied = $this->access()) return $denied;
        if (!(new \Config\Storage())->centralized()) return $this->response->setStatusCode(204);
        try {
            $path = \App\Services\ServerLibraryPresentation::path($token);
            (new \App\Services\CentralMediaService($this->library()))->viewed($path,(int)session()->get('employee_id'));
            return $this->response->noCache()->setJSON(['csrfToken'=>csrf_hash(),'csrfTokenName'=>csrf_token()]);
        } catch (RuntimeException) { return $this->response->setStatusCode(404); }
    }

    public function recent()
    {
        if ($denied = $this->access()) return $denied;
        $actor = session()->get();
        session()->close();
        $rows = db_connect()->table('server_recent r')->select('m.id, m.path, r.viewed_at')
            ->join('server_media m','m.id = r.media_id')->where('r.employee_id',(int)($actor['employee_id'] ?? 0))
            ->where('m.source_key',$this->library()->catalogSourceKey())->where('m.is_deleted',0)
            ->orderBy('r.viewed_at','DESC')->limit(100)->get()->getResultArray();
        return view('workspace/server_recent',['user'=>$actor,'activePage'=>'recent','title'=>'Recent','items'=>$rows]);
    }

    public function uploadToken()
    {
        if ($denied = $this->access()) return $denied;
        if (!RolePolicy::allows(session()->get(), 'files.upload')) return $this->response->setStatusCode(403)->setJSON(['ok'=>false]);
        $chunkBytes = \App\Services\ChunkUploadService::MAX_CHUNK_BYTES;
        foreach (['upload_max_filesize','post_max_size'] as $key) {
            $value = trim((string)ini_get($key));
            $bytes = (float)$value * match (strtolower(substr($value,-1))) { 'g'=>1073741824, 'm'=>1048576, 'k'=>1024, default=>1 };
            if ($bytes > 0) $chunkBytes = min($chunkBytes, max(1,(int)floor($bytes*.75)));
        }
        return $this->response->noCache()->setJSON(['ok'=>true,'csrfTokenName'=>csrf_token(),'csrfToken'=>csrf_hash(),'maxUploadBytes'=>(new \Config\Storage())->maxUploadBytes(),'chunkBytes'=>$chunkBytes]);
    }

    public function upload()
    {
        if ($denied = $this->access()) return $denied;
        $actor = session()->get();
        if (!RolePolicy::allows($actor,'files.upload')) return $this->response->setStatusCode(403)->setJSON(['ok'=>false,'message'=>'Your account cannot upload files.']);
        $csrf = csrf_hash();
        session()->close();
        @set_time_limit(0);
        try {
            $uploads = $this->uploads();
            if ($this->request->getPost('cancel_upload') === '1') {
                $id = $this->request->getPost('chunk_id');
                if (!is_string($id)) throw new RuntimeException('Invalid upload identifier.');
                return $this->response->noCache()->setJSON(array_merge(['ok'=>true,'csrfToken'=>$csrf], $uploads->chunks()->cancel((int)$actor['employee_id'],$id)));
            }
            $metadata = [];
            foreach (['file_name','folder_id','relative_path','upload_batch_id','audit_total','audit_item_id'] as $key) {
                $value = $this->request->getPost($key);
                if (is_array($value) || strlen((string)$value)>5000) throw new RuntimeException('Invalid upload details.');
                $metadata[$key === 'file_name' ? 'name' : $key] = (string)$value;
            }
            $file = $this->request->getFile('file');
            if ($file === null || !$file->isValid()) throw new RuntimeException('The upload is incomplete. Try again.');
            $process = function ($file) use ($uploads,$metadata,$actor): array {
                try {
                    $payload = $uploads->receive($file,$metadata,$actor);
                    if (!empty($payload['ok']) && !empty($payload['complete']) && !empty($payload['uploaded'])) {
                        $this->recordUploadBatch($actor, $metadata, $payload['path'], true);
                    }
                    return ['status'=>200,'payload'=>$payload];
                }
                catch (RuntimeException $exception) {
                    $this->recordUploadBatch($actor, $metadata, trim(($metadata['folder_id'] ?? '') . '/' . ($metadata['relative_path'] ?: $file->getClientName()), '/'), false);
                    return ['status'=>422,'payload'=>['ok'=>false,'message'=>$exception->getMessage()]];
                }
            };
            if ($this->request->getPost('chunk_upload') === '1') {
                $total = filter_var($this->request->getPost('file_size'),FILTER_VALIDATE_INT);
                $offset = filter_var($this->request->getPost('chunk_offset'),FILTER_VALIDATE_INT);
                $id = $this->request->getPost('chunk_id');
                $limit = (new \Config\Storage())->maxUploadBytes();
                if (!is_string($id) || $total === false || $offset === false || ($limit>0 && $total>$limit)) throw new RuntimeException('Invalid file size or upload identifier.');
                $result = $uploads->chunks()->accept((int)$actor['employee_id'],$id,$total,$offset,$file->getTempName(),$metadata,$process);
            } else $result = $process($file);
            $result['payload']['csrfToken'] = $csrf;
            return $this->response->noCache()->setStatusCode($result['status'])->setJSON($result['payload']);
        } catch (RuntimeException $exception) {
            return $this->response->noCache()->setStatusCode(422)->setJSON(['ok'=>false,'message'=>$exception->getMessage(),'csrfToken'=>$csrf]);
        }
    }

    protected function recordUploadBatch(array $actor, array $metadata, string $path, bool $success): void
    {
        try { (new \App\Services\ServerUploadAuditService(db_connect()))->record($actor, $metadata, $path, $success); }
        catch (\Throwable $error) { log_message('error', 'Server Library batch audit failed: {message}', ['message'=>$error->getMessage()]); }
    }

    public function folders()
    {
        if ($denied = $this->access()) return $denied;
        session()->close();
        try {
            $folders = $this->fileManagementCatalog()->listFolders((string)$this->request->getGet('folder'), $this->readBudget());
            return $this->response->noCache()->setJSON(['folders'=>array_map(static fn ($item) => ['id'=>$item['path'],'name'=>$item['path']],$folders)]);
        } catch (RuntimeException $exception) { return $this->response->noCache()->setStatusCode(503)->setJSON(['message'=>$exception->getMessage()]); }
    }

    public function suggestions()
    {
        if ($denied = $this->access()) return $denied;
        session()->close();
        $query = trim(mb_substr((string)$this->request->getGet('q'),0,120));
        if (mb_strlen($query)<2) return $this->response->noCache()->setJSON(['suggestions'=>[]]);
        try {
            $suggestions = $this->fileManagementCatalog()->suggestions((string)$this->request->getGet('folder'), $query, (string)$this->request->getGet('type'), $this->readBudget());
            return $this->response->noCache()->setJSON(['suggestions'=>$suggestions]);
        } catch (RuntimeException $exception) { return $this->response->noCache()->setStatusCode(503)->setJSON(['message'=>$exception->getMessage()]); }
    }

    public function passport()
    {
        if ($denied = $this->access()) return $denied;
        session()->close();
        try {
            $relative = (string)$this->request->getGet('path');
            $this->library()->resolve($relative);
            $passport = $this->uploads()->state()['media'][$relative]['passport'] ?? null;
            if (!$passport) throw new RuntimeException('No passport is available for this file.');
            return $this->response->download(basename($relative).'.passport.json',json_encode($passport,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))->noCache();
        } catch (RuntimeException $exception) { return $this->response->setStatusCode(404)->setBody($exception->getMessage()); }
    }

    private function readBudget(): callable
    {
        $deadline = hrtime(true) + 10_000_000_000;
        // Cooperative checks between filesystem calls; a blocked OS read must return first.
        // Only browsing/counting use this budget, never copy-and-send operations.
        return static fn (): bool => connection_aborted() === 0 && hrtime(true) < $deadline;
    }

    protected function access()
    {
        if (session()->get('isLoggedIn') !== true) return redirect()->to(site_url('login'));
        if (!RolePolicy::allows(session()->get(), 'files.view_all')) return $this->response->setStatusCode(403)->setBody('Server Library is unavailable for your account.');
        return null;
    }

    public function index()
    {
        if ($denied = $this->access()) return $denied;
        $folder = (string)$this->request->getGet('folder');
        $search = trim(mb_substr((string)$this->request->getGet('q'),0,120));
        $type = strtolower((string)$this->request->getGet('type'));
        $actor = session()->get();
        $polling = $this->request->getGet('_catalog_poll') === '1';
        $error = $polling ? null : session()->getFlashdata('error');
        $success = $polling ? null : session()->getFlashdata('success');
        session()->close();
        $connected = false;
        $writable = false;
        $listing = ['items'=>[], 'file_total'=>0, 'next_offset'=>null, 'listing_ms'=>0];
        try {
            $listing = $this->fileManagementCatalog()->browse($folder, $search, 0, $this->readBudget(), $type, max(1, (int)$this->request->getGet('page')));
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
        // Ignore scan heartbeat/timing changes: they do not change visible results.
        $version = hash('sha256', json_encode([
            $listing['items'], $listing['pagination'] ?? [], $listing['folder_pagination'] ?? [], $listing['folder_index_pending'] ?? false,
            $listing['search_incomplete'] ?? false, $listing['catalog_status']['status'] ?? '',
            $listing['catalog_status']['completed_at'] ?? null,
        ], JSON_INVALID_UTF8_SUBSTITUTE));
        if ($polling && $error === null && $this->request->getGet('_catalog_version') === $version) {
            return $this->response->noCache()->setStatusCode(204)->setBody('');
        }
        $listing['catalog_version'] = $version;
        $departments = [];
        $duplicates = [];

        $this->response->setHeader('Server-Timing', 'listing;dur=' . number_format($listing['listing_ms'], 2, '.', ''));
        return view('workspace/server_library', array_merge($listing, [
            'user'=>$actor,'activePage'=>'file-management','title'=>'Server Library','duplicates'=>$duplicates,
            'type'=>$type,'uploadMaxBytes'=>(new \Config\Storage())->maxUploadBytes(),
            'catalog_mode'=>true,'folder'=>$folder,'query'=>$search,'connected'=>$connected,'error'=>$error,'success'=>$success,
            'canSend'=>RolePolicy::allows($actor,'files.assign'),'departments'=>$departments,
            'canManage'=>RolePolicy::allows($actor,'files.manage'), 'canUpload'=>RolePolicy::allows($actor,'files.upload'), 'writable'=>$writable,
        ]));
    }

    public function status()
    {
        if ($denied = $this->access()) return $denied;
        $actor = session()->get();
        session()->close();
        $readable = false;
        $writable = false;
        try { $this->library()->root(); $connected = true; }
        catch (RuntimeException) { $connected = false; }
        if ($connected) {
            try {
                $folder = $this->request->getGet('folder') ?? '';
                if (!is_string($folder)) throw new RuntimeException('Invalid server folder.');
                $this->library()->resolve($folder, true);
                $readable = true;
                $writable = $this->library()->writable($folder) && $this->library()->writable();
            } catch (RuntimeException) {}
        }
        return $this->response->noCache()->setJSON([
            'connected'=>$connected, 'readable'=>$readable, 'writable'=>$writable,
            'can_upload'=>$writable && RolePolicy::allows($actor, 'files.upload'),
            'can_manage'=>$writable && RolePolicy::allows($actor, 'files.manage'),
        ]);
    }

    public function counts()
    {
        if ($denied = $this->access()) return $denied;
        session()->close();
        $started = hrtime(true);
        try {
            $counts = $this->fileManagementCatalog()->folderCounts((string)$this->request->getGet('folder'), $this->readBudget());
            return $this->response->noCache()
                ->setHeader('Server-Timing', 'counts;dur=' . number_format((hrtime(true)-$started)/1e6, 2, '.', ''))
                ->setJSON(['counts'=>$counts]);
        } catch (RuntimeException) {
            return $this->response->noCache()->setStatusCode(404)->setJSON(['error'=>'Folder contents unavailable.']);
        }
    }

    public function files()
    {
        if ($denied = $this->access()) return $denied;
        $canSend = RolePolicy::allows(session()->get(), 'files.assign');
        $canManage = RolePolicy::allows(session()->get(), 'files.manage');
        session()->close();
        try {
            $listing = $this->fileManagementCatalog()->browse((string)$this->request->getGet('folder'),
                trim(mb_substr((string)$this->request->getGet('q'),0,120)), max(0,(int)$this->request->getGet('offset')), $this->readBudget(), (string)$this->request->getGet('type'));
            $files = array_values(array_filter($listing['items'], static fn ($item) => !$item['directory']));
            return $this->response->noCache()
                ->setHeader('Server-Timing', 'listing;dur=' . number_format($listing['listing_ms'], 2, '.', ''))
                ->setJSON(['html'=>view('workspace/server_library_files', ['files'=>$files,'canSend'=>$canSend,'canManage'=>$canManage]),
                    'next_offset'=>$listing['next_offset'], 'file_total'=>$listing['file_total']]);
        } catch (RuntimeException) {
            return $this->response->noCache()->setStatusCode(404)->setJSON(['error'=>'Server files unavailable. Refresh or reconnect the server.']);
        }
    }

    public function media()
    {
        if ($denied = $this->access()) return $denied;
        $canManage = RolePolicy::allows(session()->get(),'files.manage');
        session()->close();
        $started = hrtime(true);
        try {
            $path = $this->library()->resolve((string)$this->request->getGet('path'));
            $filename = basename($path);
            if ($this->request->getGet('download') === '1') return $this->response->download($path,null)->setFileName($filename)->noCache();
            $mime = $this->mediaMimeType($path);
            if ($this->request->getGet('thumbnail') === '1') {
                $image = is_string($mime) && str_starts_with($mime,'video/')
                    ? (new VideoPreviewService())->thumbnailForFile($path)
                    : (new ThumbnailService())->forFile($path);
                // Access and server availability are checked even when the JPEG is cached.
                $this->library()->root();
                return $this->response->noCache()->setHeader('Server-Timing', 'thumbnail;dur=' . number_format((hrtime(true)-$started)/1e6, 2, '.', ''))
                    ->setContentType('image/jpeg')->setBody((string)file_get_contents($image));
            }
            if (\App\Services\CameraImagePreviewService::supports($path)) {
                $path = (new \App\Services\CameraImagePreviewService())->forFile($path);
                $mime = 'image/jpeg';
                $this->library()->root();
            }
            if (!in_array($mime,['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm','video/quicktime','video/x-msvideo','video/x-matroska','video/x-m4v'],true)) {
                return $this->response->setStatusCode(415)->setBody('Download this file to open it.');
            }
            if ($this->request->getGet('video_preview') === '1' && is_string($mime) && str_starts_with($mime, 'video/')) {
                $path = (new VideoPreviewService())->forFile($path);
                $mime = $this->mediaMimeType($path);
                $this->library()->root();
            }
            // Stream the original or a compatible cached preview without changing the server file.
            $size = filesize($path);
            if (!$size) throw new RuntimeException('This file is empty or unavailable.');
            $start = 0; $end = $size-1; $partial = false;
            $range = $this->request->getHeaderLine('Range');
            if (preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$match) && ($match[1] !== '' || $match[2] !== '')) {
                $partial = true;
                $start = $match[1] === '' ? max(0,$size-(int)$match[2]) : (int)$match[1];
                $end = $match[1] === '' || $match[2] === '' ? $end : min($end,(int)$match[2]);
                if ($start > $end || $start >= $size) return $this->response->setStatusCode(416)->setHeader('Content-Range','bytes */'.$size)->setBody('Invalid range.');
                // Release the HTTP worker between browser playback requests.
                if ($match[1] !== '' && $match[2] === '' && str_starts_with($mime, 'video/')) {
                    $end = min($end, $start + 4 * 1024 * 1024 - 1);
                }
            }
            $response = new MediaStreamResponse($path,$mime,$start,$end-$start+1,$partial,strtoupper($this->request->getMethod())==='HEAD');
            if ($partial) $response->setHeader('Content-Range',"bytes $start-$end/$size");
            return $response;
        } catch (RuntimeException $exception) {
            return $this->response->noCache()->setStatusCode(404)->setBody('Server file unavailable. Reconnect the server or refresh the folder.');
        }
    }

    protected function mediaMimeType(string $path): string
    {
        // Inspect a bounded header: finfo::file can seek/read large amounts of
        // a video over a mounted share, repeated for every browser range request.
        return \App\Libraries\MediaMimeType::forFile($path);
    }

    public function send()
    {
        if ($denied = $this->access()) return $denied;
        $actor = session()->get();
        if (!RolePolicy::allows($actor,'files.assign')) return $this->response->setStatusCode(403)->setBody('Your account cannot send files.');
        $paths = $this->request->getPost('paths');
        try {
            $delivery = (new ServerLibraryTransferService(db_connect(),$this->library(),service('storage')))->send(
            is_array($paths) ? $paths : [], (string)$this->request->getPost('department'), $actor
            );
        } catch (\Throwable $exception) {
            log_message('error','Server Library transfer failed: {message}',['message'=>$exception->getMessage()]);
            return redirect()->to(site_url('workspace/server-library'))->with('error','The files could not be sent. Check the connection, selected files, and department, then try again.');
        }
        return redirect()->to(site_url('workspace/distribution?tab=direct&request='.(int)$delivery['request_id']))
            ->with('success','Files sent for department review. Server originals are unchanged.');
    }

    public function edit()
    {
        if ($denied = $this->access()) return $denied;
        $action = (string) $this->request->getPost('action');
        $jsonFolder = $action === 'create-folder' && $this->request->isAJAX()
            && str_contains($this->request->getHeaderLine('Accept'), 'application/json');
        $actor = session()->get();
        $permission = $action === 'upload' ? 'files.upload' : 'files.manage';
        if (! RolePolicy::allows($actor, $permission)) return $this->response->setStatusCode(403)->setBody('Your account cannot edit the Server Library.');
        $folder = (string) $this->request->getPost('folder');
        $paths = $this->request->getPost('paths');
        $paths = is_array($paths) ? $paths : [];
        $editor = new ServerLibraryEditor($this->library());
        try {
            $this->library()->relative($folder);
            switch ($action) {
                case 'create-folder': $editor->createFolder($folder, (string) $this->request->getPost('name')); break;
                case 'rename':
                    if (count($paths) !== 1 || ! is_string($paths[0] ?? null)) throw new RuntimeException('Select one item to rename.');
                    $editor->rename($paths[0], (string) $this->request->getPost('name')); break;
                case 'move': $editor->move($paths, (string) $this->request->getPost('destination')); break;
                case 'tags': $editor->addTags($paths, explode(',', (string) $this->request->getPost('tags'))); break;
                case 'trash': $editor->trash($paths, (int) ($actor['employee_id'] ?? 0)); break;
                case 'restore': $editor->restore((string) $this->request->getPost('trash_id')); break;
                case 'upload':
                    $uploads = $this->request->getFileMultiple('files') ?? [];
                    $expected = (int) $this->request->getPost('expected_files');
                    if ($expected > 0 && count($uploads) !== $expected) throw new RuntimeException('The server did not receive all selected files. Upload fewer files or increase PHP upload limits.');
                    $editor->upload($folder, $uploads); break;
                default: throw new RuntimeException('Choose a supported server action.');
            }
        } catch (RuntimeException $exception) {
            if ($jsonFolder) return $this->response->noCache()->setStatusCode(422)->setJSON(['ok'=>false, 'message'=>$exception->getMessage(), 'csrfToken'=>csrf_hash()]);
            return redirect()->to(site_url($action === 'restore' ? 'workspace/trash' : 'workspace/server-library') . '?' . http_build_query(['folder'=>$folder]))->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            if ($jsonFolder) return $this->response->noCache()->setStatusCode(500)->setJSON(['ok'=>false, 'message'=>'The folder could not be created. Check the connection and try again.', 'csrfToken'=>csrf_hash()]);
            log_message('error', 'Server Library edit failed: {message}', ['message'=>$exception->getMessage()]);
            return redirect()->to(site_url('workspace/server-library') . '?' . http_build_query(['folder'=>$folder]))->with('error', 'The server edit could not be completed. Check the connection and try again.');
        }
        $this->recordEdit($action, $paths);
        if ($jsonFolder) {
            $path = ($folder === '' ? '' : $folder . '/') . (string)$this->request->getPost('name');
            return $this->response->noCache()->setJSON(['ok'=>true, 'folder'=>['id'=>$path, 'name'=>$path], 'csrfToken'=>csrf_hash()]);
        }
        return redirect()->to(site_url($action === 'restore' ? 'workspace/trash' : 'workspace/server-library') . '?' . http_build_query(['folder'=>$folder]))
            ->with('success', ['upload'=>'Files uploaded to the server.', 'create-folder'=>'Folder created on the server.', 'rename'=>'Item renamed.', 'move'=>'Selected items moved.', 'tags'=>'Tags saved.', 'trash'=>'Selected items moved to Trash.', 'restore'=>'Item restored to its original server folder.'][$action]);
    }

    public function post()
    {
        if ($denied = $this->access()) return $denied;
        if (!RolePolicy::allows(session()->get(), 'files.manage')) return $this->response->setStatusCode(403)->setBody('Your account cannot post files.');
        $actor = session()->get();
        $previousLimit = (int) ini_get('max_execution_time');
        if ($previousLimit > 0 && $previousLimit < 300) set_time_limit(300);
        session()->close();
        $failure = null;
        try {
            $paths = $this->request->getPost('paths');
            (new ServerLibraryTransferService(db_connect(), $this->library(), service('storage')))->post(
                is_array($paths) ? $paths : [], (string)$this->request->getPost('display_title'), (string)$this->request->getPost('description'), $actor
            );
        } catch (\Throwable $exception) {
            log_message('error', 'Server Library post failed: {message}', ['message'=>$exception->getMessage()]);
            $failure = $exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException
                ? $exception->getMessage() : 'The selected photos or videos could not be posted. Check the selection and server connection.';
        } finally {
            // Reopen only to persist feedback; copying must not block other tabs.
            session()->start();
            if ((int) ini_get('max_execution_time') !== $previousLimit) set_time_limit($previousLimit);
        }
        if ($failure !== null) return redirect()->to(site_url('workspace/server-library') . '?' . http_build_query(['folder'=>(string)$this->request->getPost('folder')]))->with('error', $failure);
        $this->recordEdit('post', $paths);
        return redirect()->to(site_url('workspace/published-files'))->with('message', 'Posted to GreyhoundHub. App admins can now edit and publish it.');
    }

    public function downloadSelected()
    {
        if ($denied = $this->access()) return $denied;
        $zipPath = null;
        try {
            $paths = $this->request->getGet('paths');
            if (!is_array($paths) || !$paths || count($paths) > 50 || count(array_filter($paths, 'is_string')) !== count($paths)) throw new RuntimeException('Choose between 1 and 50 files.');
            if (!class_exists(\ZipArchive::class)) throw new RuntimeException('ZIP downloads are unavailable on this server.');
            $files = [];
            foreach (array_unique($paths) as $path) $files[$path] = $this->library()->resolve($path);
            $zipPath = tempnam(WRITEPATH . 'cache', 'server-download-');
            if ($zipPath === false) throw new RuntimeException('The download could not be prepared.');
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) throw new RuntimeException('The download could not be prepared.');
            try {
                foreach ($files as $relative=>$absolute) if (!$zip->addFile($absolute, $relative)) throw new RuntimeException('A selected file could not be read.');
            } finally { $closed = $zip->close(); }
            if (!$closed) throw new RuntimeException('The archive could not be completed. Try again.');
        } catch (\Throwable $exception) {
            if (is_string($zipPath) && is_file($zipPath)) unlink($zipPath);
            return redirect()->to(site_url('workspace/server-library'))->with('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The download could not be prepared.');
        }
        register_shutdown_function(static function () use ($zipPath): void { if (is_file($zipPath)) unlink($zipPath); });
        return $this->response->download($zipPath, null, true)->setFileName('server-media-' . gmdate('Ymd-His') . '.zip');
    }

    protected function recordEdit(string $action, array $paths): void
    {
        $module = in_array($action, ['trash', 'restore'], true) ? 'delete' : 'file';
        try { service('audit')->record(session()->get(), $this->request, $module, 'SERVER_LIBRARY_' . strtoupper(str_replace('-', '_', $action)), 'server_library', null, 'Server Library ' . $action . ': ' . implode(', ', $paths)); }
        catch (\Throwable $exception) { log_message('error', 'Server Library edit succeeded but audit logging failed: {message}', ['message'=>$exception->getMessage()]); }
    }

    public function trash()
    {
        if ($denied = $this->access()) return $denied;
        if (! RolePolicy::allows(session()->get(), 'files.manage')) return $this->response->setStatusCode(403)->setBody('Trash is unavailable for your account.');
        return redirect()->to(site_url('workspace/trash'));
    }
}
