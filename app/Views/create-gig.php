<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
require __DIR__ . '/partials/form-styles.php';

helper('form');

// $taxonomy (App\Libraries\Taxonomy::tree()) is injected by Gigs::new().
$errors = array_merge(validation_errors(), (array) session('errors'));
// Shows the error for $field, or for any of its array items (e.g. "categories.3").
$err = static function (string $field) use ($errors): string {
    foreach ($errors as $key => $message) {
        if ($key === $field || str_starts_with($key, $field . '.')) {
            return '<p class="mt-1.5 text-xs font-semibold text-red-600">' . esc($message) . '</p>';
        }
    }

    return '';
};
?>

<div class="bg-cream-25 border-b border-navy/10">
  <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-14">
    <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">For Hirers</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl">Post a Job</h1>
    <p class="mt-4 max-w-2xl leading-relaxed text-ink/70">
      Describe the work you need done and tag it so the right Providers find it.
      Fields marked <span class="font-semibold text-brand">*</span> are required.
    </p>
  </div>
</div>

<form action="<?= url_to('gig-create') ?>" method="post"
      class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-12 space-y-8">
  <?= csrf_field() ?>

  <?php if (session('message')): ?>
    <div class="rounded-card border border-green-600/20 bg-green-50 px-6 py-4 text-sm font-semibold text-green-800" role="status">
      <?= esc(session('message')) ?>
    </div>
  <?php elseif ($errors !== []): ?>
    <div class="rounded-card border border-red-600/20 bg-red-50 px-6 py-4 text-sm text-red-700" role="alert">
      <p class="font-semibold">Please fix the highlighted fields.</p>
      <?php if (isset($errors['gig'])): ?><p class="mt-1"><?= esc($errors['gig']) ?></p><?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- ── listing details ────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Fill in your job listing details</h2>

    <div class="mt-6 space-y-5">
      <div>
        <label for="gig_title" class="<?= $labelCls ?>">Gig Title <?= $req ?></label>
        <input type="text" id="gig_title" name="gig_title" required maxlength="500"
               value="<?= esc(old('gig_title', '', false)) ?>"
               placeholder="e.g. Interim CFO for a pre-Series B fintech" class="<?= $inputCls ?>">
        <?= $err('gig_title') ?>
        <p class="<?= $hintCls ?>">3–10 words naming the task, not a job title.</p>
      </div>

      <div>
        <label for="gig_summary" class="<?= $labelCls ?>">Short Gig Description <?= $req ?></label>
        <textarea id="gig_summary" name="gig_summary" rows="4" required
                  placeholder="Summarise the engagement in a few sentences." class="<?= $inputCls ?>"><?= esc(old('gig_summary', '', false)) ?></textarea>
        <?= $err('gig_summary') ?>
        <p class="<?= $hintCls ?>">This is what Providers see in search results. About 55–120 words: the need and the work,
          then a sentence starting “Deliverable:” and a last one starting “Engagement duration:”.</p>
      </div>

      <div>
        <span class="<?= $labelCls ?>">Engagement Duration</span>
        <div class="flex items-center gap-3">
          <label for="duration_weeks_min" class="sr-only">Minimum weeks</label>
          <input type="number" id="duration_weeks_min" name="duration_weeks_min" min="1" max="52" step="1"
                 value="<?= esc(old('duration_weeks_min', '', false)) ?>" placeholder="From" class="<?= $inputCls ?> max-w-[7rem]">
          <span class="text-sm text-ink/60">to</span>
          <label for="duration_weeks_max" class="sr-only">Maximum weeks</label>
          <input type="number" id="duration_weeks_max" name="duration_weeks_max" min="1" max="52" step="1"
                 value="<?= esc(old('duration_weeks_max', '', false)) ?>" placeholder="To" class="<?= $inputCls ?> max-w-[7rem]">
          <span class="text-sm text-ink/60">weeks</span>
        </div>
        <?= $err('duration_weeks_min') ?>
        <?= $err('duration_weeks_max') ?>
        <p class="<?= $hintCls ?>">Optional. Fill in one box for a fixed length.</p>
      </div>

      <div>
        <label for="additional_notes" class="<?= $labelCls ?>">Additional Notes</label>
        <textarea id="additional_notes" name="additional_notes" rows="3" maxlength="2000"
                  placeholder="Budget, timeline or seniority expectations, if any." class="<?= $inputCls ?>"><?= esc(old('additional_notes', '', false)) ?></textarea>
        <?= $err('additional_notes') ?>
      </div>
    </div>
  </section>

  <!-- ── category, specialisations, search tags ─────── -->
  <?php require __DIR__ . '/partials/taxonomy-picker.php'; ?>

  <!-- ── submit ─────────────────────────────────────── -->
  <div class="<?= $cardCls ?>">
    <button type="submit"
            class="w-full rounded-full bg-brand px-8 py-3.5 text-sm font-semibold text-white hover:bg-brand-hover transition-colors sm:w-auto">
      Submit Gig
    </button>
  </div>

</form>

<?= $this->endSection() ?>
