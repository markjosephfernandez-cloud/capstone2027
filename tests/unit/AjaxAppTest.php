<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class AjaxAppTest extends CIUnitTestCase
{
    public function testApplicationFooterLoadsProgressiveAjaxLayerAfterMainScript(): void
    {
        $footer = file_get_contents(APPPATH . 'Views/layouts/app_footer.php');

        $this->assertIsString($footer);
        $mainPosition = strpos($footer, "assets/js/main.js");
        $ajaxPosition = strpos($footer, "assets/js/ajax-app.js");
        $this->assertNotFalse($mainPosition);
        $this->assertNotFalse($ajaxPosition);
        $this->assertGreaterThan($mainPosition, $ajaxPosition);
    }

    public function testAjaxLayerSupportsCrudFiltersHistoryAndNativeFileResponses(): void
    {
        $script = file_get_contents(PUBLICPATH . 'assets/js/ajax-app.js');

        $this->assertIsString($script);
        $this->assertStringContainsString("document.addEventListener('submit'", $script);
        $this->assertStringContainsString("document.addEventListener('click'", $script);
        $this->assertStringContainsString("new FormData(form)", $script);
        $this->assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $script);
        $this->assertStringContainsString("document.body.replaceWith(importedBody)", $script);
        $this->assertStringContainsString("window.addEventListener('popstate'", $script);
        $this->assertStringContainsString("form.id === 'batchUploadForm'", $script);
        $this->assertStringContainsString("(?:download|preview)", $script);
        $this->assertStringContainsString("report|stream", $script);
        $this->assertStringContainsString("(?:login|logout)", $script);
        $this->assertStringContainsString('isAuditedAjaxMutation', $script);
        $this->assertStringContainsString("method === 'GET'", $script);
        $this->assertStringContainsString("form.matches('[data-ajax=\"true\"]')", $script);
        $this->assertStringContainsString('response.ok', $script);
        $this->assertStringContainsString('data-ajax-live-search', $script);
        $this->assertStringContainsString("document.addEventListener('compositionstart'", $script);
        $this->assertStringContainsString("document.addEventListener('input'", $script);
        $this->assertStringContainsString("document.addEventListener('change'", $script);
        $this->assertStringContainsString('restoreLiveSearchFocus', $script);
        $this->assertStringContainsString('requestUrl.hash', $script);
    }

    public function testBatchUploadRefreshesLibraryThroughAjax(): void
    {
        $view = file_get_contents(APPPATH . 'Views/workspace/media.php');

        $this->assertIsString($view);
        $this->assertStringContainsString('window.GreyhoundAjax.refresh', $view);
        $this->assertStringNotContainsString('window.location.reload()', $view);
    }

    public function testHighValueServerFiltersUseReusableLiveAjaxSearch(): void
    {
        $views = [
            APPPATH . 'Views/workspace/media.php',
            APPPATH . 'Views/workspace/trash.php',
            APPPATH . 'Views/workspace/ifound.php',
            APPPATH . 'Views/addons/passports.php',
            APPPATH . 'Views/addons/audit_logs.php',
            APPPATH . 'Views/admin/control_center.php',
        ];

        foreach ($views as $view) {
            $contents = file_get_contents($view);
            $this->assertIsString($contents);
            $this->assertStringContainsString('data-ajax-live-search', $contents, $view);
        }
    }
}
