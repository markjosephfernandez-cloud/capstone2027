<?php
$mediaUrl = static fn (string $path, array $extra = []): string => site_url('workspace/server-library/media') . '?' . http_build_query(array_merge(['path'=>$path], $extra));
?>
                        <?php foreach ($files as $item): ?>
                        <article class="server-file-card" data-server-file="<?= esc($item['path']) ?>">
                            <input type="checkbox" class="form-check-input m-1" data-server-select value="<?= esc($item['path']) ?>" aria-label="Select <?= esc($item['name']) ?>">
                            <div class="server-file-identity">
                            <?php if (in_array($item['kind'],['image','video'],true)): ?>
                            <button type="button" class="server-file-preview" data-server-preview="<?= esc($mediaUrl($item['path'])) ?>" data-kind="<?= esc($item['kind']) ?>" data-title="<?= esc($item['name']) ?>" aria-label="Preview <?= esc($item['name']) ?>">
                                <i class="bi <?= $item['kind']==='video'?'bi-film':'bi-image' ?> fs-1" aria-hidden="true"></i>
                                <img src="<?= esc($mediaUrl($item['path'],['thumbnail'=>1])) ?>" alt="" loading="lazy" decoding="async" onerror="this.hidden=true">
                                <?php if ($item['kind']==='video'): ?><span class="position-absolute bottom-0 end-0 badge bg-dark m-1">Video</span><?php endif; ?>
                            </button>
                            <?php else: ?><div class="server-file-preview"><i class="bi bi-file-earmark fs-1" aria-hidden="true"></i></div><?php endif; ?>
                            <div class="server-file-details"><div class="server-file-name" title="<?= esc($item['name']) ?>"><?= esc($item['name']) ?></div><span class="server-file-kind"><?= esc(dirname($item['path']) === '.' ? 'Root library' : dirname($item['path'])) ?></span></div>
                            </div>
                            <span class="server-file-meta server-file-type"><?= esc(strtoupper(pathinfo($item['name'], PATHINFO_EXTENSION))) ?></span>
                            <span class="server-file-meta"><?= isset($item['size']) ? esc(number_format($item['size']/1024,2)).' KB' : '—' ?></span>
                            <span class="server-file-meta"><?= !empty($item['uploaded_at']) ? esc(\App\Libraries\DisplayTime::format($item['uploaded_at'])) : (!empty($item['modified_at']) ? esc(\App\Libraries\DisplayTime::format((int) $item['modified_at'])) : '—') ?></span>
                            <span class="server-file-meta"><?= esc(implode(', ', $item['tags'] ?? [])) ?: '—' ?></span>
                            <div class="server-file-actions"><?= view('workspace/server_library_item_actions', ['item'=>$item, 'canManage'=>$canManage ?? false]) ?></div>
                        </article>
                        <?php endforeach; ?>
