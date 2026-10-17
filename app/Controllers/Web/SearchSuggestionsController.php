<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\RolePolicy;

class SearchSuggestionsController extends BaseController
{
    public function index(string $scope)
    {
        $actor = session()->get();
        if (($actor['isLoggedIn'] ?? false) !== true) return $this->response->setStatusCode(401)->setJSON(['suggestions'=>[]]);
        $allowed = match ($scope) {
            'users', 'audit-users' => RolePolicy::isSuperAdmin($actor),
            'audit' => RolePolicy::isCreativeAdmin($actor),
            'ifound', 'trash' => RolePolicy::allows($actor, 'files.manage'),
            'passports' => RolePolicy::allows($actor, 'ai.process'),
            default => false,
        };
        if (! $allowed) return $this->response->setStatusCode(403)->setJSON(['suggestions'=>[]]);
        if ($scope === 'audit' && ! RolePolicy::isSuperAdmin($actor) && (int) ($actor['employee_id'] ?? 0) < 1) return $this->response->setStatusCode(403)->setJSON(['suggestions'=>[]]);
        session()->close();
        $term = trim(mb_substr((string) $this->request->getGet('q'), 0, 100));
        if (mb_strlen($term) < 2) return $this->response->noCache()->setJSON(['suggestions'=>[]]);
        $db = db_connect();
        if (in_array($scope, ['users','audit-users'], true)) {
            $query = $scope === 'users' ? $db->table('user')->select('employee_id AS id, name')
                : $db->table('audit_logs')->select('user_id AS id, user_name AS name')->distinct();
            $id = $scope === 'users' ? 'employee_id' : 'user_id';
            $name = $scope === 'users' ? 'name' : 'user_name';
            $query->groupStart()->like($name, $term)->orLike($id, $term);
            if ($scope === 'users') $query->orLike('email_address', $term)->orLike('position', $term);
            $rows = $query->groupEnd()->orderBy($name)->limit(8)->get()->getResultArray();
            $items = array_map(static fn ($row) => ['value'=>(string) $row['id'], 'label'=>($row['name'] ?: 'Unknown user') . ' · ' . $row['id']], $rows);
        } elseif ($scope === 'trash') {
            $rows = $db->table('files f')->select('f.filename')->join('trashfiles tf', 'tf.file_id = f.id')
                ->where('f.is_deleted', 1)->where('tf.folder_root_id', null)->like('f.filename', $term)->limit(8)->get()->getResultArray();
            $items = array_map(static fn ($row) => ['value'=>$row['filename']], $rows);
            $folders = $db->table('folder')->select('name')->where('is_deleted', 1)->where('trash_root_id = id', null, false)->like('name', $term)->limit(8)->get()->getResultArray();
            foreach ($folders as $folder) $items[] = ['value'=>$folder['name']];
            try {
                $editor = new \App\Services\ServerLibraryEditor(new \App\Services\ServerLibraryService());
                foreach ($editor->trashItems() as $item) {
                    if (mb_stripos($item['path'], $term) !== false) $items[] = ['value'=>$item['path']];
                    if (count($items) >= 8) break;
                }
            } catch (\RuntimeException) { /* Local suggestions remain usable while the share is offline. */ }
            $items = array_slice($items, 0, 8);
        } elseif ($scope === 'audit') {
            $query = $db->table('audit_logs')->select('action')->distinct()->like('action', $term);
            if (! RolePolicy::isSuperAdmin($actor)) $query->where('user_id', (int) ($actor['employee_id'] ?? 0));
            $items = array_map(static fn ($row) => ['value'=>$row['action']], $query->orderBy('action')->limit(8)->get()->getResultArray());
        } else {
            $query = $db->table('files f')->select('f.filename, f.display_title')->where('f.is_deleted', 0);
            if ($scope === 'passports') $query->join('passports p', 'p.media_id = f.id');
            $rows = $query->groupStart()->like('f.filename', $term)->orLike('f.display_title', $term)->groupEnd()
                ->orderBy('f.dateupload','DESC')->limit(8)->get()->getResultArray();
            $items = array_map(static fn ($row) => ['value'=>mb_stripos($row['filename'], $term) !== false ? $row['filename'] : $row['display_title']], $rows);
        }
        return $this->response->noCache()->setJSON(['suggestions'=>$items]);
    }
}
