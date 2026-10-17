<?php

use App\Libraries\PublishedChannelGroups;
use CodeIgniter\Test\CIUnitTestCase;

final class PublishedChannelGroupsTest extends CIUnitTestCase
{
    public function testChannelsKeepTheirFoldersAndFilesSeparateAndCountContents(): void
    {
        $groups = PublishedChannelGroups::group(
            [['id' => 10, 'publication_channel' => 'CCS', 'file_count' => 4]],
            [
                ['id' => 1, 'publication_channel' => 'CCS'],
                ['id' => 2, 'publication_channel' => 'CBA'],
                ['id' => 3, 'publication_channel' => ' CCS '],
            ]
        );
        $this->assertSame(['CBA', 'CCS'], array_keys($groups));
        $this->assertSame([1, 3], array_column($groups['CCS']['files'], 'id'));
        $this->assertSame([2], array_column($groups['CBA']['files'], 'id'));
        $this->assertSame(6, $groups['CCS']['file_count']);
        $this->assertSame(1, $groups['CCS']['folder_count']);
        $this->assertSame([10], array_column($groups['CCS']['folders'], 'id'));
    }

    public function testUnassignedPublishedMediaRemainsAccessible(): void
    {
        $groups = PublishedChannelGroups::group([], [['id' => 1, 'publication_channel' => null]]);
        $this->assertSame('Unassigned channel', $groups['']['label']);
        $this->assertSame(1, $groups['']['file_count']);
        $this->assertSame([], PublishedChannelGroups::group([], []));
    }

    public function testPublishedRootShowsFoldersAndFilesWithoutChannelGroups(): void
    {
        $data = $this->viewData();
        $data['folders'] = [[
            'id' => 10, 'name' => 'Digital expo', 'file_count' => 4,
            'publisher_name' => 'Creative team', 'published_at' => '2026-09-15 10:00:00',
            'publication_channel' => 'CCS',
        ]];
        $data['files'] = [[
            'id' => 1, 'filename' => 'Campus photo.jpg', 'display_title' => 'Campus photo', 'filetype' => 'jpg',
            'publisher_name' => 'Creative team', 'published_at' => '2026-09-15 10:00:00',
            'publication_channel' => 'Arts & Culture',
        ]];
        $html = view('workspace/published_files', $data);
        $this->assertStringContainsString('workspace/published-files/10', $html);
        $this->assertStringContainsString('Digital expo', $html);
        $this->assertStringContainsString('4 files', $html);
        $this->assertStringContainsString('Campus photo', $html);
        $this->assertStringContainsString('workspace/file-management/1/preview', $html);
        $this->assertStringNotContainsString('channel=', $html);
        $this->assertStringNotContainsString('channel-folder-card', $html);
        $this->assertStringNotContainsString('Published - CCS', $html);
        $this->assertStringNotContainsString('No published files yet', $html);
    }

    public function testPublishedFolderReturnsDirectlyToPublishedFiles(): void
    {
        $data = $this->viewData();
        $data['parentFolder'] = ['id' => 10, 'name' => 'Digital expo'];
        $html = view('workspace/published_files', $data);
        $this->assertStringContainsString('Back to Published Files', $html);
        $this->assertStringContainsString('<h2>Digital expo</h2>', $html);
        $this->assertStringNotContainsString('channel=', $html);
        $this->assertStringNotContainsString('All channels', $html);
    }

    private function viewData(): array
    {
        helper('dashboard');
        return [
            'user' => ['employee_id' => 0, 'name' => 'Test User', 'role' => 'admin'],
            'activePage' => 'published-files', 'folders' => [], 'files' => [],
            'parentFolder' => null, 'canManage' => true,
        ];
    }
}
