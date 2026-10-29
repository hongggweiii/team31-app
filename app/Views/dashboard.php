<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
require __DIR__ . '/partials/form-styles.php';

// $runs (the latest $limit analytics_outputs rows, newest first, with
// gig_title), $tableRuns (this page of the runs table: page $page of $pages,
// $perPage a page, $total runs in all) and $results (run_outputs rows per analytics_output_id,
// by rank, for both) are injected by Dashboard::index().

/** The value at percentile $p (nearest rank) of the non-null $values, or null. */
$pct = static function (array $values, float $p): ?float {
    $values = array_values(array_filter($values, static fn ($v) => $v !== null));
    if ($values === []) {
        return null;
    }
    sort($values);

    return (float) $values[max(0, (int) ceil($p / 100 * count($values)) - 1)];
};
// Every time on the page is in seconds: one decimal, two below a second.
$sec = static fn (?float $v): string => $v === null ? '—' : number_format($v / 1000, $v < 1000 ? 2 : 1) . ' s';
$col =static fn (string $c): array => array_map(static fn ($r) => $r[$c] === null ? null : (float) $r[$c], $runs);

$ok = array_filter($runs, static fn ($r) => $r['status'] === 'ok');

// Where each run's time went, in the runs table: one bar for each of the two
// requests a gig submission makes, the POST that saves the gig and the matches
// page it redirects to, left to right in the order it happens. Stages are
// grouped: one colour family per group, one shade per stage.
$groups = [
    'Database' => ['connect' => ['Connect', 'bg-navy-dark'], 'queries' => ['Queries', 'bg-[#6F9BC4]']],
    'Matcher'  => ['read' => ['Read stored vector', 'bg-[#7A4A1E]'], 'embed' => ['Embed', 'bg-[#C45F00]'],
                   'rank' => ['Rank (BM25, cosine, xgboost)', 'bg-brand'], 'overhead' => ['Call overhead', 'bg-[#FFC27A]']],
    'App'      => ['php' => ['PHP + views', 'bg-[#4E8A57]']],
];
$stages  = array_merge(...array_values($groups));
// Runs saved before the save request's own numbers were recorded: only its total is known.
$stages += ['unsplit' => ['Not broken down (older runs)', 'bg-[#B8BFC7]']];
// The matches page opened again, not straight after Submit: timed, but with no redirect chain.
$revisit = static fn (array $r): bool => $r['action_total_ms'] === null && $r['page_total_ms'] !== null;
$wait    = static fn (array $r): ?float => ($r['action_total_ms'] ?? $r['page_total_ms']) === null ? null : (float) ($r['action_total_ms'] ?? $r['page_total_ms']);
$bars    = static function (array $r) use ($revisit): array {
    $v = static fn (string $c): float => (float) ($r[$c] ?? 0);

    // A revisit or refresh of the matches page has no save step.
    return ($revisit($r) ? [] : [
        'Save gig' => $r['save_total_ms'] === null
            ? ['unsplit' => max(0.0, $v('action_total_ms') - $v('page_total_ms'))]
            : ['connect' => $v('save_connect_ms'), 'queries' => $v('save_query_ms'), 'php' => $v('save_php_ms')],
    ]) + [
        'Matches page' => [
            'connect'  => $v('db_connect_ms'),
            'queries'  => $v('db_query_ms'),
            'read'     => $v('matcher_vector_read_ms'),
            'embed'    => $v('matcher_embed_ms'),
            'rank'     => $v('matcher_candidates_ms') + $v('matcher_score_ms') + $v('matcher_response_ms'),
            'overhead' => max(0.0, $v('matcher_call_ms') - $v('matcher_total_ms')),
            'php'      => $v('php_views_ms'),
        ],
    ];
};
$timed   = array_filter($tableRuns, static fn ($r) => $wait($r) !== null);
$widest  = max([1.0, ...array_map(static fn ($r) => max(array_map('array_sum', $bars($r))), $timed)]);
$unsplit = array_filter($timed, static fn ($r) => $r['save_total_ms'] === null && ! $revisit($r)) !== [];

// signals.taxonomy.tag_level, as on the matches page
$levels = [0 => 'No shared tag', 1 => 'Same category', 2 => 'Same specialisation', 3 => 'Same specialisation and skill'];
$byLevel = ['all' => array_fill_keys(array_keys($levels), 0), 'top' => array_fill_keys(array_keys($levels), 0)];
foreach ($results as $rows) {
    foreach ($rows as $row) {
        $level = (int) ($row['tag_level'] ?? 0);
        $byLevel['all'][$level]++;
        if ((int) $row['rank'] === 1) {
            $byLevel['top'][$level]++;
        }
    }
}

// Summary cards, worded for anyone: "typical" is the median, "slowest" the
// 95th percentile (1 in 20 runs is slower).
$kpis = [
    ['Gigs matched', count($runs), count($ok) . ' succeeded · ' . (count($runs) - count($ok)) . ' failed'],
    ['Wait for matches', $sec($pct($col('action_total_ms'), 50)), 'typical, from Submit to seeing matches · slowest ' . $sec($pct($col('action_total_ms'), 95))],
    ['Matching', $sec($pct($col('matcher_total_ms'), 50)), 'typical time the matcher takes · slowest ' . $sec($pct($col('matcher_total_ms'), 95))],
    ['Reading the gig', $sec($pct($col('matcher_embed_ms'), 50)), 'typical, part of matching (embedding) · slowest ' . $sec($pct($col('matcher_embed_ms'), 95))],
];
?>

<div class="bg-cream-25 border-b border-navy/10">
  <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-14">
    <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">Admin</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl">Matcher Dashboard</h1>
    <p class="mt-4 max-w-2xl leading-relaxed text-ink/70">
      The latest <?= esc((string) $limit) ?> matcher runs: how long each took and what it suggested.
    </p>
  </div>
</div>

<div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-12 space-y-6">
  <?php if ($runs === []): ?>
    <div class="<?= $cardCls ?> text-sm text-ink/70">No matcher runs have been saved yet. Post a gig to record one.</div>
  <?php else: ?>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <?php foreach ($kpis as [$label, $value, $note]): ?>
        <div class="rounded-card border border-navy/10 bg-white p-5">
          <p class="text-xs font-bold uppercase tracking-wider text-ink/50"><?= esc($label) ?></p>
          <p class="mt-2 text-2xl font-extrabold text-navy"><?= esc((string) $value) ?></p>
          <p class="mt-1 text-xs text-ink/50"><?= esc($note) ?></p>
        </div>
      <?php endforeach; ?>
    </div>

    <section id="runs" class="<?= $cardCls ?> scroll-mt-20">
      <h2 class="<?= $sectionCls ?>">Runs</h2>
      <p class="<?= $hintCls ?>">
        What each gig was matched with, and where its wait went. Submitting a gig is two steps: saving it, then loading its
        matches page; hover a bar for each stage. View all lists every suggested showcase with its score (0-100; #1 is always 100).
        App-side stages are only recorded while RequestTimer is on (development).
      </p>
      <?php
        // "CPU: AMD Ryzen 5 5600X 6-Core Processor" -> "Ryzen 5 5600X"; the full name is on hover.
        $machine = static fn (?string $m): string => $m === null ? '—'
            : trim(preg_replace(['/^CPU:\s*/', '/\b(AMD|Intel\(R\)|Core\(TM\))\s+/', '/\s+(\d+-Core Processor|CPU @.*)$/'], '', $m));
      ?>
      <div class="mt-4 flex flex-wrap gap-x-8 gap-y-3 text-xs text-ink/70">
        <?php foreach ($unsplit ? $groups + ['Older runs' => ['unsplit' => $stages['unsplit']]] : $groups as $group => $members): ?>
          <div>
            <p class="font-bold uppercase tracking-wider text-navy"><?= esc($group) ?></p>
            <ul class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1">
              <?php foreach ($members as [$label, $cls]): ?>
                <li class="flex items-center gap-2"><span class="h-3 w-3 rounded-sm <?= $cls ?>"></span><?= esc($label) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-5 overflow-x-auto">
        <table class="w-full min-w-[64rem] table-fixed text-left text-sm">
          <colgroup>
            <col class="w-24"><col class="w-[18%]"><col class="w-[27%]"><col><col class="w-20"><col class="w-24"><col class="w-10">
          </colgroup>
          <thead class="text-xs uppercase tracking-wider text-ink/50">
            <tr class="border-b border-navy/10">
              <th class="py-2 pr-4">Run</th><th class="py-2 pr-4">Gig</th><th class="py-2 pr-4">Matches</th>
              <th class="py-2 pr-4">Where the time goes</th><th class="py-2 pr-4 text-right">Total wait</th>
              <th class="py-2 pr-4">Machine</th><th class="py-2 text-center">OK</th>
            </tr>
          </thead>
          <?php foreach ($tableRuns as $r): ?>
            <?php $rows = $results[$r['id']] ?? []; ?>
            <tbody class="border-b border-navy/5">
              <tr class="align-top">
                <td class="py-3 pr-4 text-xs text-ink/50">
                  <span class="font-semibold text-ink/70">#<?= esc((string) $r['id']) ?></span><br>
                  <?= esc(date('j M, H:i', strtotime((string) $r['created_at']))) ?>
                </td>
                <td class="py-3 pr-4">
                  <p class="font-semibold text-navy"><?= esc($r['gig_title']) ?></p>
                  <?php if ($revisit($r)): ?>
                    <span class="mt-1.5 inline-block rounded-full border border-navy/15 px-2.5 py-0.5 text-xs text-ink/60" title="Matches page opened again or refreshed: no save step">Refresh</span>
                  <?php elseif ($r['action_total_ms'] !== null): ?>
                    <span class="mt-1.5 inline-block rounded-full bg-brand/10 px-2.5 py-0.5 text-xs font-semibold text-brand" title="Gig submitted, then its matches page">Submission</span>
                  <?php endif; ?>
                </td>
                <td class="py-3 pr-4">
                  <?php if ($r['status'] !== 'ok'): ?>
                    <p class="text-xs text-red-700"><?= esc((string) $r['error']) ?></p>
                  <?php elseif ($rows === []): ?>
                    <span class="text-ink/50">—</span>
                  <?php else: ?>
                    <p class="text-ink"><span class="text-ink/50">#1</span> <?= esc((string) $rows[0]['display_title']) ?></p>
                    <p class="mt-0.5 text-xs text-ink/50">
                      score <?= esc(number_format((float) $rows[0]['score'], 0)) ?> · <?= esc($levels[(int) ($rows[0]['tag_level'] ?? 0)] ?? '') ?>
                    </p>
                    <details class="mt-1.5">
                      <summary class="w-fit cursor-pointer list-none rounded-full border border-navy/15 px-3 py-0.5 text-xs font-semibold text-navy hover:border-brand hover:text-brand">
                        View all <?= count($rows) ?>
                      </summary>
                      <table class="mt-2 w-full text-xs">
                        <thead class="text-ink/50">
                          <tr><th class="py-1 pr-2 text-left font-normal">#</th><th class="py-1 pr-2 text-left font-normal">Showcase</th>
                              <th class="py-1 pr-2 text-right font-normal">Score</th><th class="py-1 text-left font-normal">Shares with gig</th></tr>
                        </thead>
                        <tbody class="text-ink/80">
                          <?php foreach ($rows as $row): ?>
                            <tr class="border-t border-navy/5 align-top">
                              <td class="py-1 pr-2 text-ink/50"><?= esc((string) $row['rank']) ?></td>
                              <td class="py-1 pr-2"><?= esc((string) $row['display_title']) ?></td>
                              <td class="py-1 pr-2 text-right font-semibold text-navy"><?= esc(number_format((float) $row['score'], 0)) ?></td>
                              <td class="py-1 text-ink/60"><?= esc($levels[(int) ($row['tag_level'] ?? 0)] ?? '') ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </details>
                  <?php endif; ?>
                </td>
                <td class="py-3 pr-4">
                  <?php if ($wait($r) === null): ?>
                    <span class="text-xs text-ink/50">Not recorded</span>
                  <?php else: ?>
                    <?php foreach ($bars($r) as $bar => $parts): ?>
                      <div class="flex items-center gap-2 text-xs [&:not(:first-child)]:mt-1.5">
                        <span class="w-20 shrink-0 text-ink/60"><?= esc($bar) ?></span>
                        <div class="flex h-3.5 flex-1">
                          <div class="flex h-3.5 gap-0.5" style="width: <?= round(array_sum($parts) / $widest * 100, 2) ?>%">
                            <?php foreach ($parts as $key => $value): ?>
                              <?php if ($value <= 0) { continue; } ?>
                              <div class="h-3.5 rounded-sm <?= $stages[$key][1] ?>" style="flex: <?= round($value, 1) ?> 1 0"
                                   title="<?= esc($bar . ' · ' . $stages[$key][0] . ': ' . $sec($value)) ?>"></div>
                            <?php endforeach; ?>
                          </div>
                        </div>
                        <span class="w-12 shrink-0 text-right text-ink/70"><?= esc($sec(array_sum($parts))) ?></span>
                      </div>
                    <?php endforeach; ?>
                    <?php if ($r['matcher_call_ms'] !== null): ?>
                      <p class="mt-1 pl-[5.5rem] text-xs text-ink/50">
                        matching <?= esc($sec((float) $r['matcher_call_ms'])) ?>,
                        <?= $r['matcher_embed_ms'] === null
                            ? 'stored vector reused (read ' . esc($sec((float) $r['matcher_vector_read_ms'])) . ')'
                            : 'of which embed ' . esc($sec((float) $r['matcher_embed_ms'])) ?>
                      </p>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td class="py-3 pr-4 text-right font-semibold text-navy"><?= esc($sec($wait($r))) ?></td>
                <td class="py-3 pr-4 truncate text-xs text-ink/60" title="<?= esc((string) ($r['matcher_machine'] ?? '')) ?>"><?= esc($machine($r['matcher_machine'])) ?></td>
                <td class="py-3 text-center">
                  <?php if ($r['status'] === 'ok'): ?>
                    <span class="font-bold text-green-700" title="Matched">✓</span>
                  <?php else: ?>
                    <span class="font-bold text-red-700" title="Failed: <?= esc((string) $r['error']) ?>">✕</span>
                  <?php endif; ?>
                </td>
              </tr>
            </tbody>
          <?php endforeach; ?>
        </table>
      </div>

      <?php
        // Page links: first, last, and the two either side of this page; gaps become "…".
        $shown = array_unique([1, ...range(max(1, $page - 2), min($pages, $page + 2)), $pages]);
        $link  = static fn (int $p): string => current_url() . '?page=' . $p . '#runs';
        $pill  = 'rounded-full px-3 py-1 text-xs font-semibold';
      ?>
      <nav class="mt-5 flex flex-wrap items-center justify-between gap-3" aria-label="Runs pages">
        <p class="text-xs text-ink/50">
          Runs <?= esc((string) (($page - 1) * $perPage + 1)) ?>–<?= esc((string) (($page - 1) * $perPage + count($tableRuns))) ?> of <?= esc((string) $total) ?>
        </p>
        <?php if ($pages > 1): ?>
          <ul class="flex flex-wrap items-center gap-1">
            <li>
              <?php if ($page > 1): ?>
                <a href="<?= $link($page - 1) ?>" class="<?= $pill ?> text-navy hover:text-brand">← Newer</a>
              <?php else: ?>
                <span class="<?= $pill ?> text-ink/30">← Newer</span>
              <?php endif; ?>
            </li>
            <?php $previous = 0; ?>
            <?php foreach ($shown as $p): ?>
              <?php if ($p > $previous + 1): ?><li class="px-1 text-xs text-ink/40">…</li><?php endif; ?>
              <li>
                <?php if ($p === $page): ?>
                  <span class="<?= $pill ?> bg-navy text-white" aria-current="page"><?= $p ?></span>
                <?php else: ?>
                  <a href="<?= $link($p) ?>" class="<?= $pill ?> text-navy hover:bg-cream-50 hover:text-brand"><?= $p ?></a>
                <?php endif; ?>
              </li>
              <?php $previous = $p; ?>
            <?php endforeach; ?>
            <li>
              <?php if ($page < $pages): ?>
                <a href="<?= $link($page + 1) ?>" class="<?= $pill ?> text-navy hover:text-brand">Older →</a>
              <?php else: ?>
                <span class="<?= $pill ?> text-ink/30">Older →</span>
              <?php endif; ?>
            </li>
          </ul>
        <?php endif; ?>
      </nav>
    </section>

    <section class="<?= $cardCls ?>">
      <h2 class="<?= $sectionCls ?>">Taxonomy match</h2>
      <p class="<?= $hintCls ?>">What each suggested showcase shares with the gig's tags, over the latest <?= esc((string) $limit) ?> runs.</p>
      <div class="mt-5 grid gap-8 md:grid-cols-2">
        <?php foreach (['top' => 'Rank 1 only', 'all' => 'Every suggested showcase'] as $key => $heading): ?>
          <?php $total = max(1, array_sum($byLevel[$key])); ?>
          <div>
            <h3 class="text-sm font-semibold text-navy"><?= esc($heading) ?></h3>
            <dl class="mt-3 space-y-2 text-sm">
              <?php foreach (array_reverse($levels, true) as $level => $label): ?>
                <div class="grid grid-cols-[11rem_1fr_3rem] items-center gap-3">
                  <dt class="text-ink/70"><?= esc($label) ?></dt>
                  <dd class="h-3 rounded-sm bg-cream-50"><div class="h-3 rounded-sm bg-navy" style="width: <?= round($byLevel[$key][$level] / $total * 100, 1) ?>%"></div></dd>
                  <dd class="text-right font-semibold text-navy"><?= esc((string) $byLevel[$key][$level]) ?></dd>
                </div>
              <?php endforeach; ?>
            </dl>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

  <?php endif; ?>
</div>

<?= $this->endSection() ?>
