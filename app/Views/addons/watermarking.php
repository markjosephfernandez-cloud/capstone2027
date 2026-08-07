<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<?php
    $positions = ['bottom_right' => 'Bottom right', 'bottom_left' => 'Bottom left', 'top_right' => 'Top right', 'top_left' => 'Top left', 'center' => 'Center'];
    $enabled = ! empty($settings['enabled']);
?>
<style>
    .watermark-workspace .watermark-intro { background: linear-gradient(135deg, #f4f7ff, #fff); border: 1px solid #e0e7f4; border-radius: 16px; padding: 1.2rem; }
    .watermark-workspace .logo-grid { display: grid; gap: .65rem; grid-template-columns: repeat(auto-fill, minmax(125px, 1fr)); }
    .watermark-workspace .logo-option input { position: absolute; opacity: 0; pointer-events: none; }
    .watermark-workspace .logo-option label { align-items: center; background: #fff; border: 2px solid #e3e8f1; border-radius: 12px; cursor: pointer; display: flex; flex-direction: column; gap: .45rem; min-height: 105px; padding: .65rem; text-align: center; transition: border-color .15s ease, background .15s ease, box-shadow .15s ease; }
    .watermark-workspace .logo-option label:hover { background: #f8faff; border-color: #b8c6f5; }
    .watermark-workspace .logo-option input:checked + label { background: #f0f4ff; border-color: #4154f1; box-shadow: 0 0 0 3px rgba(65, 84, 241, .1); }
    .watermark-workspace .logo-option img { height: 55px; max-width: 88px; object-fit: contain; }
    .watermark-workspace .logo-option span { color: #4b5870; font-size: .72rem; font-weight: 700; }
    .watermark-workspace .default-logo-preview { align-items: center; background: #f7f9ff; border: 1px solid #e1e7f3; border-radius: 12px; display: flex; gap: .8rem; padding: .8rem; }
    .watermark-workspace .default-logo-preview img { height: 62px; object-fit: contain; width: 75px; }
    .watermark-workspace .download-logo-form { align-items: center; display: flex; gap: .4rem; justify-content: flex-end; min-width: 290px; }
    .watermark-workspace .download-logo-form .form-select { max-width: 115px; }
    .watermark-workspace .media-name { color: #33405a; font-weight: 700; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    @media (max-width: 767.98px) { .watermark-workspace .download-logo-form { align-items: stretch; flex-direction: column; min-width: 170px; } .watermark-workspace .download-logo-form .form-select { max-width: none; } }
</style>
<main id="main" class="main watermark-workspace">
    <div class="pagetitle"><h1>Logo Watermarking</h1><nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li><li class="breadcrumb-item active">Watermarking</li></ol></nav></div>
    <section class="section">
        <?php if (! empty($success)): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= esc($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
        <?php if (! empty($error)): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?= esc($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>

        <div class="watermark-intro mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div><h2 class="h5 mb-1">Department logo downloads</h2><p class="text-muted mb-0">FU is the default watermark. Choose another department logo when preparing an image download; the original image is never changed.</p></div>
            <span class="badge <?= $enabled ? 'bg-success' : 'bg-secondary' ?> px-3 py-2"><?= $enabled ? 'Automatic watermark enabled' : 'Automatic watermark disabled' ?></span>
        </div>

        <div class="row g-4">
            <div class="col-xl-4">
                <div class="card h-100"><div class="card-body">
                    <h5 class="card-title mb-1">Default watermark</h5>
                    <p class="text-muted small">Used automatically for new Creative Department image uploads.</p>
                    <?php if ($isAdmin): ?>
                        <form class="row g-3" action="<?= esc(site_url('workspace/watermarking/settings')) ?>" method="post">
                            <?= csrf_field() ?>
                            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>><label class="form-check-label" for="enabled">Apply logo automatically on Creative uploads</label></div></div>
                            <div class="col-12"><label class="form-label">Default logo</label><div class="logo-grid">
                                <?php foreach ($logos as $logo): ?><div class="logo-option"><input type="radio" id="default_logo_<?= esc($logo['code']) ?>" name="logo_code" value="<?= esc($logo['code']) ?>" <?= $defaultLogoCode === $logo['code'] ? 'checked' : '' ?> required><label for="default_logo_<?= esc($logo['code']) ?>"><img src="<?= esc(site_url('workspace/watermarking/logo/' . $logo['code'])) ?>" alt="<?= esc($logo['label']) ?> logo"><span><?= esc($logo['code']) ?></span></label></div><?php endforeach; ?>
                            </div></div>
                            <div class="col-12"><label class="form-label" for="position">Position</label><select class="form-select" id="position" name="position"><?php foreach ($positions as $value => $label): ?><option value="<?= esc($value) ?>" <?= ($settings['position'] ?? 'bottom_right') === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
                            <div class="col-6"><label class="form-label" for="opacity">Opacity</label><div class="input-group"><input class="form-control" id="opacity" name="opacity" type="number" min="30" max="100" value="<?= esc((string) ($settings['opacity'] ?? 65)) ?>"><span class="input-group-text">%</span></div><div class="form-text">Minimum 30% keeps the logo readable.</div></div>
                            <div class="col-6"><label class="form-label" for="resize_percent">Logo scale</label><div class="input-group"><input class="form-control" id="resize_percent" name="resize_percent" type="number" min="50" max="200" value="<?= esc((string) ($settings['resize_percent'] ?? 100)) ?>"><span class="input-group-text">%</span></div></div>
                            <div class="col-12 d-grid"><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save default logo</button></div>
                        </form>
                        <form class="d-grid mt-2" action="<?= esc(site_url('workspace/watermarking/rebuild')) ?>" method="post" onsubmit="return confirm('Rebuild existing automatic watermark copies with the current default logo?');"><?= csrf_field() ?><button class="btn btn-outline-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Apply default to existing images</button></form>
                    <?php else: ?>
                        <div class="default-logo-preview mt-3"><img src="<?= esc(site_url('workspace/watermarking/logo/' . $defaultLogoCode)) ?>" alt="Default <?= esc($defaultLogoCode) ?> logo"><div><strong><?= esc($defaultLogoCode) ?> logo</strong><div class="small text-muted"><?= esc($positions[$settings['position'] ?? 'bottom_right'] ?? 'Bottom right') ?> · <?= esc((string) ($settings['opacity'] ?? 65)) ?>% opacity</div></div></div>
                    <?php endif; ?>
                </div></div>
            </div>

            <div class="col-xl-8">
                <div class="card h-100"><div class="card-body">
                    <h5 class="card-title mb-1">Prepare an image download</h5>
                    <p class="text-muted small">Select the requesting department's logo for each download. Originals remain available separately.</p>
                    <div class="table-responsive"><table class="table table-hover align-middle"><thead class="table-light"><tr><th>Image</th><th>Status</th><th>Uploaded</th><th class="text-end">Download options</th></tr></thead><tbody>
                        <?php foreach ($images as $image): ?><tr id="media-<?= (int) $image['id'] ?>">
                            <td><div class="media-name" title="<?= esc(\App\Libraries\MediaTitle::forFile($image)) ?>"><?= esc(\App\Libraries\MediaTitle::forFile($image)) ?></div><span class="small text-muted text-uppercase"><?= esc($image['filetype']) ?></span></td>
                            <td><?php if (! empty($image['watermark_logo_code'])): ?><span class="badge bg-success"><?= esc($image['watermark_logo_code']) ?> logo ready</span><?php elseif ($image['watermarked_path']): ?><span class="badge bg-warning text-dark">Legacy text copy</span><?php else: ?><span class="badge bg-light text-dark border">Original only</span><?php endif; ?></td>
                            <td><?= esc($image['dateupload'] ? date('M d, Y', strtotime((string) $image['dateupload'])) : '-') ?></td>
                            <td class="text-end"><form class="download-logo-form" action="<?= esc(site_url('workspace/watermarking/' . (int) $image['id'] . '/download')) ?>" method="post"><?= csrf_field() ?><label class="visually-hidden" for="logo_<?= (int) $image['id'] ?>">Department logo</label><select class="form-select form-select-sm" id="logo_<?= (int) $image['id'] ?>" name="logo_code"><?php foreach ($logos as $logo): ?><option value="<?= esc($logo['code']) ?>" <?= $defaultLogoCode === $logo['code'] ? 'selected' : '' ?>><?= esc($logo['code']) ?></option><?php endforeach; ?></select><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-download me-1"></i>Logo copy</button><a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('workspace/file-management/' . (int) $image['id'] . '/download')) ?>"><i class="bi bi-file-earmark me-1"></i>Original</a></form></td>
                        </tr><?php endforeach; ?>
                        <?php if ($images === []): ?><tr><td colspan="4" class="text-center text-muted py-5"><i class="bi bi-images fs-2 d-block mb-2"></i>No active images are available.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </div></div>
            </div>
        </div>
    </section>
</main>
<?= view('layouts/app_footer') ?>
