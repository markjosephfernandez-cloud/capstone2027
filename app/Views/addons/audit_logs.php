<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<?php
$roleLabel = static function (?string $role): string {
    $role = strtolower(trim((string) $role));
    return match ($role) {
        'super_admin' => 'Super Admin',
        'admin', 'staff' => 'Admin',
        'writer' => 'Writer',
        'department' => 'Department',
        'school_user' => 'School User',
        'system' => 'System',
        default => $role === '' ? 'Unknown' : ucwords(str_replace('_', ' ', $role)),
    };
};
$actionLabel = static function (?string $action): string {
    return ucwords(strtolower(str_replace('_', ' ', (string) $action)));
};
?>
<main id="main" class="main">
    <div class="pagetitle">
        <h1>Audit Logs</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Audit Logs</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h5 class="card-title mb-1">System activity</h5>
                        <p class="text-muted small mb-0">
                            <?= ! empty($isSuperAdmin) ? 'Super Admin view includes all users and modules.' : 'Creative Staff view includes permitted operational activity.' ?>
                        </p>
                    </div>
                    <span class="badge bg-primary rounded-pill"><?= count($logs) ?> shown</span>
                </div>

                <form class="row g-2 my-3" method="get" data-ajax-live-search>
                    <div class="col-lg-3 col-md-6">
                        <label class="visually-hidden" for="auditUser">User</label>
                        <input class="form-control" id="auditUser" name="user" placeholder="User name or employee ID" value="<?= esc($filterUser) ?>">
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="visually-hidden" for="auditSearch">Search audit logs</label>
                        <input class="form-control" id="auditSearch" name="q" placeholder="Search action, target, description" value="<?= esc($query) ?>">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="visually-hidden" for="auditModule">Module</label>
                        <select class="form-select" id="auditModule" name="module">
                            <option value="">All modules</option>
                            <?php foreach ($modules as $availableModule): ?>
                                <option value="<?= esc((string) $availableModule['module']) ?>" <?= $module === $availableModule['module'] ? 'selected' : '' ?>>
                                    <?= esc(ucfirst((string) $availableModule['module'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="visually-hidden" for="auditAction">Action</label>
                        <select class="form-select" id="auditAction" name="action">
                            <option value="">All actions</option>
                            <?php foreach ($actions as $availableAction): ?>
                                <option value="<?= esc((string) $availableAction['action']) ?>" <?= $action === $availableAction['action'] ? 'selected' : '' ?>>
                                    <?= esc($actionLabel((string) $availableAction['action'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4 d-grid">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Target</th>
                                <th>Description</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="text-nowrap"><?= esc(date('M d, Y h:i A', strtotime((string) $log['created_at']))) ?></td>
                                    <td>
                                        <strong><?= esc($log['user_name'] ?: $log['user_id'] ?: 'System') ?></strong>
                                        <?php if (! empty($log['department'])): ?>
                                            <div class="small text-muted"><?= esc((string) $log['department']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= esc($roleLabel($log['actor_role'] ?? null)) ?></span></td>
                                    <td>
                                        <span class="badge bg-secondary"><?= esc($actionLabel($log['action'] ?? '')) ?></span>
                                        <?php if (! empty($log['module'])): ?><div class="small text-muted mt-1"><?= esc((string) $log['module']) ?></div><?php endif; ?>
                                    </td>
                                    <td><?= esc((string) ($log['target_label'] ?? 'System')) ?></td>
                                    <td style="min-width: 280px;"><?= esc($log['description'] ?: '-') ?></td>
                                    <td class="text-muted small"><?= esc($log['ip_address'] ?: '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($logs === []): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No audit logs match your filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</main>
<?= view('layouts/app_footer') ?>
