<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<main id="main" class="main"><div class="pagetitle"><h1><?= esc($title) ?></h1></div><section class="section"><div class="card"><div class="card-body pt-4"><h5 class="card-title">Add-on database migration is required</h5><p>This module needs the legacy add-on tables. Import <code>database/bootstrap/addons.sql</code>, then run <code>php spark migrate</code>.</p></div></div></section></main>
<?= view('layouts/app_footer') ?>
