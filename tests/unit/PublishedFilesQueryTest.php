<?php

use App\Controllers\Web\WorkspaceController;
use App\Models\FileModel;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class PublishedFilesQueryTest extends CIUnitTestCase
{
    public function testPublishedFilesInsideOrdinaryFoldersRemainVisibleWithoutFolderCards(): void
    {
        $method = new ReflectionMethod(WorkspaceController::class, 'publishedStandaloneQuery');
        $query = $method->invoke(new WorkspaceController(), new FileModel(), []);
        $sql = $query->builder()->getCompiledSelect(false);

        $this->assertStringContainsString('release_status', $sql);
        $this->assertStringNotContainsString('folder_id IS NULL', $sql);
    }

    public function testFilesRepresentedByPublishedFolderCardsAreExcluded(): void
    {
        $method = new ReflectionMethod(WorkspaceController::class, 'publishedStandaloneQuery');
        $query = $method->invoke(new WorkspaceController(), new FileModel(), [124, 126]);
        $sql = $query->builder()->getCompiledSelect(false);

        $this->assertStringContainsString('folder_id IS NULL', $sql);
        $this->assertMatchesRegularExpression('/folder_id.*NOT IN.*124.*126/is', $sql);
    }
}
