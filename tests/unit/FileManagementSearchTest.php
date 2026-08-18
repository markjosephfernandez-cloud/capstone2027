<?php

use App\Controllers\Web\WorkspaceController;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class FileManagementSearchTest extends CIUnitTestCase
{
    public function testSearchCoversFilenamePassportDateEventAndMatchingFolders(): void
    {
        $method = new ReflectionMethod(WorkspaceController::class, 'applyFileManagementSearch');
        $builder = db_connect()->table('files f')->select('f.*')->where('f.is_deleted', 0);
        $builder = $method->invoke(new WorkspaceController(), $builder, 'graduation', [7, 8], true);
        $sql = $builder->getCompiledSelect(false);

        $this->assertMatchesRegularExpression('/LEFT JOIN `(?:db_)?passports` `p` ON `p`\.`media_id` = `f`\.`id`/', $sql);
        $this->assertStringContainsString('`f`.`filename` LIKE', $sql);
        $this->assertStringContainsString('`f`.`tags` LIKE', $sql);
        $this->assertStringContainsString('`p`.`date_taken` LIKE', $sql);
        $this->assertStringContainsString('`p`.`event_name` LIKE', $sql);
        $this->assertMatchesRegularExpression('/`f`\.`folder_id` IN\s*\(7,8\)/', $sql);
        $this->assertStringNotContainsString('`f`.`folder_id` IS NULL', $sql);
    }

    public function testParentFolderNameMatchesFilesInNestedFolderPaths(): void
    {
        $folders = [
            ['id' => 7, 'parent_id' => null, 'name' => 'Pillar', 'path' => 'Pillar'],
            ['id' => 8, 'parent_id' => 7, 'name' => '2026', 'path' => 'Pillar/2026'],
            ['id' => 9, 'parent_id' => 8, 'name' => 'Graduation', 'path' => 'Pillar/2026/Graduation'],
        ];
        $folderById = [];
        foreach ($folders as $folder) {
            $folderById[(int) $folder['id']] = $folder;
        }

        $method = new ReflectionMethod(WorkspaceController::class, 'matchingFolderIds');
        $ids = $method->invoke(new WorkspaceController(), $folders, $folderById, 'Pillar');

        $this->assertSame([7, 8, 9], $ids);
    }

    public function testContainingFolderAccessIncludesEveryAncestor(): void
    {
        $folders = [
            7 => ['id' => 7, 'parent_id' => null, 'name' => 'Pillar'],
            8 => ['id' => 8, 'parent_id' => 7, 'name' => '2026'],
            9 => ['id' => 9, 'parent_id' => 8, 'name' => 'CCS'],
            10 => ['id' => 10, 'parent_id' => 9, 'name' => 'Graduation'],
        ];

        $method = new ReflectionMethod(WorkspaceController::class, 'folderIdsWithAncestors');
        $ids = $method->invoke(new WorkspaceController(), [10], $folders);

        $this->assertSame([10, 9, 8, 7], $ids);
    }
}
