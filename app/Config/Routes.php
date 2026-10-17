<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('/', 'Web\\AuthController::loginForm');
$routes->get('login', 'Web\\AuthController::loginForm');
$routes->post('login', 'Web\\AuthController::login', ['filter' => 'csrf']);
$routes->get('dashboard', 'Web\\DashboardController::index');
$routes->get('admin/control-center', 'Web\\AdminController::controlCenter');
$routes->get('admin/control-center/server-health', 'Web\\AdminController::serverHealth');
$routes->post('admin/users', 'Web\\AdminController::createUser', ['filter' => 'csrf']);
$routes->post('admin/users/(:num)/update', 'Web\\AdminController::updateUser/$1', ['filter' => 'csrf']);
$routes->post('admin/users/(:num)/toggle', 'Web\\AdminController::toggleUser/$1', ['filter' => 'csrf']);
$routes->post('admin/users/(:num)/reset-password', 'Web\\AdminController::resetPassword/$1', ['filter' => 'csrf']);
$routes->post('admin/users/(:num)/revoke-tokens', 'Web\\AdminController::revokeTokens/$1', ['filter' => 'csrf']);
$routes->post('admin/maintenance/consent-reminders', 'Web\\AdminController::sendConsentReminders', ['filter' => 'csrf']);
$routes->post('admin/maintenance/purge-expired-trash', 'Web\\AdminController::purgeExpiredTrash', ['filter' => 'csrf']);
$routes->post('privacy/consent', 'Web\\PrivacyController::accept', ['filter' => 'csrf']);
$routes->get('workspace/file-management', 'Web\\WorkspaceController::media');
$routes->get('workspace/file-management/suggestions', 'Web\\WorkspaceController::mediaSuggestions');
// Local Library-compatible controls backed by server paths.
$routes->get('workspace/server-library/items/(:segment)/preview', 'Web\ServerLibraryActionsController::item/$1/preview');
$routes->get('workspace/server-library/items/(:segment)/video-preview', 'Web\ServerLibraryActionsController::item/$1/video-preview');
$routes->get('workspace/server-library/items/(:segment)/download', 'Web\ServerLibraryActionsController::item/$1/download');
$routes->post('workspace/server-library/items/(:segment)/trash', 'Web\ServerLibraryActionsController::action/trash/$1', ['filter'=>'csrf']);
$routes->post('workspace/server-library/folders/selection', 'Web\ServerLibraryActionsController::folderSelection', ['filter'=>'csrf']);
$routes->post('workspace/server-library/folders/create', 'Web\ServerLibraryActionsController::action/create-folder', ['filter'=>'csrf']);
$routes->post('workspace/server-library/folders/(:segment)/rename', 'Web\ServerLibraryActionsController::action/rename/$1', ['filter'=>'csrf']);
$routes->post('workspace/server-library/folders/(:segment)/delete', 'Web\ServerLibraryActionsController::action/trash/$1', ['filter'=>'csrf']);
$routes->post('workspace/server-library/tags', 'Web\ServerLibraryActionsController::action/tags', ['filter'=>'csrf']);
$routes->post('workspace/server-library/bulk-trash', 'Web\ServerLibraryActionsController::action/trash', ['filter'=>'csrf']);
$routes->post('workspace/server-library/bulk-download', 'Web\ServerLibraryActionsController::action/download', ['filter'=>'csrf']);
$routes->post('workspace/server-library/post-selection', 'Web\ServerLibraryActionsController::action/post', ['filter'=>'csrf']);
$routes->post('workspace/server-library/watermarking/logos', 'Web\ServerLibraryActionsController::uploadWatermarkLogo', ['filter'=>'csrf']);
$routes->get('workspace/server-library/watermarking', 'Web\ServerLibraryActionsController::watermarking');
$routes->post('workspace/server-library/watermarking/download', 'Web\ServerLibraryActionsController::watermarkDownload', ['filter'=>'csrf']);
$routes->get('workspace/server-library', 'Web\\ServerLibraryController::index');
$routes->post('workspace/server-library/edit', 'Web\\ServerLibraryController::edit', ['filter' => 'csrf']);
$routes->get('workspace/server-library/trash', 'Web\\ServerLibraryController::trash');
$routes->post('workspace/server-library/post', 'Web\\ServerLibraryController::post', ['filter' => 'csrf']);
$routes->get('workspace/server-library/download-selected', 'Web\\ServerLibraryController::downloadSelected');
$routes->get('workspace/server-library/upload/token', 'Web\\ServerLibraryController::uploadToken');
$routes->post('workspace/server-library/upload', 'Web\\ServerLibraryController::upload', ['filter' => 'csrf']);
$routes->get('workspace/server-library/folders', 'Web\\ServerLibraryController::folders');
$routes->get('workspace/server-library/suggestions', 'Web\\ServerLibraryController::suggestions');
$routes->get('workspace/server-library/passport', 'Web\\ServerLibraryController::passport');
$routes->get('workspace/server-library/status', 'Web\\ServerLibraryController::status');
$routes->get('workspace/server-library/counts', 'Web\\ServerLibraryController::counts');
$routes->get('workspace/server-library/files', 'Web\\ServerLibraryController::files');
$routes->get('workspace/server-library/media', 'Web\\ServerLibraryController::media');
$routes->post('workspace/server-library/send', 'Web\\ServerLibraryController::send', ['filter' => 'csrf']);
$routes->post('workspace/file-management/folders', 'Web\\WorkspaceController::createFolder', ['filter' => 'csrf']);
$routes->post('workspace/file-management/folders/(:num)/rename', 'Web\\WorkspaceController::renameFolder/$1', ['filter' => 'csrf']);
$routes->post('workspace/file-management/folders/(:num)/delete', 'Web\\WorkspaceController::deleteFolder/$1', ['filter' => 'csrf']);
$routes->post('workspace/file-management/upload', 'Web\\WorkspaceController::upload', ['filter' => 'csrf']);
$routes->get('workspace/file-management/upload/token', 'Web\\WorkspaceController::uploadToken');
$routes->post('workspace/file-management/tags', 'Web\\WorkspaceController::addTags', ['filter' => 'csrf']);
$routes->post('workspace/file-management/bulk-download', 'Web\\WorkspaceController::bulkDownload', ['filter' => 'csrf']);
$routes->post('workspace/file-management/bulk-trash', 'Web\\WorkspaceController::bulkTrash', ['filter' => 'csrf']);
$routes->post('workspace/file-management/post', 'Web\\WorkspaceController::postMedia', ['filter' => 'csrf']);
$routes->get('workspace/published-files', 'Web\\WorkspaceController::publishedFiles');
$routes->get('workspace/published-files/(:num)', 'Web\\WorkspaceController::publishedFiles/$1');
$routes->get('workspace/file-management/(:num)/preview', 'Web\\WorkspaceController::preview/$1');
$routes->get('workspace/file-management/(:num)/video-preview', 'Web\\WorkspaceController::videoPreview/$1');
$routes->get('workspace/file-management/(:num)/download', 'Web\\WorkspaceController::download/$1');
$routes->post('workspace/file-management/(:num)/trash', 'Web\\WorkspaceController::moveToTrash/$1', ['filter' => 'csrf']);
$routes->get('workspace/recent', 'Web\\WorkspaceController::recent');
$routes->get('workspace/ifound', 'Web\\WorkspaceController::ifound');
$routes->get('workspace/ifound/scan/token', 'Web\\WorkspaceController::iFoundScanToken');
$routes->get('workspace/ifound/scan/browse', 'Web\\WorkspaceController::browseIFoundScan');
$routes->post('workspace/ifound/scan/start', 'Web\\WorkspaceController::startIFoundScan', ['filter' => 'csrf']);
$routes->post('workspace/ifound/scan/step', 'Web\\WorkspaceController::stepIFoundScan', ['filter' => 'csrf']);
$routes->post('workspace/ifound/review', 'Web\\WorkspaceController::reviewEvents', ['filter' => 'csrf']);
$routes->post('workspace/ifound/(:num)/review', 'Web\\WorkspaceController::reviewEvent/$1', ['filter' => 'csrf']);
$routes->get('workspace/downloads', 'Web\\WorkspaceController::downloads');
$routes->get('workspace/trash', 'Web\\WorkspaceController::trash');
$routes->get('workspace/trash/folders/(:num)', 'Web\\WorkspaceController::trashFolder/$1');
$routes->get('workspace/trash/(:num)/preview', 'Web\\WorkspaceController::trashPreview/$1');
$routes->post('workspace/trash/(:num)/restore', 'Web\\WorkspaceController::restoreFromTrash/$1', ['filter' => 'csrf']);
$routes->post('workspace/trash/(:num)/delete', 'Web\\WorkspaceController::permanentlyDeleteFromTrash/$1', ['filter' => 'csrf']);
$routes->post('workspace/trash/bulk-restore', 'Web\\WorkspaceController::bulkRestoreTrash', ['filter' => 'csrf']);
$routes->post('workspace/trash/bulk-delete', 'Web\\WorkspaceController::bulkDeleteTrash', ['filter' => 'csrf']);
$routes->get('workspace/passports', 'Web\\AddonsController::passports');
$routes->post('workspace/passports/backfill', 'Web\\PassportController::backfill', ['filter' => 'csrf']);
$routes->get('workspace/passports/scan/browse', 'Web\\PassportController::browseScan');
$routes->get('workspace/passports/scan/token', 'Web\\PassportController::scanToken');
$routes->post('workspace/passports/scan/image', 'Web\\PassportController::scanImage', ['filter' => 'csrf']);
$routes->post('workspace/passports/analyze', 'Web\\PassportController::reanalyze', ['filter' => 'csrf']);
$routes->get('workspace/watermarking', 'Web\\AddonsController::watermarking');
$routes->get('workspace/watermarking/logo/(:segment)', 'Web\\AddonsController::watermarkLogo/$1');
$routes->post('workspace/watermarking/logos', 'Web\\AddonsController::uploadWatermarkLogo', ['filter' => 'csrf']);
$routes->post('workspace/watermarking/settings', 'Web\\AddonsController::updateWatermarking', ['filter' => 'csrf']);
$routes->post('workspace/watermarking/download', 'Web\\AddonsController::bulkDownloadWithLogo', ['filter' => 'csrf']);
$routes->post('workspace/watermarking/(:num)/download', 'Web\\AddonsController::downloadWithLogo/$1', ['filter' => 'csrf']);
$routes->post('workspace/distribution/direct-send-server', 'Web\DistributionServerController::direct', ['filter'=>'csrf']);
$routes->get('workspace/distribution/server-library', 'Web\DistributionServerController::browse');
$routes->post('workspace/distribution/server-library/selection', 'Web\DistributionServerController::selection', ['filter'=>'csrf']);
$routes->post('workspace/distribution/requests/(:num)/transmit-server', 'Web\DistributionServerController::transmit/$1', ['filter'=>'csrf']);
$routes->get('workspace/distribution', 'Web\\DistributionController::index');
$routes->post('workspace/distribution/requests', 'Web\\DistributionController::requestMedia', ['filter' => 'csrf']);
$routes->post('workspace/distribution/requests/(:num)/transmit', 'Web\\DistributionController::transmit/$1', ['filter' => 'csrf']);
$routes->post('workspace/distribution/requests/(:num)/complete', 'Web\\DistributionController::complete/$1', ['filter' => 'csrf']);
$routes->post('workspace/distribution/requests/(:num)/incomplete', 'Web\\DistributionController::incomplete/$1', ['filter' => 'csrf']);
$routes->post('workspace/distribution/direct-send', 'Web\\DistributionController::directSend', ['filter' => 'csrf']);
$routes->get('workspace/distribution/requests/(:num)/files/(:num)/preview', 'Web\\DistributionController::reviewPreview/$1/$2');
$routes->get('workspace/notifications', 'Web\\NotificationController::index');
$routes->post('notifications/read-all', 'Web\\NotificationController::readAll', ['filter' => 'csrf']);
$routes->post('notifications/(:num)/read', 'Web\\NotificationController::read/$1', ['filter' => 'csrf']);
$routes->get('workspace/audit-logs', 'Web\\AddonsController::auditLogs');
$routes->post('logout', 'Web\\AuthController::logout', ['filter' => 'csrf']);

$routes->group('api/v1', ['namespace' => 'App\\Controllers\\Api', 'filter' => 'apiCors'], static function (RouteCollection $routes): void {
    $routes->get('health', 'HealthController::index');
    $routes->post('auth/login', 'AuthController::login');
    $routes->options('', 'PreflightController::index');
    $routes->options('(:any)', 'PreflightController::index');
    // Published previews are public media assets; the controller still rejects
    // draft, withdrawn, deleted, and unsupported records.
    $routes->get('mobile/media/(:num)/thumbnail', 'MobileFeedController::thumbnail/$1');
    $routes->get(
        'mobile/media/(:num)/preview',
        'MobileFeedController::preview/$1'
    );
    $routes->get(
        'mobile/media/(:num)/stream',
        'MobileFeedController::stream/$1'
    );
    $routes->head(
        'mobile/media/(:num)/stream',
        'MobileFeedController::stream/$1'
    );
    $routes->get('mobile/home', 'MobileFeedController::home');
    $routes->get('mobile/featured', 'MobileFeaturedController::index');
    $routes->get('mobile/shorts', 'MobileFeedController::shorts');
    $routes->get('mobile/channels', 'MobileFeedController::channels');
    $routes->get('mobile/search', 'MobileFeedController::search');
    $routes->get('mobile/media/(:num)/comments', 'MobileInteractionController::comments/$1');
    $routes->get('mobile/profile/avatar/(:num)', 'MobileProfileController::avatar/$1');

    $routes->group('', ['filter' => 'apiAuth'], static function (RouteCollection $routes): void {
        $routes->get('auth/me', 'AuthController::me');
        $routes->post('auth/logout', 'AuthController::logout');
        $routes->get('mobile/media/(:num)/engagement', 'MobileInteractionController::engagement/$1');
        $routes->post('mobile/media/(:num)/like', 'MobileInteractionController::like/$1');
        $routes->post('mobile/media/(:num)/dislike', 'MobileInteractionController::dislike/$1');
        $routes->post('mobile/media/(:num)/comments', 'MobileInteractionController::addComment/$1');
        $routes->get('mobile/channels/(:segment)/subscription', 'MobileInteractionController::subscription/$1');
        $routes->post('mobile/channels/(:segment)/subscription', 'MobileInteractionController::subscription/$1');
        $routes->get('mobile/media/(:num)/download', 'MobileFeedController::download/$1');
        $routes->get('mobile/search/history', 'MobileSearchController::history');
        $routes->post('mobile/search/history', 'MobileSearchController::remember');
        $routes->get('mobile/library', 'MobileFeedController::library');
        $routes->get('mobile/admin/posts', 'MobilePostController::index');
        $routes->get('mobile/admin/posts/(:num)', 'MobilePostController::show/$1');
        $routes->post('mobile/admin/posts/(:num)/unpublish', 'MobilePostController::unpublish/$1');
        $routes->post('mobile/admin/featured', 'MobileFeaturedController::save');
        $routes->post('mobile/admin/posts/(:num)', 'MobilePostController::update/$1');
        $routes->post('mobile/admin/posts/(:num)/publish', 'MobilePostController::publish/$1');
        $routes->get('mobile/admin/media/(:num)/thumbnail', 'MobilePostController::thumbnail/$1');
        $routes->get('mobile/profile', 'MobileProfileController::show');
        $routes->patch('mobile/profile', 'MobileProfileController::update');
        $routes->post('mobile/profile/avatar', 'MobileProfileController::uploadAvatar');
        $routes->post('mobile/history/watch', 'MobileHistoryController::watch');
        $routes->get('mobile/history/watch', 'MobileHistoryController::watchList');
        $routes->delete('mobile/history/watch', 'MobileHistoryController::clearWatch');
        $routes->post('mobile/history/download', 'MobileHistoryController::download');
        $routes->get('mobile/history/download', 'MobileHistoryController::downloadList');
        $routes->delete('mobile/history/download', 'MobileHistoryController::clearDownload');
        $routes->get('files', 'FileController::index');
        $routes->post('files', 'FileController::upload');
        $routes->get('files/(:num)', 'FileController::show/$1');
        $routes->patch('files/(:num)', 'FileController::update/$1');
        $routes->post('files/(:num)/move', 'FileController::move/$1');
        $routes->post('files/(:num)/trash', 'FileController::trash/$1');
        $routes->post('files/(:num)/restore', 'FileController::restore/$1');
        $routes->get('files/(:num)/download', 'FileController::download/$1');

        $routes->get('folders', 'FolderController::index');
        $routes->post('folders', 'FolderController::create');

        $routes->get('tags', 'TagController::index');
        $routes->put('files/(:num)/tags', 'TagController::replace/$1');
        $routes->get('recent', 'RecentController::index');
        $routes->get('audit-logs', 'AuditLogController::index');
    });
});

$routes->post('workspace/file-management/(:num)/viewed', 'Web\\WorkspaceController::viewed/$1', ['filter'=>'recentCsrf']);
$routes->post('workspace/server-library/items/(:segment)/viewed', 'Web\\ServerLibraryController::viewed/$1', ['filter'=>'recentCsrf']);

$routes->get('workspace/search-suggestions/(:segment)', 'Web\\SearchSuggestionsController::index/$1');

$routes->post('workspace/ifound/analyze-selected', 'Web\\WorkspaceController::analyzeSelectedImages', ['filter' => 'csrf']);
$routes->get('workspace/ifound/analysis-status', 'Web\\WorkspaceController::selectedAnalysisStatus');

$routes->post('workspace/ifound/visual-analysis', 'Web\\VisualAnalysisController::analyze', ['filter' => 'csrf']);
