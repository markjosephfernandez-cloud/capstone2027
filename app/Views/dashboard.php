<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => 'Dashboard']) ?>
<?php
$storageAvailable = $stats['storage_available'] ?? true;
$trendCounts = array_map(static fn (array $point): int => (int) $point['count'], $uploadTrend);
$trendTotal = array_sum($trendCounts);
$trendPeak = max($trendCounts ?: [0]);
$trendTickStep = max(1, (int) ceil($trendPeak / 4));
$trendScaleMax = $trendTickStep * 4;
$chartLeft = 54;
$chartRight = 870;
$chartTop = 18;
$chartBottom = 224;
$chartWidth = $chartRight - $chartLeft;
$chartHeight = $chartBottom - $chartTop;
$trendPoints = [];
foreach ($uploadTrend as $index => $point) {
    $x = $chartLeft + (($chartWidth * $index) / max(1, count($uploadTrend) - 1));
    $y = $chartBottom - (($chartHeight * (int) $point['count']) / $trendScaleMax);
    $trendPoints[] = ['x' => $x, 'y' => $y, 'point' => $point];
}
$linePoints = implode(' ', array_map(static fn (array $point): string => number_format($point['x'], 2, '.', '') . ',' . number_format($point['y'], 2, '.', ''), $trendPoints));
$areaPoints = $trendPoints === [] ? '' : $chartLeft . ',' . $chartBottom . ' ' . $linePoints . ' ' . $chartRight . ',' . $chartBottom;
$labelIndexes = [0, 7, 14, 21, 29];
?>
<style>
    .storage-analytics-table { max-height: 360px; overflow: auto; }
    .storage-analytics-table:focus-visible { outline: 2px solid #4154f1; outline-offset: 2px; }
    .storage-analytics-table table { margin-bottom: 0; }
    .storage-analytics-table th, .storage-analytics-table td { padding: .65rem .75rem; }
    .storage-analytics-table th:not(:first-child), .storage-analytics-table td:not(:first-child) { text-align: right; white-space: nowrap; }
    .storage-analytics-table thead th { position: sticky; top: 0; z-index: 1; background: #f6f9ff; }
    .storage-analytics-table tfoot th { position: sticky; bottom: 0; z-index: 1; background: #f6f9ff; box-shadow: 0 -1px 0 #e6e9ef; }
    .upload-trend-chart { min-height: 300px; overflow-x: auto; }
    .upload-trend-chart svg { display: block; height: auto; min-width: 620px; width: 100%; }
    .upload-trend-grid { stroke: #e6e9ef; stroke-width: 1; }
    .upload-trend-axis-label { fill: #6c757d; font-size: 12px; }
    .upload-trend-area { fill: url(#uploadTrendGradient); }
    .upload-trend-line { fill: none; stroke: #4154f1; stroke-linecap: round; stroke-linejoin: round; stroke-width: 3; }
    .upload-trend-point { fill: #fff; stroke: #4154f1; stroke-width: 2; }
    .upload-trend-summary strong { color: #012970; font-size: 1.35rem; }
    .dashboard-request-list { display: grid; gap: .2rem; }
    .dashboard-request-item { align-items: flex-start; border-bottom: 1px solid #eef1f6; color: inherit; display: flex; gap: .75rem; min-width: 0; padding: .85rem 0; text-decoration: none; }
    .dashboard-request-item:last-child { border-bottom: 0; }
    .dashboard-request-item:hover .dashboard-request-title { color: #4154f1; }
    .dashboard-request-icon { align-items: center; background: #eef1ff; border-radius: 50%; color: #4154f1; display: inline-flex; flex: 0 0 34px; height: 34px; justify-content: center; width: 34px; }
    .dashboard-request-content { min-width: 0; overflow: hidden; }
    .dashboard-request-title { color: #012970; display: block; font-size: .9rem; overflow-wrap: anywhere; transition: color .15s; }
    .dashboard-request-details { align-items: center; display: grid; gap: .5rem; grid-template-columns: minmax(0, 1fr) auto; margin-top: .2rem; min-width: 0; }
    .dashboard-request-meta { color: #6c757d; font-size: .76rem; min-width: 0; overflow-wrap: anywhere; }
    .dashboard-request-status { align-self: center; justify-self: end; max-width: 8rem; overflow-wrap: anywhere; white-space: normal; }
    @media (max-width: 400px) { .dashboard-request-details { align-items: start; grid-template-columns: minmax(0, 1fr); } .dashboard-request-status { justify-self: start; } }
</style>
<main id="main" class="main">
    <div class="pagetitle"><h1>Dashboard</h1><nav><ol class="breadcrumb"><li class="breadcrumb-item active">Dashboard</li></ol></nav></div>
    <?php if (! empty($error)): ?><div class="alert alert-warning alert-dismissible fade show" role="alert"><?= esc($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if (! empty($success)): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= esc($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($consentRequired): ?><div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-3" role="alert"><div><strong>Data Privacy & Media Use Reminder</strong><br><span class="small">Please acknowledge the responsible use of university media, role-based access, and activity logging.</span></div><form action="<?= esc(site_url('privacy/consent')) ?>" method="post"><?= csrf_field() ?><button type="submit" class="btn btn-info btn-sm text-white">Acknowledge notice</button></form></div><?php endif; ?>
    <?php if (! $storageAvailable): ?>
        <div class="alert alert-warning" role="alert"><strong>Server storage is unavailable.</strong> Connect the server share, then <a href="<?= esc(site_url('dashboard')) ?>" class="alert-link">refresh the dashboard</a>. Storage capacity cannot be checked until the connection is restored.</div>
    <?php endif; ?>
    <section class="section dashboard"><div class="row">
        <div class="col-lg-8"><div class="row">
            <div class="col-xxl-4 col-md-6"><div class="card info-card sales-card"><div class="card-body"><h5 class="card-title">STORAGE USED</h5><div class="d-flex align-items-center"><div class="card-icon rounded-circle d-flex align-items-center justify-content-center"><i class="bi bi-cloud"></i></div><div class="ps-3"><h6><?= $storageAvailable ? esc(format_bytes($stats['disk_used'])) : 'Unavailable' ?></h6><?php if ($storageAvailable): ?><span class="text-success small pt-1 fw-bold"><?= $stats['disk_total'] > 0 ? esc(number_format(($stats['disk_used'] / $stats['disk_total']) * 100, 1)) : '0' ?>%</span><span class="text-muted small pt-2 ps-1">of total storage</span><?php endif; ?></div></div></div></div></div>
            <div class="col-xxl-4 col-md-6"><div class="card info-card revenue-card"><div class="card-body"><h5 class="card-title">AVAILABLE STORAGE</h5><div class="d-flex align-items-center"><div class="card-icon rounded-circle d-flex align-items-center justify-content-center"><i class="bi bi-hdd"></i></div><div class="ps-3"><h6><?= $storageAvailable ? esc(format_bytes($stats['disk_free'])) : 'Unavailable' ?></h6><?php if ($storageAvailable): ?><span class="text-muted small pt-2 ps-1">of <?= esc(format_bytes($stats['disk_total'])) ?></span><?php endif; ?></div></div></div></div></div>
            <div class="col-xxl-4 col-xl-12"><div class="card info-card customers-card"><div class="card-body"><h5 class="card-title">TOTAL FILES</h5><div class="d-flex align-items-center"><div class="card-icon rounded-circle d-flex align-items-center justify-content-center"><i class="bi bi-file-earmark-text" style="color:white"></i></div><div class="ps-3"><h6><?= esc(number_format($stats['total_files'])) ?></h6><span class="text-muted small pt-2 ps-1"><?= esc(number_format($stats['active_files'])) ?> active</span></div></div></div></div></div>
            <div class="col-12"><div class="card"><div class="card-body">
                <div class="d-flex align-items-start justify-content-between flex-wrap gap-2"><div><h5 class="card-title mb-1">Media Uploads <span>| Last 30 Days</span></h5><p class="text-muted small mb-0">Daily active media uploads visible to your account.</p></div><div class="upload-trend-summary text-end pt-3"><strong><?= esc(number_format($trendTotal)) ?></strong><div class="small text-muted">uploads in this period</div></div></div>
                <?php if ($trendTotal === 0): ?>
                    <div class="text-center text-muted py-5"><i class="bi bi-graph-up fs-2 d-block mb-2"></i>No media uploads were recorded during the last 30 days.</div>
                <?php else: ?>
                    <div class="upload-trend-chart" role="img" aria-label="Media uploads during the last 30 days. <?= esc(number_format($trendTotal)) ?> total uploads; peak of <?= esc(number_format($trendPeak)) ?> in one day.">
                        <svg viewBox="0 0 900 270" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                            <defs><linearGradient id="uploadTrendGradient" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#4154f1" stop-opacity="0.3"/><stop offset="100%" stop-color="#4154f1" stop-opacity="0.02"/></linearGradient></defs>
                            <?php for ($tick = 0; $tick <= 4; $tick++): ?><?php $tickY = $chartBottom - (($chartHeight * $tick) / 4); ?>
                                <line class="upload-trend-grid" x1="<?= $chartLeft ?>" y1="<?= esc(number_format($tickY, 2, '.', '')) ?>" x2="<?= $chartRight ?>" y2="<?= esc(number_format($tickY, 2, '.', '')) ?>"/>
                                <text class="upload-trend-axis-label" x="44" y="<?= esc(number_format($tickY + 4, 2, '.', '')) ?>" text-anchor="end"><?= esc(number_format($trendTickStep * $tick)) ?></text>
                            <?php endfor; ?>
                            <polygon class="upload-trend-area" points="<?= esc($areaPoints) ?>"/>
                            <polyline class="upload-trend-line" points="<?= esc($linePoints) ?>"/>
                            <?php foreach ($trendPoints as $index => $trendPoint): ?>
                                <circle class="upload-trend-point" cx="<?= esc(number_format($trendPoint['x'], 2, '.', '')) ?>" cy="<?= esc(number_format($trendPoint['y'], 2, '.', '')) ?>" r="3"><title><?= esc((string) $trendPoint['point']['label']) ?>: <?= esc(number_format((int) $trendPoint['point']['count'])) ?> upload<?= (int) $trendPoint['point']['count'] === 1 ? '' : 's' ?></title></circle>
                            <?php endforeach; ?>
                            <?php foreach ($labelIndexes as $labelIndex): ?><?php if (isset($trendPoints[$labelIndex])): ?>
                                <text class="upload-trend-axis-label" x="<?= esc(number_format($trendPoints[$labelIndex]['x'], 2, '.', '')) ?>" y="252" text-anchor="middle"><?= esc((string) $trendPoints[$labelIndex]['point']['label']) ?></text>
                            <?php endif; ?><?php endforeach; ?>
                        </svg>
                    </div>
                <?php endif; ?>
            </div></div></div>
            <div class="col-12">
                <div class="card"><div class="card-body">
                    <h5 class="card-title mb-1" id="storage-analytics-title">Storage Analytics</h5>
                    <p class="text-muted small mb-3">All file types, sorted by storage used. Scroll to view more.</p>
                    <div class="table-responsive storage-analytics-table" role="region" aria-labelledby="storage-analytics-title" tabindex="0">
                        <table class="table table-borderless">
                            <thead><tr><th scope="col">FILE TYPE</th><th scope="col">FILES</th><th scope="col">TOTAL SIZE</th></tr></thead>
                            <tbody>
                                <?php foreach ($categories as $category): ?>
                                    <tr><td><?= esc($category['filetype'] ?: 'Unknown') ?></td><td><?= esc(number_format((int) $category['file_count'])) ?></td><td><?= esc(format_bytes((int) $category['total_size'])) ?></td></tr>
                                <?php endforeach; ?>
                                <?php if ($categories === []): ?><tr><td colspan="3" class="text-muted">No file data is available.</td></tr><?php endif; ?>
                            </tbody>
                            <tfoot><tr><th scope="row">Total</th><th><?= esc(number_format($stats['total_files'])) ?></th><th><?= esc(format_bytes((int) $stats['total_bytes'])) ?></th></tr></tfoot>
                        </table>
                    </div>
                </div></div>
            </div>
            <?php if ($isAdmin): ?><div class="col-12"><div class="card"><div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3"><div><h5 class="card-title mb-1">Administration</h5><p class="text-muted mb-0">Manage team access and review protected system activity.</p></div><a class="btn btn-primary" href="<?= esc(site_url('admin/control-center')) ?>"><i class="bi bi-shield-lock me-1"></i> Open Control Center</a></div></div></div><?php endif; ?>
        </div></div>
        <div class="col-lg-4">
            <div class="card"><div class="card-body"><h5 class="card-title">Recent Activity</h5><div class="activity"><?php foreach ($activity as $item): ?>
                <?php
                $activityDescription = (string) ($item['description'] ?: $item['action']);
                $hasLibraryDetails = preg_match('/\A(Server Library [^:\r\n]+:)\s*\S/s', $activityDescription, $activityMatch) === 1;
                $canViewActivityDetails = $hasLibraryDetails && ! empty($item['id']) && (\App\Libraries\RolePolicy::isSuperAdmin($user) || \App\Libraries\RolePolicy::isCreativeAdmin($user));
                $activityPreview = $canViewActivityDetails ? $activityMatch[1] : $activityDescription;
                ?>
                <div class="activity-item d-flex"><div class="activite-label"><?= esc(\App\Libraries\DisplayTime::format($item['created_at'], 'M j')) ?></div><i class="bi bi-circle-fill activity-badge text-primary align-self-start"></i><div class="activity-content"><strong><?= esc($item['user_name'] ?: 'System') ?></strong> <?= esc($activityPreview) ?>
                    <?php if ($canViewActivityDetails): ?>
                        <a class="d-block small mt-1" href="<?= esc(site_url('workspace/audit-logs') . '?log=' . (int) $item['id'] . '#audit-log-' . (int) $item['id'], 'attr') ?>">View details<span class="visually-hidden"> for <?= esc($item['user_name'] ?: 'System') ?>, <?= esc(\App\Libraries\DisplayTime::format($item['created_at'], 'M d, Y h:i A')) ?></span></a>
                    <?php endif; ?>
                </div></div><?php endforeach; ?><?php if ($activity === []): ?><p class="text-muted mb-0">No activity has been recorded yet.</p><?php endif; ?></div></div></div>
            <?php if ($canManageDistribution ?? false): ?>
                <div class="card"><div class="card-body">
                    <div class="d-flex align-items-center justify-content-between gap-2"><h5 class="card-title mb-0">Department Direct/Requests</h5><a class="small" href="<?= esc(site_url('workspace/distribution') . '?tab=requests') ?>">View all</a></div>
                    <div class="dashboard-request-list">
                        <?php foreach (($departmentRequests ?? []) as $departmentRequest): ?><?php
                            $requestId = (int) $departmentRequest['id'];
                            $requestStatus = (string) $departmentRequest['status'];
                            $requestUrl = site_url('workspace/distribution') . '?tab=requests&request=' . $requestId . '#request-' . $requestId;
                            $statusClass = $requestStatus === 'complete' ? 'bg-success-subtle text-success' : ($requestStatus === 'pending_incomplete' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning-emphasis');
                            $statusLabel = $requestStatus === 'pending_incomplete' ? 'Pending – Incomplete' : ucfirst($requestStatus);
                        ?>
                            <a class="dashboard-request-item" href="<?= esc($requestUrl) ?>" aria-label="Open <?= esc((string) $departmentRequest['title'], 'attr') ?> from <?= esc((string) $departmentRequest['department'], 'attr') ?> in Department Requests">
                                <span class="dashboard-request-icon"><i class="bi bi-inbox"></i></span>
                                <span class="dashboard-request-content flex-grow-1"><strong class="dashboard-request-title"><?= esc($departmentRequest['title']) ?></strong><span class="dashboard-request-details"><span class="dashboard-request-meta"><?= esc($departmentRequest['department']) ?> · <?= esc($departmentRequest['requester_name'] ?: 'Department user') ?><br><?= esc(\App\Libraries\DisplayTime::format($departmentRequest['submitted_at'], 'M j, Y g:i A')) ?> · <?= ($departmentRequest['authorization_status'] ?? '') === 'authorized' ? 'Authorized' : 'Not Authorized' ?></span><span class="badge dashboard-request-status <?= esc($statusClass, 'attr') ?>"><?= esc($statusLabel) ?></span></span></span>
                            </a>
                        <?php endforeach; ?>
                        <?php if (($departmentRequests ?? []) === []): ?><p class="text-muted small py-3 mb-0">No department requests have been submitted yet.</p><?php endif; ?>
                    </div>
                </div></div>
            <?php endif; ?>
        </div>
    </div></section>
</main>
<?= view('layouts/app_footer') ?>
