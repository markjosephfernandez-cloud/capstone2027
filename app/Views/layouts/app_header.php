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
        $role = \App\Libraries\RolePolicy::role($user ?? []);
        $headerNotifications = [];
        $unreadNotificationCount = 0;
        if (! empty($user['employee_id']) && db_connect()->tableExists('notifications')) {
            $unreadNotificationCount = db_connect()->table('notifications')->where('recipient_id', (string) $user['employee_id'])->where('is_read', 0)->countAllResults();
            if ($role !== 'department') {
                $headerNotifications = db_connect()->table('notifications')->where('recipient_id', (string) $user['employee_id'])->orderBy('created_at', 'DESC')->limit(8)->get()->getResultArray();
            }
        }
        $roleLabel = match ($role) {
            'super_admin' => 'Super Admin',
            'admin', 'staff' => 'Admin',
            'writer' => 'Writer',
            'department' => 'Department',
            default => ucfirst(str_replace('_', ' ', $role)),
        };
    ?>
    <?php if ($role !== 'department'): ?>
        <nav class="header-nav ms-auto" aria-label="Header actions">
            <ul class="d-flex align-items-center">
                <li class="nav-item dropdown">
                    <button class="nav-link nav-icon mb-0 border-0 bg-transparent position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications"><i class="bi bi-bell"></i><?php if ($unreadNotificationCount > 0): ?><span class="badge bg-primary badge-number"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span><?php endif; ?></button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow notifications">
                        <li class="notification-menu-header">
                            <div><strong>Notifications</strong><span><?= esc(number_format($unreadNotificationCount)) ?> unread</span></div>
                            <?php if ($unreadNotificationCount > 0): ?><form action="<?= esc(site_url('notifications/read-all')) ?>" method="post"><?= csrf_field() ?><button class="notification-read-all" type="submit"><i class="bi bi-check2-all me-1"></i>Read all</button></form><?php endif; ?>
                        </li>
                        <li class="notification-scroll"><ul class="list-unstyled mb-0">
                            <?php foreach ($headerNotifications as $notification): ?><li class="notification-item"><form action="<?= esc(site_url('notifications/' . (int) $notification['id'] . '/read')) ?>" method="post"><?= csrf_field() ?><button class="notification-button" type="submit"><strong><?= esc($notification['title']) ?></strong><span><?= nl2br(esc($notification['message'])) ?></span><time datetime="<?= esc(\App\Libraries\DisplayTime::format($notification['created_at'], 'c', '')) ?>"><?= esc(\App\Libraries\DisplayTime::format($notification['created_at'], 'M j, g:i A')) ?></time></button></form></li><?php endforeach; ?>
                            <?php if ($headerNotifications === []): ?><li class="notification-empty"><i class="bi bi-bell-slash"></i><span>No notifications yet.</span></li><?php endif; ?>
                        </ul></li>
                        <li class="notification-menu-footer"><a href="<?= esc(site_url('workspace/notifications')) ?>">View all notifications <i class="bi bi-arrow-right ms-1"></i></a></li>
                    </ul>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</header>
<aside id="sidebar" class="sidebar" aria-label="Main navigation">
    <ul class="sidebar-nav" id="sidebar-nav">
        <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'dashboard' ? '' : 'collapsed' ?>" href="<?= esc(site_url('dashboard')) ?>"><i class="bi bi-grid"></i><span>Dashboard</span></a></li>
        <?php if (\App\Libraries\RolePolicy::allows($user ?? [], 'files.manage')): ?>
            <li class="nav-heading">File Management</li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'file-management' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/server-library')) ?>"><i class="bi bi-hdd-network"></i><span>File Management</span></a></li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'recent' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/recent')) ?>"><i class="bi bi-clock-history"></i><span>Recent</span></a></li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'ifound' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/ifound')) ?>"><i class="bi bi-search-heart"></i><span>iFound</span></a></li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'trash' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/trash')) ?>"><i class="bi bi-trash"></i><span>Trash</span></a></li>
            <li class="nav-heading">Creatives AI Tools</li>
            <?php foreach (['passports' => ['Metadata Passports', 'bi-patch-check'], 'watermarking' => ['Watermarking', 'bi-droplet']] as $key => [$label, $icon]): ?><li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === $key ? '' : 'collapsed' ?>" href="<?= esc(site_url($key === 'watermarking' ? 'workspace/server-library/watermarking' : 'workspace/' . $key)) ?>"><i class="bi <?= esc($icon) ?>"></i><span><?= esc($label) ?></span></a></li><?php endforeach; ?>
            <li class="nav-item">
                <a class="nav-link <?= in_array($activePage ?? '', ['distribution', 'published-files'], true) ? '' : 'collapsed' ?>" data-bs-target="#release-media-nav" data-bs-toggle="collapse" href="#">
                    <i class="bi bi-send-check"></i><span>Release Media</span><i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <ul id="release-media-nav" class="nav-content collapse <?= in_array($activePage ?? '', ['distribution', 'published-files'], true) ? 'show' : '' ?>" data-bs-parent="#sidebar-nav">
                    <?php if (\App\Libraries\RolePolicy::allows($user ?? [], 'distribution.manage') || \App\Libraries\RolePolicy::allows($user ?? [], 'requests.manage_own')): ?>
                        <li>
                            <a href="<?= esc(site_url('workspace/distribution')) ?>" class="<?= ($activePage ?? '') === 'distribution' ? 'active' : '' ?>">
                                <i class="bi bi-circle"></i><span><?= $role === 'department' ? 'My Requests' : 'Distribution Workflow' ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <li>
                        <a href="<?= esc(site_url('workspace/published-files')) ?>" class="<?= ($activePage ?? '') === 'published-files' ? 'active' : '' ?>">
                            <i class="bi bi-circle"></i><span>Published Files</span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'audit-logs' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/audit-logs')) ?>"><i class="bi bi-journal-text"></i><span>Audit Logs</span></a></li>
        <?php elseif ($role === 'department'): ?>
            <li class="nav-heading">File Management</li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'file-management' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/file-management')) ?>"><i class="bi bi-house-door"></i><span>Authorized Files</span></a></li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'distribution' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/distribution?tab=requests')) ?>"><i class="bi bi-inboxes"></i><span>My Requests</span></a></li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'notifications' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/notifications')) ?>"><i class="bi bi-bell"></i><span>Notifications</span><?php if ($unreadNotificationCount > 0): ?><span class="sidebar-notification-badge" aria-label="<?= esc((string) $unreadNotificationCount) ?> unread notifications"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span><?php endif; ?></a></li>
        <?php elseif ($role === 'writer'): ?>
            <li class="nav-heading">File Management</li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'file-management' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/server-library')) ?>"><i class="bi bi-hdd-network"></i><span>File Management</span></a></li>
        <?php endif; ?>
        <?php if ((new \Config\Storage())->centralized() && in_array($role,['writer','department'],true)): ?>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'recent' ? '' : 'collapsed' ?>" href="<?= esc(site_url('workspace/recent')) ?>"><i class="bi bi-clock-history"></i><span>Recent</span></a></li>
        <?php endif; ?>
        <?php if (\App\Libraries\RolePolicy::isSuperAdmin($user ?? [])): ?>
            <li class="nav-heading">Administration</li>
            <li class="nav-item"><a class="nav-link <?= ($activePage ?? '') === 'admin-control' ? '' : 'collapsed' ?>" href="<?= esc(site_url('admin/control-center')) ?>"><i class="bi bi-shield-lock"></i><span>Admin Control Center</span></a></li>
        <?php endif; ?>
    </ul>
    <div class="sidebar-profile dropup">
        <button class="sidebar-profile-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open user menu">
            <img src="<?= esc(base_url('assets/img/profile-img.jpg')) ?>" alt="" class="rounded-circle">
            <span class="sidebar-profile-copy"><strong><?= esc((string) ($user['name'] ?? 'User')) ?></strong><small><?= esc($roleLabel) ?></small></span>
            <i class="bi bi-three-dots-vertical sidebar-profile-more" aria-hidden="true"></i>
        </button>
        <ul class="dropdown-menu sidebar-profile-menu">
            <li class="dropdown-header"><h6><?= esc((string) ($user['name'] ?? 'User')) ?></h6><span><?= esc($roleLabel) ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><form action="<?= esc(site_url('logout')) ?>" method="post" data-logout-token-url="<?= esc(site_url('logout/token')) ?>"><?= csrf_field() ?><button class="dropdown-item d-flex align-items-center" type="submit"><i class="bi bi-box-arrow-right me-2"></i><span>Sign Out</span></button></form></li>
        </ul>
    </div>
</aside>
