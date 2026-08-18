<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<main id="main" class="main">
    <div class="pagetitle">
        <h1>Notifications</h1>
        <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li><li class="breadcrumb-item active">Notifications</li></ol></nav>
    </div>
    <section class="section">
        <div class="card">
            <div class="card-body pt-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3"><div><h5 class="card-title p-0 m-0">All notifications</h5><span class="small text-muted">Review updates sent to your account.</span></div><?php if ((int) ($unreadCount ?? 0) > 0): ?><form action="<?= esc(site_url('notifications/read-all')) ?>" method="post"><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-check2-all me-1"></i>Read all</button></form><?php endif; ?></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($notifications as $notification): ?>
                        <form class="list-group-item px-0" action="<?= esc(site_url('notifications/' . (int) $notification['id'] . '/read')) ?>" method="post">
                            <?= csrf_field() ?>
                            <button class="btn w-100 text-start p-0" type="submit">
                                <span class="d-flex justify-content-between gap-3">
                                    <strong><?= esc($notification['title']) ?></strong>
                                    <span class="badge <?= (int) $notification['is_read'] === 1 ? 'bg-light text-muted' : 'bg-primary-subtle text-primary' ?>"><?= (int) $notification['is_read'] === 1 ? 'Read' : 'Unread' ?></span>
                                </span>
                                <span class="d-block small text-muted mt-1"><?= nl2br(esc($notification['message'])) ?></span>
                                <span class="d-block text-muted mt-1" style="font-size:.75rem"><?= esc(date('M j, Y g:i A', strtotime((string) $notification['created_at']))) ?></span>
                            </button>
                        </form>
                    <?php endforeach; ?>
                    <?php if ($notifications === []): ?><div class="text-center text-muted py-5">No notifications yet.</div><?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</main>
<?= view('layouts/app_footer') ?>
