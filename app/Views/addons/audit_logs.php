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
<style>
    .audit-page .audit-card { border: 1px solid #e9edf5; border-radius: 15px; box-shadow: 0 5px 18px rgba(45,60,100,.06); }
    .audit-page .audit-header { align-items: center; display: flex; flex-wrap: wrap; gap: .75rem; justify-content: space-between; padding-top: 1.25rem; }
    .audit-page .audit-header h2 { color: #23304d; font-size: 1rem; font-weight: 700; margin: 0 0 .35rem; }
    .audit-page .audit-count, .audit-page .audit-badge { background: #f2f4f8; border-radius: 5px; color: #637088; display: inline-block; font-size: .7rem; font-weight: 600; padding: .2rem .5rem; }
    .audit-page .audit-action { background: #eff2ff; color: #4154f1; }
    .audit-page .audit-filter-picker { position: relative; min-width: 0; }
    .audit-page .audit-filter-picker summary { cursor: pointer; list-style: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .audit-page .audit-filter-picker summary::-webkit-details-marker { display: none; }
    .audit-page .audit-filter-picker summary:focus-visible { outline: 2px solid #4154f1; outline-offset: 2px; }
    .audit-page .audit-filter-menu { position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 30; padding: .5rem; border: 1px solid #dce3f1; border-radius: 10px; background: #fff; box-shadow: 0 8px 24px rgba(26,42,70,.16); }
    .audit-page .audit-filter-options { max-height: min(240px, 35vh); overflow-y: auto; overscroll-behavior: contain; margin-top: .5rem; }
    .audit-page .audit-filter-option { display: block; width: 100%; border: 0; border-radius: 6px; padding: .5rem .65rem; background: transparent; color: #23304d; text-align: left; overflow-wrap: anywhere; }
    .audit-page .audit-filter-option[hidden] { display: none; }
    .audit-page .audit-filter-option:hover, .audit-page .audit-filter-option:focus-visible { background: #f2f4f8; }
    .audit-page .audit-filter-option[aria-pressed="true"] { background: #eff2ff; color: #4154f1; font-weight: 600; }
    .audit-page .audit-list { border-top: 1px solid #e9edf5; list-style: none; margin: 0; padding: 0; }
    .audit-page .audit-item { align-items: start; border-bottom: 1px solid #eef1f6; display: grid; gap: .85rem; grid-template-columns: 34px minmax(0,1fr) auto; padding: 1rem 0; }
    .audit-page .audit-item:last-child { border-bottom: 0; }
    .audit-page .audit-icon { align-items: center; background: #eff2ff; border-radius: 10px; color: #4154f1; display: flex; height: 34px; justify-content: center; width: 34px; }
    .audit-page .audit-heading { align-items: center; display: flex; flex-wrap: wrap; gap: .5rem; }
    .audit-page .audit-user { color: #23304d; font-size: .85rem; font-weight: 650; overflow-wrap: anywhere; }
    .audit-page .audit-meta { color: #7b869b; font-size: .72rem; margin-top: .25rem; overflow-wrap: anywhere; }
    .audit-page .audit-description { color: #637088; font-size: .82rem; line-height: 1.6; margin: .35rem 0; overflow-wrap: anywhere; }
    .audit-page .audit-time { color: #7b869b; font-size: .72rem; padding-top: .15rem; white-space: nowrap; }
    .audit-page .audit-details { font-size: .78rem; }
    .audit-page .audit-details summary { color: #4154f1; cursor: pointer; width: fit-content; }
    .audit-page .audit-details summary:focus-visible { border-radius: 3px; outline: 2px solid #4154f1; outline-offset: 4px; }
    .audit-page .audit-full { background: #f8f9fc; border: 1px solid #e9edf5; border-radius: 8px; color: #536078; line-height: 1.7; margin-top: .65rem; max-height: 280px; overflow: auto; overflow-wrap: anywhere; padding: .85rem 1rem; }
    .audit-page .audit-full dl { display: grid; gap: .45rem 1rem; grid-template-columns: 95px minmax(0,1fr); margin: 0; }
    .audit-page .audit-full dt { color: #637088; font-weight: 600; }
    .audit-page .audit-full dd { margin: 0; white-space: pre-wrap; }
    @media (max-width: 575.98px) {
        .audit-page .audit-item { gap: .45rem .7rem; grid-template-columns: 30px minmax(0,1fr); }
        .audit-page .audit-icon { height: 30px; width: 30px; }
        .audit-page .audit-time { grid-column: 2; padding: 0; }
        .audit-page .audit-full dl { grid-template-columns: minmax(0,1fr); gap: .15rem; }
        .audit-page .audit-full dd { margin-bottom: .6rem; }
    }
</style>
<main id="main" class="main audit-page">
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
        <div class="card audit-card">
            <div class="card-body">
                <div class="audit-header">
                    <div>
                        <h2>System activity</h2>
                        <p class="text-muted small mb-0">
                            <?= ! empty($isSuperAdmin) ? 'Super Admin view includes all users and modules.' : 'Only your own account activity is shown.' ?>
                        </p>
                    </div>
                    <span class="audit-count"><?= count($logs) ?> shown &middot; Times in PHT</span>
                </div>

                <?php if (isset($selectedLogId)): ?>
                    <a class="d-inline-block mt-3" href="<?= esc(site_url('workspace/audit-logs'), 'attr') ?>">View all audit logs</a>
                <?php endif; ?>

                <form class="row g-2 my-3" method="get" data-ajax-live-search>
                    <?php if (! empty($isSuperAdmin)): ?>
                    <div class="col-lg-6 col-md-6">
                        <label class="visually-hidden" for="auditUser">User</label>
                        <input class="form-control" id="auditUser" data-search-suggestions="<?= esc(site_url('workspace/search-suggestions/audit-users')) ?>" name="user" placeholder="User name or employee ID" value="<?= esc($filterUser) ?>">
                    </div>
                    <?php endif; ?>
                    <?php if (empty($isSuperAdmin)): ?>
                    <div class="col-lg-6 col-md-6">
                        <label class="visually-hidden" for="auditSearch">Search audit logs</label>
                        <input class="form-control" id="auditSearch" data-search-suggestions="<?= esc(site_url('workspace/search-suggestions/audit')) ?>" name="q" placeholder="Search action, target, description" value="<?= esc($query) ?>">
                    </div>
                    <?php endif; ?>
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

                <ol class="audit-list" aria-label="Audit events">
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $description = (string) ($log['description'] ?: '-');
                        $description = \App\Libraries\TrashAuditDescription::format((string) ($log['action'] ?? ''), $description);
                        $isLibraryUpload = ($log['action'] ?? '') === 'SERVER_LIBRARY_UPLOAD';
                        if ($isLibraryUpload) {
                            $description = \App\Libraries\UploadAuditDescription::format($description);
                        }
                        $isLibraryPost = str_starts_with($description, 'Server Library post:');
                        $preview = $isLibraryPost ? 'Posted media to the server library.' : (mb_strlen($description) > 160 ? mb_substr($description, 0, 150) . '…' : $description);
                        if (in_array($log['action'], ['SERVER_SCAN_IN_PROGRESS', 'SERVER_SCAN_COMPLETED'], true)) {
                            $preview = explode("\n", $description, 2)[0];
                        }
                        $actor = (string) ($log['user_name'] ?: $log['user_id'] ?: 'System');
                        $moduleName = (string) ($log['module'] ?: 'system');
                        $icon = match ($moduleName) { 'auth' => 'bi-shield-check', 'file' => 'bi-file-earmark', 'distribution' => 'bi-send', default => 'bi-activity' };
                        $displayTime = \App\Libraries\DisplayTime::format($log['created_at'], 'M d, Y h:i A');
                        ?>
                        <li class="audit-item" id="audit-log-<?= (int) $log['id'] ?>" style="scroll-margin-top: 90px">
                            <span class="audit-icon" aria-hidden="true"><i class="bi <?= esc($icon) ?>"></i></span>
                            <div>
                                <div class="audit-heading">
                                    <span class="audit-user"><?= esc($actor) ?></span>
                                    <span class="audit-badge"><?= esc($roleLabel($log['actor_role'] ?? null)) ?></span>
                                    <span class="audit-badge audit-action"><?= esc($actionLabel($log['action'] ?? '')) ?></span>
                                </div>
                                <div class="audit-meta"><?= esc(ucfirst($moduleName)) ?><?php if (! empty($log['department'])): ?> &middot; <?= esc((string) $log['department']) ?><?php endif; ?></div>
                                <?php if (! $isLibraryUpload): ?>
                                    <p class="audit-description"><?= esc($preview) ?></p>
                                <?php endif; ?>
                                <details class="audit-details" <?= isset($selectedLogId) && $selectedLogId === (int) $log['id'] ? 'open' : '' ?>>
                                    <summary>View details<span class="visually-hidden"> for <?= esc($actor) ?>, <?= esc($displayTime) ?> PHT</span></summary>
                                    <div class="audit-full" tabindex="0" role="region" aria-label="Full audit details">
                                        <dl>
                                            <dt>Description</dt><dd><?= esc($description) ?></dd>
                                            <dt>Target</dt><dd><?= esc((string) ($log['target_label'] ?? 'System')) ?></dd>
                                            <dt>User ID</dt><dd><?= esc((string) ($log['user_id'] ?: '-')) ?></dd>
                                            <dt>Timestamp (PHT)</dt><dd><?= esc($displayTime) ?></dd>
                                        </dl>
                                    </div>
                                </details>
                            </div>
                            <span class="audit-time"><?= esc($displayTime) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($logs === []): ?>
                    <div class="text-center text-muted small py-5"><i class="bi bi-search d-block fs-4 mb-2" aria-hidden="true"></i>No audit logs match your filters.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
<?= view('addons/audit_filter_script') ?>
<?= view('layouts/app_footer') ?>
