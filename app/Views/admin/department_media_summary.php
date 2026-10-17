<div class="card" id="department-media-summary"><div class="card-body">
    <h2 class="card-title">Department Media Summary</h2>
    <p class="small text-muted">Active registered central-library files by passport department, falling back to the uploader’s current department. Excludes trash and separate department delivery copies. Storage is the sum of registered file sizes.</p>
    <div class="table-responsive"><table class="table small align-middle">
        <thead><tr><th scope="col">Department</th><th scope="col">Files</th><th scope="col">Storage</th><th scope="col">With passport</th><th scope="col">Missing passport</th><th scope="col">Coverage</th><th scope="col">Pending AI</th><th scope="col">Failed AI</th><th scope="col">Needs attention</th></tr></thead>
        <tbody><?php foreach ($rows as $row): ?>
            <tr><td><?= esc($row['department']) ?></td><td><?= number_format((int) $row['total_files']) ?></td><td><?= esc(format_bytes((int) $row['total_storage_bytes'])) ?></td><td><?= (int) $row['files_with_passport'] ?></td><td><?= (int) $row['files_missing_passport'] ?></td><td><?= number_format((float) $row['passport_coverage_percent'], 2) ?>%</td><td><?= (int) $row['pending_analysis'] ?></td><td><?= (int) $row['failed_analysis'] ?></td><td><?= (int) $row['files_needing_attention'] ?></td></tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="9" class="text-muted">No department report data available.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <p class="small text-muted mb-0">Needs attention includes pending or failed analysis, a missing passport, or a passport flagged for review. Each file is counted once.</p>
</div></div>
