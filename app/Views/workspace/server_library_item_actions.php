<?php if (($canManage ?? false) || !$item['directory']): ?>
<div class="dropdown">
    <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for <?= esc($item['name']) ?>"><i class="bi bi-three-dots-vertical"></i></button>
    <ul class="dropdown-menu dropdown-menu-end">
        <?php if ($canManage ?? false): ?>
        <?php foreach (['rename'=>'Rename', 'move'=>'Move', 'trash'=>'Move to Trash'] as $action=>$label): ?>
        <li><button class="dropdown-item <?= $action === 'trash' ? 'text-danger' : '' ?>" type="button" data-server-edit="<?= $action ?>" data-path="<?= esc($item['path']) ?>" data-name="<?= esc($item['name']) ?>"><?= $label ?></button></li>
        <?php endforeach; ?>
        <?php if (! $item['directory']): ?><li><button class="dropdown-item" type="button" data-server-edit="tags" data-path="<?= esc($item['path']) ?>">Add tags</button></li><?php endif; ?>
        <?php endif; ?>
        <?php if (!$item['directory']): ?>
        <li><a class="dropdown-item" data-no-ajax href="<?= esc(site_url('workspace/server-library/media').'?'.http_build_query(['path'=>$item['path'],'download'=>1])) ?>"><i class="bi bi-download me-2"></i>Download</a></li>
        <?php if (!empty($item['has_passport'])): ?><li><a class="dropdown-item" data-no-ajax href="<?= esc(site_url('workspace/server-library/passport').'?'.http_build_query(['path'=>$item['path']])) ?>">Metadata passport</a></li><?php endif; ?>
        <?php endif; ?>
    </ul>
</div>
<?php endif; ?>
