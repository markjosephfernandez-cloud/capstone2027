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
            $success = session()->getFlashdata('success');
            $error = session()->getFlashdata('error');
            session()->close();
            $db = db_connect();
            if (! $db->tableExists('passports')) {
                return $this->missingAddon('passports', 'Metadata Passports');
            }

            $search = trim((string) $this->request->getGet('q'));
            if (mb_strlen($search) > 100) {
                $search = mb_substr($search, 0, 100);
            }
            $perPage = 20;
            $requestedPage = max(1, (int) $this->request->getGet('page'));

            $passportQuery = $db->table('passports p')
                ->join('files f', 'f.id = p.media_id')
                ->join('user u', 'u.employee_id = p.uploaded_by', 'left')
                ->where('f.is_deleted', 0);
            if ($search !== '') {
                $passportQuery
                    ->groupStart()
                    ->like('f.filename', $search)
                    ->orLike('f.display_title', $search)
                    ->orLike('p.date_taken', $search)
                    ->orLike('p.event_name', $search)
                    ->groupEnd();
            }

            $matchingTotal = (clone $passportQuery)->countAllResults();
            $totalPages = max(1, (int) ceil($matchingTotal / $perPage));
            $currentPage = min($requestedPage, $totalPages);
            $offset = ($currentPage - 1) * $perPage;

            $passports = $passportQuery
                ->select('p.*, f.id AS file_id, f.display_title, f.filename, f.filetype, f.mime_type, f.size, u.name AS uploader_name')
                ->orderBy('p.updated_at', 'DESC')
                ->limit($perPage, $offset)
                ->get()
                ->getResultArray();
            $summary = [
                'total' => (int) $db->table('passports p')
                    ->join('files f', 'f.id = p.media_id')
                    ->where('f.is_deleted', 0)
                    ->countAllResults(),
                'ai_flags' => (int) $db->table('passports p')
                    ->join('files f', 'f.id = p.media_id')
                    ->where('f.is_deleted', 0)
                    ->where('p.needs_review', 1)
                    ->countAllResults(),
            ];

            return view('addons/passports', $this->pageData('passports', 'Metadata Passports', [
                'passports' => $passports,
                'summary' => $summary,
                'searchQuery' => $search,
                'pagination' => [
                    'current_page' => $currentPage,
                    'total_pages' => $totalPages,
                    'total_results' => $matchingTotal,
                    'per_page' => $perPage,
                    'from' => $matchingTotal === 0 ? 0 : $offset + 1,
                    'to' => min($offset + $perPage, $matchingTotal),
                ],
                'isAdmin' => RolePolicy::isCreativeAdmin(session()->get()),
                'success' => $success,
                'error' => $error,
            ]));
        }

        public function watermarking()
        {
            if (($redirect = $this->requireLogin()) !== null) {
                return $redirect;
            }
            if (! RolePolicy::allows(session()->get(), 'files.manage')) return redirect()->to(site_url('workspace/file-management'))->with('error', 'Watermarking is restricted to Creative Staff.');
            $db = db_connect();
            $settings = $db->tableExists('watermark_settings')
                ? ($db->table('watermark_settings')->orderBy('id', 'ASC')->get(1)->getRowArray() ?? [])
                : [];
            $logos = service('watermarking')->availableLogos();
            $addedLogo = session()->getFlashdata('watermark_logo_added');
            $search = mb_substr(trim((string) $this->request->getGet('q')), 0, 100);
            $type = strtolower(trim((string) $this->request->getGet('type')));
            $imageTypes = ['jpg', 'jpeg', 'png', 'webp'];
            if (! in_array($type, $imageTypes, true)) {
                $type = '';
            }
            $folderId = filter_var($this->request->getGet('folder'), FILTER_VALIDATE_INT);
            $folderId = $folderId !== false && $folderId > 0 ? $folderId : null;
            if ($search !== '') {
                $folderId = null;
            }
            $allFolders = $db->table('folder')->where('is_deleted', 0)->orderBy('name', 'ASC')->get()->getResultArray();
            $folderById = [];
            foreach ($allFolders as $folder) {
                $folderById[(int) $folder['id']] = $folder;
            }
            $folderPath = static function (array $folder) use (&$folderById): string {
                $parts = [(string) $folder['name']];
                $seen = [(int) $folder['id'] => true];
                $parentId = empty($folder['parent_id']) ? null : (int) $folder['parent_id'];
                while ($parentId !== null && isset($folderById[$parentId]) && ! isset($seen[$parentId])) {
                    $seen[$parentId] = true;
                    array_unshift($parts, (string) $folderById[$parentId]['name']);
                    $parentId = empty($folderById[$parentId]['parent_id']) ? null : (int) $folderById[$parentId]['parent_id'];
                }
                return implode(' / ', $parts);
            };
            $selectedFolder = $folderId === null ? null : ($folderById[$folderId] ?? null);
            if ($folderId !== null && $selectedFolder === null) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'The requested folder is unavailable.');
            }
            $folderBreadcrumbs = [];
            if ($selectedFolder !== null) {
                $breadcrumbFolder = $selectedFolder;
                $breadcrumbSeen = [];
                while ($breadcrumbFolder !== null && ! isset($breadcrumbSeen[(int) $breadcrumbFolder['id']])) {
                    $breadcrumbSeen[(int) $breadcrumbFolder['id']] = true;
                    array_unshift($folderBreadcrumbs, [
                        'id' => (int) $breadcrumbFolder['id'],
                        'name' => (string) $breadcrumbFolder['name'],
                    ]);
                    $breadcrumbParentId = empty($breadcrumbFolder['parent_id']) ? null : (int) $breadcrumbFolder['parent_id'];
                    $breadcrumbFolder = $breadcrumbParentId === null ? null : ($folderById[$breadcrumbParentId] ?? null);
                }
            }
            $parentFolder = $selectedFolder === null || empty($selectedFolder['parent_id']) ? null : ($folderById[(int) $selectedFolder['parent_id']] ?? null);
            $folders = array_values(array_filter($allFolders, static function (array $folder) use ($folderId, $search, $folderPath): bool {
                if ($search !== '') {
                    return stripos((string) $folder['name'], $search) !== false || stripos($folderPath($folder), $search) !== false;
                }
                return $folderId === null ? empty($folder['parent_id']) : (int) ($folder['parent_id'] ?? 0) === $folderId;
            }));
            $descendantIds = static function (int $rootId, array $seen = []) use (&$descendantIds, $allFolders): array {
                if (isset($seen[$rootId])) {
                    return [];
                }
                $seen[$rootId] = true;
                $ids = [$rootId];
                foreach ($allFolders as $candidate) {
                    if ((int) ($candidate['parent_id'] ?? 0) === $rootId) {
                        $ids = array_merge($ids, $descendantIds((int) $candidate['id'], $seen));
                    }
                }
                return array_values(array_unique($ids));
            };
            foreach ($folders as &$folder) {
                $folder['file_count'] = $db->table('files')->where('is_deleted', 0)->whereIn('folder_id', $descendantIds((int) $folder['id']))->whereIn('filetype', $imageTypes)->countAllResults();
                $folder['subfolder_count'] = count(array_filter($allFolders, static fn (array $child): bool => (int) ($child['parent_id'] ?? 0) === (int) $folder['id']));
            }
            unset($folder);

            $images = $db->table('files f')
                ->select('f.id, f.display_title, f.filename, f.filetype, f.size, f.dateupload, f.folder_id, f.tags')
                ->where('f.is_deleted', 0)
                ->whereIn('f.filetype', $imageTypes);
            if ($search !== '') {
                $matchingFolderIds = array_map('intval', array_column($folders, 'id'));
                $images->groupStart()->like('f.display_title', $search)->orLike('f.filename', $search)->orLike('f.tags', $search);
                if ($matchingFolderIds !== []) {
                    $images->orWhereIn('f.folder_id', $matchingFolderIds);
                }
                $images->groupEnd();
            } elseif ($folderId === null) {
                $images->where('f.folder_id IS NULL', null, false);
            } else {
                $images->where('f.folder_id', $folderId);
            }
            if ($type !== '') {
                $images->where('f.filetype', $type);
            }
            $perPage = 20;
            $requestedPage = max(1, (int) $this->request->getGet('page'));
            $matchingTotal = $images->countAllResults(false);
            $totalPages = max(1, (int) ceil($matchingTotal / $perPage));
            $currentPage = min($requestedPage, $totalPages);
            $offset = ($currentPage - 1) * $perPage;
            $images = $images->orderBy('f.dateupload', 'DESC')->orderBy('f.id', 'DESC')
                ->limit($perPage, $offset)->get()->getResultArray();
            foreach ($images as &$image) {
                $containingFolder = empty($image['folder_id']) ? null : ($folderById[(int) $image['folder_id']] ?? null);
                $image['folder_display_path'] = $containingFolder === null ? 'Root Library' : $folderPath($containingFolder);
            }
            unset($image);

            return view('addons/watermarking', $this->pageData('watermarking', 'Watermarking', [
                'settings' => $settings,
                'logos'    => $logos,
                'defaultLogoCode' => is_string($addedLogo) && in_array($addedLogo, array_column($logos, 'code'), true)
                    ? $addedLogo : service('watermarking')->settingsLogoCode($settings),
                'logoUploadName' => session()->getFlashdata('watermark_logo_name'),
                'images'   => $images,
                'pagination' => [
                    'current_page' => $currentPage,
                    'total_pages' => $totalPages,
                    'total_results' => $matchingTotal,
                    'per_page' => $perPage,
                    'from' => $matchingTotal === 0 ? 0 : $offset + 1,
                    'to' => min($offset + $perPage, $matchingTotal),
                ],
                'searchQuery' => $search,
                'type' => $type,
                'folderId' => $folderId,
                'folders' => $folders,
                'selectedFolder' => $selectedFolder,
                'parentFolder' => $parentFolder,
                'folderBreadcrumbs' => $folderBreadcrumbs,
                'selectedFolderPath' => $selectedFolder === null ? 'Root Library' : 'Root Library / ' . $folderPath($selectedFolder),
                'isAdmin'  => RolePolicy::isCreativeAdmin(session()->get()),
                'success'  => session()->getFlashdata('success'),
                'error'    => session()->getFlashdata('error'),
            ]));
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

        public function uploadWatermarkLogo()
        {
            if (($redirect = $this->requireLogin()) !== null) {
                return $redirect;
            }
            $actor = session()->get();
            if (! in_array(RolePolicy::role($actor), ['admin', 'super_admin'], true)) {
                return $this->response->setStatusCode(403)->setBody('Only administrators can add watermark logos.');
            }
            $context = [];
            foreach (['folder', 'q', 'type', 'page'] as $key) {
                $value = $this->request->getPost($key);
                if (is_string($value) && $value !== '') $context[$key] = mb_substr($value, 0, 100);
            }
            $returnUrl = site_url('workspace/watermarking') . ($context ? '?' . http_build_query($context) : '');
            $name = $this->request->getPost('logo_name');
            try {
                $upload = $this->request->getFile('logo_file');
                if ($upload === null) throw new RuntimeException('Choose a logo image to upload.');
                $logo = service('watermarking')->addLogo($upload, is_string($name) ? $name : '');
            } catch (RuntimeException $error) {
                return redirect()->to($returnUrl)->with('error', $error->getMessage())
                    ->with('watermark_logo_name', is_string($name) ? mb_substr($name, 0, 60) : '');
            }
            service('audit')->record($actor, $this->request, 'watermark', 'WATERMARK_LOGO_UPLOAD', 'logo', null,
                'Added watermark logo "' . $logo['label'] . '" (' . $logo['code'] . ').');
            return redirect()->to($returnUrl)->with('success', 'Logo added. It is selected and ready to preview.')
                ->with('watermark_logo_added', $logo['code']);
        }

        public function updateWatermarking()
        {
            if (($redirect = $this->requireLogin()) !== null) {
                return $redirect;
            }
            $actor = session()->get();
            if (! RolePolicy::isCreativeAdmin($actor)) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Only administrators can save default watermark settings.');
            }
            $db = db_connect();
            if (! $db->tableExists('watermark_settings')) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Watermark settings are not installed.');
            }
            $logoCode = strtoupper(trim((string) $this->request->getPost('logo_code')));
            $position = (string) $this->request->getPost('position');
            $opacity = filter_var($this->request->getPost('opacity'), FILTER_VALIDATE_INT);
            $scale = filter_var($this->request->getPost('resize_percent'), FILTER_VALIDATE_INT);
            $validLogos = array_column(service('watermarking')->availableLogos(), 'code');
            if (! in_array($logoCode, $validLogos, true)
                || ! in_array($position, ['bottom_right', 'bottom_left', 'top_right', 'top_left', 'center'], true)
                || $opacity === false || $opacity < 30 || $opacity > 100
                || $scale === false || $scale < 10 || $scale > 200) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Choose valid logo, position, opacity, and scale settings.');
            }
            $existing = $db->table('watermark_settings')->orderBy('id', 'ASC')->get(1)->getRowArray();
            $payload = [
                'enabled' => 0,
                'logo_path' => $logoCode,
                'position' => $position,
                'opacity' => $opacity,
                'resize_percent' => $scale,
                'updated_by' => (string) ($actor['employee_id'] ?? ''),
            ];
            if ($existing === null) {
                $payload['id'] = 1;
                $db->table('watermark_settings')->insert($payload);
                $settingsId = (int) $db->insertID();
            } else {
                $settingsId = (int) $existing['id'];
                $db->table('watermark_settings')->where('id', $settingsId)->update($payload);
            }
            service('audit')->record($actor, $this->request, 'watermark', 'WATERMARK_SETTINGS_UPDATE', 'settings', $settingsId, 'Saved the ' . $logoCode . ' logo as the default on-demand watermark.');
            return redirect()->to(site_url('workspace/watermarking'))->with('success', 'Default watermark settings saved. No image copy was created.');
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
            $position = (string) $this->request->getPost('position');
            $opacity = filter_var($this->request->getPost('opacity'), FILTER_VALIDATE_INT);
            $resizePercent = filter_var($this->request->getPost('resize_percent'), FILTER_VALIDATE_INT);
            if (! in_array($position, ['bottom_right', 'bottom_left', 'top_right', 'top_left', 'center'], true)
                || $opacity === false || $opacity < 30 || $opacity > 100
                || $resizePercent === false || $resizePercent < 10 || $resizePercent > 200) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Choose valid watermark settings.');
            }
            $result = service('watermarking')->createForDownload($file, $logoCode, [
                'position' => $position,
                'opacity' => $opacity,
                'resize_percent' => $resizePercent,
            ]);
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

        public function bulkDownloadWithLogo()
        {
            if (($redirect = $this->requireLogin()) !== null) {
                return $redirect;
            }
            $actor = session()->get();
            if (! RolePolicy::allows($actor, 'files.manage')) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Your role is not allowed to create watermarked downloads.');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->getPost('file_ids')), static fn (int $id): bool => $id > 0)));
            if ($ids === []) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Select one or more images first.');
            }
            if (count($ids) > 100) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Select no more than 100 images at one time.');
            }
            if (! class_exists(\ZipArchive::class)) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'ZIP downloads are not available on this server.');
            }
            $logoCode = strtoupper(trim((string) $this->request->getPost('logo_code')));
            $position = (string) $this->request->getPost('position');
            $opacity = filter_var($this->request->getPost('opacity'), FILTER_VALIDATE_INT);
            $scale = filter_var($this->request->getPost('resize_percent'), FILTER_VALIDATE_INT);
            $validLogos = array_column(service('watermarking')->availableLogos(), 'code');
            if (! in_array($logoCode, $validLogos, true)
                || ! in_array($position, ['bottom_right', 'bottom_left', 'top_right', 'top_left', 'center'], true)
                || $opacity === false || $opacity < 30 || $opacity > 100
                || $scale === false || $scale < 10 || $scale > 200) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'Choose valid watermark settings.');
            }
            $files = (new FileModel())->where('is_deleted', 0)->whereIn('id', $ids)->whereIn('filetype', ['jpg', 'jpeg', 'png', 'webp'])->findAll();
            $zipPath = tempnam(WRITEPATH . 'cache', 'watermarked-');
            if ($zipPath === false) {
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'The download archive could not be created.');
            }
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
                @unlink($zipPath);
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'The download archive could not be created.');
            }
            $temporaryFiles = [];
            $usedNames = [];
            $added = 0;
            foreach ($files as $file) {
                $result = service('watermarking')->createForDownload($file, $logoCode, ['position' => $position, 'opacity' => $opacity, 'resize_percent' => $scale]);
                if (empty($result['applied']) || empty($result['path'])) {
                    continue;
                }
                $temporary = (string) $result['path'];
                $baseName = pathinfo((string) $file['filename'], PATHINFO_FILENAME) . '_' . strtolower($logoCode) . '_watermarked.' . strtolower((string) $file['filetype']);
                $archiveName = $baseName;
                $suffix = 2;
                while (isset($usedNames[mb_strtolower($archiveName)])) {
                    $archiveName = pathinfo($baseName, PATHINFO_FILENAME) . '_' . $suffix++ . '.' . pathinfo($baseName, PATHINFO_EXTENSION);
                }
                if ($zip->addFile($temporary, $archiveName)) {
                    $usedNames[mb_strtolower($archiveName)] = true;
                    $temporaryFiles[] = $temporary;
                    $added++;
                } else {
                    @unlink($temporary);
                }
            }
            $zip->close();
            foreach ($temporaryFiles as $temporary) {
                @unlink($temporary);
            }
            if ($added === 0) {
                @unlink($zipPath);
                return redirect()->to(site_url('workspace/watermarking'))->with('error', 'None of the selected images could be watermarked.');
            }
            service('audit')->record($actor, $this->request, 'watermark', 'BULK_WATERMARK_DOWNLOAD', 'file', 0, 'Downloaded ' . $added . ' temporary ' . $logoCode . '-watermarked image(s).');
            register_shutdown_function(static function () use ($zipPath): void { if (is_file($zipPath)) @unlink($zipPath); });
            return $this->response->download($zipPath, null, true)->setFileName('watermarked-images-' . gmdate('Ymd-His') . '.zip')->noCache();
        }

        public function auditLogs()
        {
            if (($redirect = $this->requireLogin()) !== null) {
                return $redirect;
            }
            $actor = session()->get();
            $isSuperAdmin = RolePolicy::isSuperAdmin($actor);
            if (! $isSuperAdmin && ! RolePolicy::isCreativeAdmin($actor)) {
                return redirect()->to(site_url('dashboard'))->with('error', 'Audit Logs require Creative Staff access.');
            }
            session()->close();
            $db = db_connect();
            if (! $db->tableExists('audit_logs')) {
                return $this->missingAddon('audit-logs', 'Audit Logs');
            }

            $actorId = (string) ($actor['employee_id'] ?? '');
            if (! $isSuperAdmin && (! ctype_digit($actorId) || (int) $actorId < 1)) {
                return $this->response->setStatusCode(403)->setBody('A valid user account is required to view Audit Logs.');
            }
            $requestedLog = (string) $this->request->getGet('log');
            $selectedLogId = $requestedLog === '' ? null : max(0, (int) (filter_var($requestedLog, FILTER_VALIDATE_INT) ?: 0));
            $filterUser = $isSuperAdmin ? trim(mb_substr((string) $this->request->getGet('user'), 0, 100)) : '';
            $module = trim(mb_substr((string) $this->request->getGet('module'), 0, 50));
            $action = trim(mb_substr((string) $this->request->getGet('action'), 0, 50));
            $query = $isSuperAdmin ? '' : trim(mb_substr((string) $this->request->getGet('q'), 0, 120));
            $hasRoleColumn = in_array('user_role', $db->getFieldNames('audit_logs'), true);
            $roleExpression = $hasRoleColumn ? "COALESCE(l.user_role, u.role, 'system') AS actor_role" : "COALESCE(u.role, 'system') AS actor_role";

            $logs = $db->table('audit_logs l')
                ->select('l.*, ' . $roleExpression, false)
                ->join('user u', 'u.employee_id = l.user_id', 'left')
                ->orderBy('l.created_at', 'DESC')
                ->orderBy('l.id', 'DESC');
            if (! $isSuperAdmin) {
                $logs->where('l.user_id', $actorId);
            }
            if ($selectedLogId !== null) {
                $logs->where('l.id', $selectedLogId);
            }
            if ($filterUser !== '') {
                if (ctype_digit($filterUser)) $logs->where('l.user_id', $filterUser);
                else $logs->groupStart()->like('l.user_name', $filterUser)->orLike('l.user_id', $filterUser)->groupEnd();
            }
            if ($module !== '') {
                $logs->where('l.module', $module);
            }
            if ($action !== '') {
                $logs->where('l.action', $action);
            }
            if ($query !== '') {
                $logs->groupStart()
                    ->like('l.description', $query)
                    ->orLike('l.action', $query)
                    ->orLike('l.target_type', $query)
                    ->orLike('l.target_id', $query)
                    ->groupEnd();
            }
            $logs    = $logs->limit(200)->get()->getResultArray();
            $this->attachAuditTargets($logs);

            $moduleQuery = $db->table('audit_logs')->select('module')->where('module IS NOT NULL', null, false);
            $actionQuery = $db->table('audit_logs')->select('action')->where('action IS NOT NULL', null, false);
            if (! $isSuperAdmin) {
                $moduleQuery->where('user_id', $actorId);
                $actionQuery->where('user_id', $actorId);
            }
            $modules = $moduleQuery->groupBy('module')->orderBy('module')->get()->getResultArray();
            $actions = $actionQuery->groupBy('action')->orderBy('action')->get()->getResultArray();

            return view('addons/audit_logs', $this->pageData('audit-logs', 'Audit Logs', compact('logs', 'modules', 'actions', 'filterUser', 'module', 'action', 'query', 'isSuperAdmin', 'selectedLogId')));
        }

        /** @param list<array<string, mixed>> $logs */
        private function attachAuditTargets(array &$logs): void
        {
            $db = db_connect();
            foreach ($logs as &$log) {
                $type = (string) ($log['target_type'] ?? '');
                $id = filter_var($log['target_id'] ?? null, FILTER_VALIDATE_INT);
                $label = $type !== '' ? ucfirst(str_replace('_', ' ', $type)) : 'System';
                if ($id === false || $id < 1) {
                    $log['target_label'] = $label;
                    continue;
                }

                $row = null;
                if ($type === 'file' && $db->tableExists('files')) {
                    $row = $db->table('files')->select('display_title, filename')->where('id', $id)->get()->getRowArray();
                    $label = $row ? \App\Libraries\MediaTitle::forFile($row) : 'File #' . $id;
                } elseif ($type === 'folder' && $db->tableExists('folder')) {
                    $row = $db->table('folder')->select('name')->where('id', $id)->where('is_deleted', 0)->get()->getRowArray();
                    $label = $row ? (string) $row['name'] : 'Folder #' . $id;
                } elseif ($type === 'user' && $db->tableExists('user')) {
                    $row = $db->table('user')->select('name')->where('employee_id', $id)->get()->getRowArray();
                    $label = $row ? (string) $row['name'] : 'User #' . $id;
                } elseif ($type === 'media_request' && $db->tableExists('media_requests')) {
                    $row = $db->table('media_requests')->select('title, department')->where('id', $id)->get()->getRowArray();
                    $label = $row ? (string) $row['title'] . ' - ' . (string) $row['department'] : 'Media request #' . $id;
                } elseif ($type === 'passport' && $db->tableExists('passports')) {
                    $row = $db->table('passports')->select('passport_uid')->where('id', $id)->get()->getRowArray();
                    $label = $row ? (string) $row['passport_uid'] : 'Passport #' . $id;
                } elseif ($type === 'settings') {
                    $label = 'Watermark settings';
                } else {
                    $label .= ' #' . $id;
                }

                $log['target_label'] = $label;
            }
            unset($log);
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
