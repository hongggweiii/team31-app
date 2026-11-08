<?php
/**
 * The taxonomy dashboard's title band, tabs and flash messages, included by
 * both of its pages. Expects $tab: 'proposals' or 'taxonomy'.
 */
$tabs = ['proposals' => ['Proposals', 'taxonomy-dashboard'], 'taxonomy' => ['Taxonomy', 'taxonomy-manage']];
?>
<div class="bg-cream-25 border-b border-navy/10">
  <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 pt-12">
    <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">Taxonomy dashboard</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl">SkillsFuture taxonomy</h1>
    <nav class="mt-8 flex gap-8" aria-label="Taxonomy dashboard">
      <?php foreach ($tabs as $key => [$label, $route]): ?>
        <a href="<?= url_to($route) ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>
           class="border-b-2 pb-3 text-sm font-semibold <?= $key === $tab ? 'border-brand text-navy' : 'border-transparent text-ink/60 hover:text-navy' ?>">
          <?= esc($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>

<div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 pt-8 space-y-3 empty:hidden"><?php
  if (session('message')): ?>
    <div class="rounded-card border border-green-600/20 bg-green-50 px-6 py-4 text-sm font-semibold text-green-800" role="status">
      <?= esc(session('message')) ?>
    </div>
  <?php endif;
  if ((array) session('errors') !== []): ?>
    <div class="rounded-card border border-red-600/20 bg-red-50 px-6 py-4 text-sm text-red-700" role="alert">
      <?php foreach ((array) session('errors') as $error): ?><p><?= esc($error) ?></p><?php endforeach; ?>
    </div>
  <?php endif;
  if ((array) session('notes') !== []): ?>
    <div class="rounded-card border border-amber-600/20 bg-amber-50 px-6 py-4 text-sm text-amber-800">
      <?php foreach ((array) session('notes') as $note): ?><p><?= esc($note) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?></div>
