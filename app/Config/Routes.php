<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('/', 'Web\\AuthController::loginForm');
$routes->get('login', 'Web\\AuthController::loginForm');
$routes->post('login', 'Web\\AuthController::login', ['filter' => 'csrf']);
$routes->get('dashboard', 'Web\\DashboardController::index');
$routes->get('admin/control-center', 'Web\\AdminController::controlCenter');
$routes->post('admin/users', 'Web\\AdminController::createUser', ['filter' => 'csrf']);
$routes->post('admin/users/(:num)/update', 'Web\\AdminController::updateUser/$1', ['filter' => 'csrf']);
$routes->post('admin/users/(:num)/toggle', 'Web\\AdminController::toggleUser/$1', ['filter' => 'csrf']);
$routes->post('admin/users/(:num)/reset-password', 'Web\\AdminController::resetPassword/$1', ['filter' => 'csrf']);
$routes->post('admin/users/(:num)/revoke-tokens', 'Web\\AdminController::revokeTokens/$1', ['filter' => 'csrf']);
$routes->post('admin/maintenance/consent-reminders', 'Web\\AdminController::sendConsentReminders', ['filter' => 'csrf']);
$routes->post('admin/maintenance/purge-expired-trash', 'Web\\AdminController::purgeExpiredTrash', ['filter' => 'csrf']);
$routes->post('privacy/consent', 'Web\\PrivacyController::accept', ['filter' => 'csrf']);
$routes->get('workspace/file-management', 'Web\\WorkspaceController::media');
$routes->post('workspace/file-management/folders', 'Web\\WorkspaceController::createFolder', ['filter' => 'csrf']);
$routes->post('workspace/file-management/folders/(:num)/rename', 'Web\\WorkspaceController::renameFolder/$1', ['filter' => 'csrf']);
$routes->post('workspace/file-management/folders/(:num)/delete', 'Web\\WorkspaceController::deleteFolder/$1', ['filter' => 'csrf']);
$routes->post('workspace/file-management/upload', 'Web\\WorkspaceController::upload', ['filter' => 'csrf']);
$routes->post('workspace/file-management/tags', 'Web\\WorkspaceController::addTags', ['filter' => 'csrf']);
$routes->get('workspace/file-management/duplicates', 'Web\\WorkspaceController::duplicateQueue');
$routes->post('workspace/file-management/bulk-download', 'Web\\WorkspaceController::bulkDownload', ['filter' => 'csrf']);
$routes->post('workspace/file-management/bulk-trash', 'Web\\WorkspaceController::bulkTrash', ['filter' => 'csrf']);
$routes->post('workspace/file-management/post', 'Web\\WorkspaceController::postMedia', ['filter' => 'csrf']);
$routes->get('workspace/published-files', 'Web\\WorkspaceController::publishedFiles');
$routes->get('workspace/published-files/(:num)', 'Web\\WorkspaceController::publishedFiles/$1');
$routes->get('workspace/file-management/(:num)/preview', 'Web\\WorkspaceController::preview/$1');
$routes->get('workspace/file-management/(:num)/video-preview', 'Web\\WorkspaceController::videoPreview/$1');
$routes->get('workspace/file-management/(:num)/watermarked-preview', 'Web\\WorkspaceController::watermarkedPreview/$1');
$routes->get('workspace/file-management/(:num)/download', 'Web\\WorkspaceController::download/$1');
$routes->post('workspace/file-management/(:num)/trash', 'Web\\WorkspaceController::moveToTrash/$1', ['filter' => 'csrf']);
$routes->get('workspace/recent', 'Web\\WorkspaceController::recent');
$routes->get('workspace/ifound', 'Web\\WorkspaceController::ifound');
$routes->post('workspace/ifound/(:num)/review', 'Web\\WorkspaceController::reviewEvent/$1', ['filter' => 'csrf']);
$routes->get('workspace/downloads', 'Web\\WorkspaceController::downloads');
$routes->get('workspace/trash', 'Web\\WorkspaceController::trash');
$routes->get('workspace/trash/(:num)/preview', 'Web\\WorkspaceController::trashPreview/$1');
$routes->post('workspace/trash/(:num)/restore', 'Web\\WorkspaceController::restoreFromTrash/$1', ['filter' => 'csrf']);
$routes->post('workspace/trash/(:num)/delete', 'Web\\WorkspaceController::permanentlyDeleteFromTrash/$1', ['filter' => 'csrf']);
$routes->post('workspace/trash/bulk-restore', 'Web\\WorkspaceController::bulkRestoreTrash', ['filter' => 'csrf']);
$routes->post('workspace/trash/bulk-delete', 'Web\\WorkspaceController::bulkDeleteTrash', ['filter' => 'csrf']);
$routes->get('workspace/passports', 'Web\\AddonsController::passports');
$routes->post('workspace/passports/backfill', 'Web\\PassportController::backfill', ['filter' => 'csrf']);
$routes->post('workspace/passports/analyze', 'Web\\PassportController::reanalyze', ['filter' => 'csrf']);
$routes->get('workspace/watermarking', 'Web\\AddonsController::watermarking');
$routes->get('workspace/watermarking/logo/(:segment)', 'Web\\AddonsController::watermarkLogo/$1');
$routes->post('workspace/watermarking/settings', 'Web\\AddonsController::updateWatermarking', ['filter' => 'csrf']);
$routes->post('workspace/watermarking/rebuild', 'Web\\AddonsController::rebuildDefaultWatermarks', ['filter' => 'csrf']);
$routes->post('workspace/watermarking/(:num)/download', 'Web\\AddonsController::downloadWithLogo/$1', ['filter' => 'csrf']);
$routes->get('workspace/distribution', 'Web\\DistributionController::index');
$routes->post('workspace/distribution/requests', 'Web\\DistributionController::requestMedia', ['filter' => 'csrf']);
$routes->post('workspace/distribution/requests/(:num)/transmit', 'Web\\DistributionController::transmit/$1', ['filter' => 'csrf']);
$routes->post('workspace/distribution/requests/(:num)/complete', 'Web\\DistributionController::complete/$1', ['filter' => 'csrf']);
$routes->post('workspace/distribution/direct-send', 'Web\\DistributionController::directSend', ['filter' => 'csrf']);
$routes->get('workspace/notifications', 'Web\\NotificationController::index');
$routes->post('notifications/(:num)/read', 'Web\\NotificationController::read/$1', ['filter' => 'csrf']);
$routes->get('workspace/audit-logs', 'Web\\AddonsController::auditLogs');
$routes->get('workspace/(:segment)', 'Web\\FeatureController::show/$1');
$routes->post('logout', 'Web\\AuthController::logout', ['filter' => 'csrf']);

$routes->group('api/v1', ['namespace' => 'App\\Controllers\\Api', 'filter' => 'apiCors'], static function (RouteCollection $routes): void {
    $routes->get('health', 'HealthController::index');
    $routes->post('auth/login', 'AuthController::login');
    $routes->options('', 'PreflightController::index');
    $routes->options('(:any)', 'PreflightController::index');
    // Published previews are public media assets; the controller still rejects
    // draft, withdrawn, deleted, and non-image records.
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
        $routes->post('mobile/media/(:num)/comments', 'MobileInteractionController::addComment/$1');
        $routes->get('mobile/channels/(:segment)/subscription', 'MobileInteractionController::subscription/$1');
        $routes->post('mobile/channels/(:segment)/subscription', 'MobileInteractionController::subscription/$1');
        $routes->get('mobile/media/(:num)/download', 'MobileFeedController::download/$1');
        $routes->get('mobile/search/history', 'MobileSearchController::history');
        $routes->post('mobile/search/history', 'MobileSearchController::remember');
        $routes->get('mobile/profile', 'MobileProfileController::show');
        $routes->patch('mobile/profile', 'MobileProfileController::update');
        $routes->post('mobile/profile/avatar', 'MobileProfileController::uploadAvatar');
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
