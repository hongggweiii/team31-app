<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
require __DIR__ . '/partials/form-styles.php';

use App\Libraries\MatcherException;

// $gig (gigs row), $matches (matcher/'s response, or null when matching
// failed) and $error (the MatcherException reason, or null) are injected by
// Gigs::matches().
$results = $matches['results'] ?? [];
$error ??= null;
$failure = [
    MatcherException::UNAVAILABLE  => 'Matching is unavailable right now. Your gig is saved; please try again in a moment.',
    MatcherException::BAD_RESPONSE => 'Something went wrong while finding matches. Your gig is saved; please try again in a moment.',
    MatcherException::REJECTED     => 'We couldn\'t find matches for this gig because its title and description are empty or invalid.',
][$error ?? MatcherException::UNAVAILABLE];
$retry = $error !== MatcherException::REJECTED;
?>

<div class="bg-cream-25 border-b border-navy/10">
  <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-14">
    <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">Matched Providers</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl"><?= esc($gig['title']) ?></h1>
    <p class="mt-4 max-w-2xl leading-relaxed text-ink/70"><?= esc($gig['summary']) ?></p>
  </div>
</div>

<div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-12 space-y-6">
  <?php if (session('message')): ?>
    <div class="rounded-card border border-green-600/20 bg-green-50 px-6 py-4 text-sm font-semibold text-green-800" role="status">
      <?= esc(session('message')) ?>
    </div>
  <?php endif; ?>

  <?php if ($matches === null): ?>
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-card border border-red-600/20 bg-red-50 px-6 py-4 text-sm text-red-700" role="alert">
      <p><?= esc($failure) ?></p>
      <?php if ($retry): ?>
        <a href="<?= esc(current_url(), 'attr') ?>" class="shrink-0 rounded-full bg-navy px-5 py-2 text-sm font-semibold text-white hover:bg-navy-dark transition-colors">Try again</a>
      <?php endif; ?>
    </div>
  <?php elseif ($results === [] && ($matches['meta']['catalogue_size'] ?? null) === 0): ?>
    <div class="<?= $cardCls ?> text-sm text-ink/70">No providers have published a showcase yet, so there is no one to match this gig with. Check back soon.</div>
  <?php elseif ($results === []): ?>
    <div class="<?= $cardCls ?> text-sm text-ink/70">We couldn't find experts relevant to this gig. Try adding more detail to its description or choosing different specialisations.</div>
  <?php else: ?>
    <?php foreach ($results as $r): ?>
      <section class="<?= $cardCls ?>">
        <div class="flex items-start justify-between gap-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">#<?= esc((string) $r['rank']) ?></p>
            <h2 class="mt-1 <?= $sectionCls ?>"><?= esc($r['provider']['display_title'] ?? '') ?></h2>
          </div>
          <p class="shrink-0 text-2xl font-extrabold text-navy" title="Ranking score, 0-100 (rank 1 is always 100); not a probability">
            <?= esc(number_format((float) $r['score'], 0)) ?>
          </p>
        </div>
        <p class="mt-3 text-sm leading-relaxed text-ink/70"><?= esc($r['provider']['bio_excerpt'] ?? '') ?></p>
        <?php
          // signals.taxonomy.tag_level: 1 shared category, 2 shared specialisation, 3 + a shared skill
          $level = (int) ($r['signals']['taxonomy']['tag_level'] ?? 0);
          $badge = [1 => 'Same category', 2 => 'Same specialisation', 3 => 'Same specialisation and skill'][$level] ?? null;
        ?>
        <?php if ($badge !== null): ?>
          <div class="mt-4 flex flex-wrap gap-2">
            <span class="rounded-full border border-brand/30 bg-cream-50 px-3 py-1 text-xs font-semibold text-navy"><?= esc($badge) ?></span>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?= $this->endSection() ?>
