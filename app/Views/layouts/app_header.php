<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title><?= esc($title ?? 'Greyhound Hub') ?></title>
    <link href="<?= esc(base_url('assets/img/logoo.png')) ?>" rel="icon">
    <link href="<?= esc(base_url('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= esc(base_url('assets/vendor/bootstrap-icons/bootstrap-icons.css')) ?>" rel="stylesheet">
    <link href="<?= esc(base_url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<header id="header" class="header fixed-top d-flex align-items-center">
    <button class="toggle-sidebar-btn header-menu-button" type="button" aria-label="Toggle sidebar" aria-controls="sidebar" aria-expanded="true">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <a href="<?= esc(site_url('dashboard')) ?>" class="logo d-flex align-items-center">
        <img src="<?= esc(base_url('assets/img/logoo.png')) ?>" alt="Greyhound Hub">
        <span class="d-none d-sm-block">Greyhound Hub</span>
    </a>
    <?php
        $headerNotifications = [];
        $unreadNotificationCount = 0;
        if (! empty($user['employee_id']) && db_connect()->tableExists('notifications')) {
            $headerNotifications = db_connect()->table('notifications')->where('recipient_id', (string) $user['employee_id'])->orderBy('created_at', 'DESC')->limit(8)->get()->getResultArray();
            $unreadNotificationCount = db_connect()->table('notifications')->where('recipient_id', (string) $user['employee_id'])->where('is_read', 0)->countAllResults();
        }
        $role = \App\Libraries\RolePolicy::role($user ?? []);
    ?>
    <nav class="header-nav ms-auto" aria-label="Header actions">
        <ul class="d-flex align-items-center">
            <li class="nav-item dropdown">
                <button class="nav-link nav-icon mb-0 border-0 bg-transparent position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications"><i class="bi bi-bell"></i><?php if ($unreadNotificationCount > 0): ?><span class="badge bg-primary badge-number"><?= min(99, $unreadNotificationCount) ?></span><?php endif; ?></button>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow notifications" style="min-width:330px"><li class="dropdown-header"><?= $unreadNotificationCount ?> unread notification<?= $unreadNotificationCount === 1 ? '' : 's' ?></li><li><hr class="dropdown-divider"></li><?php foreach ($headerNotifications as $notification): ?><li class="notification-item px-3 py-2"><form action="<?= esc(site_url('notifications/' . (int) $notification['id'] . '/read')) ?>" method="post"><?= csrf_field() ?><button class="btn text-start p-0 w-100" type="submit"><strong class="d-block small"><?= esc($notification['title']) ?></strong><span class="small text-muted"><?= nl2br(esc($notification['message'])) ?></span><span class="d-block text-muted" style="font-size:.7rem"><?= esc(date('M j, g:i A', strtotime((string) $notification['created_at']))) ?></span></button></form></li><li><hr class="dropdown-divider"></li><?php endforeach; ?><?php if ($headerNotifications === []): ?><li class="px-3 py-3 small text-muted">No notifications yet.</li><?php endif; ?></ul>
            </li>
        </ul>
    </nav>
</header>
<aside id="sidebar" class="sidebar" aria-label="Main navigation">
    <ul class="sidebar-nav" id="sidebar-nav">
        <?php $nav = ['dashboard' => ['Dashboard', 'bi-grid', site_url('dashboard')], 'file-management' => [$role === 'department' ? 'Authorized Files' : 'File Management', 'bi-house-door', site_url('workspace/file-management')]]; ?>
        <?php foreach ($nav as $key => [$label, $icon, $url]): ?>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === $key ? '' : 'collapsed' ?>" href="<?= esc($url) ?>"><i class="bi <?= esc($icon) ?>"></i><span><?= esc($label) ?></span></a></li>
        <?php endforeach; ?>
        <?php if (\App\Libraries\RolePolicy::allows($user ?? [], 'files.manage')): ?>
            <li class="nav-heading">File Management</li>
            <?php foreach (['raw' => 'Raw Files', 'processed' => 'Processed Files', 'published' => 'Published Files', 'department' => 'Department Assigned'] as $stageKey => $stageLabel): ?><li class="nav-item"><a class="nav-link collapsed" href="<?= esc(site_url('workspace/file-management?stage=' . $stageKey)) ?>"><i class="bi bi-folder2"></i><span><?= esc($stageLabel) ?></span></a></li><?php endforeach; ?>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'duplicates' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/file-management/duplicates')) ?>"><i class="bi bi-copy"></i><span>Duplicate Queue</span></a></li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'trash' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/trash')) ?>"><i class="bi bi-trash"></i><span>Trash</span></a></li>
            <li class="nav-heading">Creatives AI Tools</li>
            <?php foreach (['passports' => ['Metadata Passports', 'bi-patch-check'], 'watermarking' => ['Watermarking', 'bi-droplet']] as $key => [$label, $icon]): ?><li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === $key ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/' . $key)) ?>"><i class="bi <?= esc($icon) ?>"></i><span><?= esc($label) ?></span></a></li><?php endforeach; ?>
        <?php endif; ?>
        <?php if (\App\Libraries\RolePolicy::allows($user ?? [], 'distribution.manage') || \App\Libraries\RolePolicy::allows($user ?? [], 'requests.manage_own')): ?><li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'distribution' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/distribution')) ?>"><i class="bi bi-send-check"></i><span><?= $role === 'department' ? 'My Requests' : 'Distribution Workflow' ?></span></a></li><?php endif; ?>
        <?php if (\App\Libraries\RolePolicy::isSuperAdmin($user ?? [])): ?>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'audit-logs' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/audit-logs')) ?>"><i class="bi bi-journal-text"></i><span>Audit Logs</span></a></li>
            <li class="nav-heading">Administration</li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'admin-control' ? '' : 'collapsed' ?>" href="<?= esc(site_url('admin/control-center')) ?>"><i class="bi bi-shield-lock"></i><span>Admin Control Center</span></a></li>
        <?php endif; ?>
    </ul>
    <div class="sidebar-profile dropup">
        <button class="sidebar-profile-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open user menu">
            <img src="<?= esc(base_url('assets/img/profile-img.jpg')) ?>" alt="" class="rounded-circle">
            <span class="sidebar-profile-copy"><strong><?= esc((string) ($user['name'] ?? 'User')) ?></strong><small><?= esc((string) ($user['role'] ?? 'guest')) ?></small></span>
            <i class="bi bi-three-dots-vertical sidebar-profile-more" aria-hidden="true"></i>
        </button>
        <ul class="dropdown-menu sidebar-profile-menu">
            <li class="dropdown-header"><h6><?= esc((string) ($user['name'] ?? 'User')) ?></h6><span><?= esc((string) ($user['role'] ?? 'guest')) ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><form action="<?= esc(site_url('logout')) ?>" method="post"><?= csrf_field() ?><button class="dropdown-item d-flex align-items-center" type="submit"><i class="bi bi-box-arrow-right me-2"></i><span>Sign Out</span></button></form></li>
        </ul>
    </div>
</aside>
