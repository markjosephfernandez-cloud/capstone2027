<?php

namespace App\Controllers\Api;

use CodeIgniter\I18n\Time;

class MobileSearchController extends ApiController
{
    public function history()
    {
        $rows = db_connect()->table('mobile_search_history')->select('query, MAX(searched_at) searched_at')
            ->where('user_employee_id', (int) $this->actor()['employee_id'])->groupBy('query')->orderBy('searched_at', 'DESC')->limit(15)->get()->getResultArray();
        return $this->success($rows);
    }

    public function remember()
    {
        $query = mb_substr(trim((string) ($this->input()['query'] ?? '')), 0, 255);
        if ($query === '') return $this->error('invalid_query', 'Search query cannot be empty.', 422);
        db_connect()->table('mobile_search_history')->insert(['user_employee_id' => (int) $this->actor()['employee_id'], 'query' => $query, 'searched_at' => Time::now('UTC')->toDateTimeString()]);
        return $this->success(['query' => $query], 201);
    }
}
