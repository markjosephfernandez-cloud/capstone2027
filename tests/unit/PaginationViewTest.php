<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class PaginationViewTest extends CIUnitTestCase
{
    public function testPaginationIsHiddenForASinglePage(): void
    {
        $html = view('components/pagination', [
            'pagination' => ['current_page' => 1, 'total_pages' => 1],
            'pageUrl' => static fn (int $page): string => '/items?page=' . $page,
        ]);

        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringNotContainsString('class="pagination', $html);
    }

    public function testPaginationShowsNavigationForMultiplePages(): void
    {
        $html = view('components/pagination', [
            'pagination' => ['current_page' => 2, 'total_pages' => 4],
            'pageUrl' => static fn (int $page): string => '/items?page=' . $page,
            'paginationLabel' => 'Media pages',
        ]);

        $this->assertStringContainsString('aria-label="Media pages"', $html);
        $this->assertStringContainsString('/items?page=1', $html);
        $this->assertStringContainsString('/items?page=3', $html);
        $this->assertStringContainsString('aria-current="page">2</a>', $html);
    }
}
