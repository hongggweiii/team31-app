<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
require __DIR__ . '/../partials/form-styles.php';

// $status, $statuses and $proposals (taxonomy-service's rows, or null when it
// is unreachable) come from TaxonomyDashboard::proposals().
$flags      = [ // auto_flag => [label, badge colours]
    'exact'   => ['Already exists', 'border-red-600/30 bg-red-50 text-red-800'],
    'fuzzy'   => ['Near-identical', 'border-amber-600/30 bg-amber-50 text-amber-800'],
    'synonym' => ['Synonym', 'border-amber-600/30 bg-amber-50 text-amber-800'],
    'none'    => ['New term', 'border-green-600/30 bg-green-50 text-green-800'],
];
$matchKinds = ['sf_skills' => 'skill', 'sf_specialisations' => 'specialisation', 'taxonomy_proposals' => 'pending term'];
$where      = static fn (array $p): string => implode(' › ', array_filter([$p['category'], $p['kind'] === 'skill' ? $p['specialisation'] : null]));
$owner      = static fn (array $p): string => $p['owner_id'] !== null ? "{$p['owner_type']} #{$p['owner_id']}" : 'not saved yet';
$badgeCls   = 'inline-block rounded-full border px-2.5 py-0.5 text-xs font-semibold';
$primaryCls = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white hover:bg-brand-hover transition-colors';
$quietCls   = 'rounded-full border border-navy/20 px-5 py-2 text-sm font-semibold text-navy hover:border-red-600 hover:text-red-700 transition-colors';
$cellCls    = 'px-4 py-4';
?>

<?= $this->include('taxonomy-dashboard/header') ?>

<div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-8 space-y-8">

  <!-- ── review queue ──────────────────────────────────── -->
  <section class="space-y-5">
    <div class="flex flex-wrap gap-2">
      <?php foreach ($statuses as $key => $label): ?>
        <a href="<?= url_to('taxonomy-dashboard') ?>?status=<?= $key ?>" <?= $key === $status ? 'aria-current="page"' : '' ?>
           class="rounded-full px-4 py-1.5 text-sm font-semibold <?= $key === $status ? 'bg-navy text-white' : 'border border-navy/15 text-navy hover:border-brand' ?>">
          <?= esc($label) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($proposals === null): ?>
      <div class="rounded-card border border-red-600/20 bg-red-50 px-6 py-4 text-sm text-red-700" role="alert">
        The taxonomy service is unavailable. Start taxonomy-service/ and refresh this page.
      </div>
    <?php elseif ($proposals === []): ?>
      <div class="<?= $cardCls ?> text-sm text-ink/70">No <?= esc(mb_strtolower($statuses[$status])) ?> terms.</div>
    <?php elseif ($status === 'pending_review'): ?>
      <form method="post" action="<?= url_to('taxonomy-review') ?>" class="space-y-4">
        <?= csrf_field() ?>
        <div class="rounded-card border border-navy/10 bg-white overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-navy/10 text-xs uppercase tracking-wide text-ink/50">
              <tr>
                <th class="<?= $cellCls ?>">Term</th>
                <th class="<?= $cellCls ?>">Would go under</th>
                <th class="<?= $cellCls ?>">Source</th>
                <th class="<?= $cellCls ?>">For</th>
                <th class="<?= $cellCls ?>">Duplicate check</th>
                <th class="<?= $cellCls ?>"><span class="sr-only">Decision</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-navy/10">
              <?php foreach ($proposals as $p): ?>
                <?php [$flagLabel, $flagCls] = $flags[$p['auto_flag']] ?? $flags['none']; ?>
                <tr class="align-top">
                  <td class="<?= $cellCls ?>">
                    <span class="font-semibold text-navy"><?= esc($p['proposed_name']) ?></span>
                    <span class="block text-xs text-ink/50">#<?= esc((string) $p['id']) ?> · <?= esc($p['kind']) ?></span>
                  </td>
                  <td class="<?= $cellCls ?>"><?= esc($where($p)) ?></td>
                  <td class="<?= $cellCls ?>">
                    <?= $p['submitted_by_source'] === 'ai_suggestion' ? 'AI' : esc(ucfirst($p['submitted_by_source'])) ?>
                    <?php if ($p['confidence'] !== null): ?>
                      <span class="block text-xs text-ink/50"><?= round($p['confidence'] * 100) ?>% confident</span>
                    <?php endif; ?>
                  </td>
                  <td class="<?= $cellCls ?>">
                    <?= esc($owner($p)) ?>
                    <?php if ($p['submitted_by_user'] !== null): ?>
                      <span class="block text-xs text-ink/50">user <?= esc((string) $p['submitted_by_user']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="<?= $cellCls ?>">
                    <span class="<?= $badgeCls ?> <?= $flagCls ?>"><?= $flagLabel ?></span>
                    <?php if ($p['closest_match_name'] !== null): ?>
                      <span class="mt-1 block text-xs text-ink/60">
                        <?= esc($matchKinds[$p['closest_match_table']] ?? 'term') ?> “<?= esc($p['closest_match_name']) ?>”<?=
                          $p['auto_flag'] === 'fuzzy' ? ', ' . esc((string) $p['similarity_score']) . '% similar' : '' ?>
                      </span>
                    <?php endif; ?>
                    <?php if ($p['duplicates'] !== []): ?>
                      <span class="mt-1 block text-xs text-ink/60">Same term as #<?= esc(implode(', #', $p['duplicates'])) ?>: decided together</span>
                    <?php endif; ?>
                  </td>
                  <td class="<?= $cellCls ?> whitespace-nowrap">
                    <button type="submit" name="approve" value="<?= esc((string) $p['id']) ?>" class="<?= $primaryCls ?>">Approve</button>
                    <button type="submit" name="reject" value="<?= esc((string) $p['id']) ?>" class="<?= $quietCls ?>">Reject</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      </form>
    <?php else: ?>
      <div class="rounded-card border border-navy/10 bg-white overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-navy/10 text-xs uppercase tracking-wide text-ink/50">
            <tr>
              <th class="<?= $cellCls ?>">Term</th>
              <th class="<?= $cellCls ?>">Under</th>
              <th class="<?= $cellCls ?>">For</th>
              <th class="<?= $cellCls ?>"><?= $status === 'approved' ? 'Approved' : 'Rejected' ?></th>
              <?php if ($status === 'approved'): ?><th class="<?= $cellCls ?>">Became</th><?php endif; ?>
            </tr>
          </thead>
          <tbody class="divide-y divide-navy/10">
            <?php foreach ($proposals as $p): ?>
              <tr class="align-top">
                <td class="<?= $cellCls ?>">
                  <span class="font-semibold text-navy"><?= esc($p['proposed_name']) ?></span>
                  <span class="block text-xs text-ink/50">#<?= esc((string) $p['id']) ?> · <?= esc($p['kind']) ?></span>
                </td>
                <td class="<?= $cellCls ?>"><?= esc($where($p)) ?></td>
                <td class="<?= $cellCls ?>"><?= esc($owner($p)) ?></td>
                <td class="<?= $cellCls ?> whitespace-nowrap"><?= esc((string) $p['actioned_at']) ?></td>
                <?php if ($status === 'approved'): ?>
                  <td class="<?= $cellCls ?>"><?= esc("{$p['kind']} #{$p['approved_item_id']}") ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?= $this->endSection() ?>
