<?php
namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\VisualMediaAnalysisService;

class AnalyzeVisualImage extends BaseCommand
{
    protected $group = 'Greyhound';
    protected $name = 'media:visual-analyze';
    protected $description = 'Analyze one active image by file ID and save its iFound results.';

    public function run(array $params)
    {
        $id = filter_var($params[0] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$id) { CLI::error('Usage: php spark media:visual-analyze <file-id>'); return EXIT_ERROR; }
        $file = db_connect()->table('files')->where('id',$id)->where('is_deleted',0)->get()->getRowArray();
        if (!$file) { CLI::error('Image unavailable.'); return EXIT_ERROR; }
        try {
            $start = microtime(true);
            $result = (new VisualMediaAnalysisService())->analyzeAndStore($file);
            CLI::write(json_encode(['file_id'=>$id, 'seconds'=>round(microtime(true)-$start,2),
                'objects'=>$result['objects']['status'], 'object_labels'=>array_column($result['objects']['items'],'label'),
                'expressions'=>$result['expressions']['status'], 'duplicates_checked'=>$result['duplicates']['checked'],
                'unindexed'=>$result['duplicates']['unindexed'] ?? 0, 'duplicate_matches'=>$result['duplicates']['total_matches'],
                'catalog_sync'=>$result['catalog_sync'] ?? 'complete']));
        } catch (\Throwable $error) { CLI::error($error->getMessage()); return EXIT_ERROR; }
        return EXIT_SUCCESS;
    }
}
