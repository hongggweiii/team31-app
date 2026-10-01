<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
require __DIR__ . '/partials/form-styles.php';

helper('form');

// $categories / $specialisations ([id => name]) and $seniorityLevels are
// injected by Gigs::new().
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

<form action="<?= url_to('gig-create') ?>" method="post" enctype="multipart/form-data"
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
        <input type="text" id="gig_title" name="gig_title" required
               value="<?= esc(old('gig_title', '')) ?>"
               placeholder="e.g. Interim CFO for a pre-Series B fintech" class="<?= $inputCls ?>">
        <?= $err('gig_title') ?>
      </div>

      <div>
        <label for="gig_summary" class="<?= $labelCls ?>">Short Gig Description <?= $req ?></label>
        <textarea id="gig_summary" name="gig_summary" rows="4" required
                  placeholder="Summarise the engagement in a few sentences." class="<?= $inputCls ?>"><?= esc(old('gig_summary', '')) ?></textarea>
        <?= $err('gig_summary') ?>
        <p class="<?= $hintCls ?>">This is what Providers see in search results.</p>
      </div>

      <div>
        <label for="gig_document" class="<?= $labelCls ?>">Upload Description</label>
        <input type="file" id="gig_document" name="gig_document" accept=".pdf,.doc,.docx"
               class="<?= $inputCls ?> file:mr-4 file:rounded-full file:border-0 file:bg-cream-50 file:px-4 file:py-1.5 file:text-sm file:font-semibold file:text-navy">
        <?= $err('gig_document') ?>
        <p class="<?= $hintCls ?>">Optional brief or scope document. PDF or Word, maximum 4MB.</p>
      </div>

      <div>
        <label for="gig_notes" class="<?= $labelCls ?>">
          Additional Notes <span class="font-normal text-ink/45">(optional)</span>
        </label>
        <textarea id="gig_notes" name="gig_notes" rows="3"
                  placeholder="Anything else worth knowing." class="<?= $inputCls ?>"><?= esc(old('gig_notes', '')) ?></textarea>
      </div>
    </div>
  </section>

  <!-- ── budget, seniority and timing ───────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Budget, Seniority &amp; Timing</h2>
    <p class="mt-2 text-sm text-ink/60">Used to match you with Providers whose rate, experience and availability fit.</p>

    <div class="mt-6 grid gap-5 sm:grid-cols-2">
      <div>
        <label for="budget_min" class="<?= $labelCls ?>">
          Minimum Budget <span class="font-normal text-ink/45">(optional)</span>
        </label>
        <input type="number" id="budget_min" name="budget_min" min="0" step="0.01"
               value="<?= esc(old('budget_min', '')) ?>" placeholder="e.g. 5000" class="<?= $inputCls ?>">
        <?= $err('budget_min') ?>
      </div>

      <div>
        <label for="budget_max" class="<?= $labelCls ?>">
          Maximum Budget <span class="font-normal text-ink/45">(optional)</span>
        </label>
        <input type="number" id="budget_max" name="budget_max" min="0" step="0.01"
               value="<?= esc(old('budget_max', '')) ?>" placeholder="e.g. 10000" class="<?= $inputCls ?>">
        <?= $err('budget_max') ?>
      </div>

      <div>
        <label for="seniority" class="<?= $labelCls ?>">Seniority Needed <?= $req ?></label>
        <select id="seniority" name="seniority" required class="<?= $inputCls ?>">
          <option value="">Select a level</option>
          <?php foreach ($seniorityLevels as $value => $label): ?>
            <option value="<?= esc($value) ?>" <?= old('seniority') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $err('seniority') ?>
      </div>

      <div>
        <label for="start_date" class="<?= $labelCls ?>">
          Start Date <span class="font-normal text-ink/45">(optional)</span>
        </label>
        <input type="date" id="start_date" name="start_date"
               value="<?= esc(old('start_date', '')) ?>" class="<?= $inputCls ?>">
        <?= $err('start_date') ?>
      </div>
    </div>
  </section>

  <!-- ── category and specialisation ────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Category &amp; Specialisation</h2>
    <p class="mt-2 text-sm text-ink/60">Search and select as many as apply.</p>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">

      <?php foreach ([
          ['category',       'Search by Category',       'categories',      $categories],
          ['specialisation', 'Search by Specialisation', 'specialisations', $specialisations],
      ] as [$key, $label, $field, $options]): ?>
        <?php $selected = array_map('strval', (array) old($field, [])); ?>
        <div class="js-multiselect" data-field="<?= esc($field) ?>">
          <label for="<?= $key ?>_search" class="<?= $labelCls ?>"><?= esc($label) ?> <?= $req ?></label>

          <input type="search" id="<?= $key ?>_search" autocomplete="off"
                 placeholder="Type to filter&hellip;"
                 class="<?= $inputCls ?> js-filter">

          <!-- selected items appear here as removable chips -->
          <div class="js-chips mt-3 flex flex-wrap gap-2 empty:mt-0"></div>

          <div class="js-options mt-3 max-h-56 space-y-1 overflow-y-auto rounded-lg border border-navy/10 p-2">
            <?php foreach ($options as $id => $option): ?>
              <label class="js-option flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink hover:bg-cream-25 has-[:checked]:bg-cream-50 has-[:checked]:font-semibold has-[:checked]:text-navy">
                <input type="checkbox" name="<?= esc($field) ?>[]" value="<?= esc($id) ?>"
                       data-label="<?= esc($option) ?>" <?= in_array((string) $id, $selected, true) ? 'checked' : '' ?>
                       class="<?= $checkCls ?>">
                <span class="js-label"><?= esc($option) ?></span>
              </label>
            <?php endforeach; ?>
            <p class="js-empty hidden px-3 py-2 text-sm text-ink/45">No matches.</p>
          </div>

          <?= $err($field) ?>
          <p class="<?= $hintCls ?>"><span class="js-count">0</span> selected</p>
        </div>
      <?php endforeach; ?>

    </div>
  </section>

  <!-- ── submit ─────────────────────────────────────── -->
  <div class="<?= $cardCls ?>">
    <button type="submit"
            class="w-full rounded-full bg-brand px-8 py-3.5 text-sm font-semibold text-white hover:bg-brand-hover transition-colors sm:w-auto">
      Submit Gig
    </button>
  </div>

</form>

<script>
/**
 * Searchable multi-select: filters the checkbox list as you type and mirrors
 * every checked option as a removable chip above it.
 */
document.querySelectorAll('.js-multiselect').forEach(function (root) {
  var filter  = root.querySelector('.js-filter');
  var chips   = root.querySelector('.js-chips');
  var empty   = root.querySelector('.js-empty');
  var count   = root.querySelector('.js-count');
  var options = Array.prototype.slice.call(root.querySelectorAll('.js-option'));

  function render() {
    chips.innerHTML = '';
    var checked = options.filter(function (o) { return o.querySelector('input').checked; });

    checked.forEach(function (option) {
      var input = option.querySelector('input');
      var chip  = document.createElement('button');
      chip.type = 'button';
      chip.className = 'inline-flex items-center gap-1.5 rounded-full bg-navy px-3 py-1.5 text-xs font-semibold text-white hover:bg-navy-dark transition-colors';
      chip.textContent = input.dataset.label;
      chip.setAttribute('aria-label', 'Remove ' + input.dataset.label);
      chip.insertAdjacentHTML('beforeend',
        '<svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>');
      chip.addEventListener('click', function () {
        input.checked = false;
        render();
      });
      chips.appendChild(chip);
    });

    count.textContent = checked.length;
  }

  filter.addEventListener('input', function () {
    var term = filter.value.trim().toLowerCase();
    var shown = 0;

    options.forEach(function (option) {
      var match = option.querySelector('.js-label').textContent.toLowerCase().indexOf(term) !== -1;
      // NB: a Tailwind display class beats the UA [hidden] rule, so toggle the
      // .hidden CLASS here — setting the hidden attribute would not hide it.
      option.classList.toggle('hidden', !match);
      if (match) shown++;
    });

    empty.classList.toggle('hidden', shown > 0);
  });

  root.addEventListener('change', function (event) {
    if (event.target.matches('input[type="checkbox"]')) render();
  });

  render();
});
</script>

<?= $this->endSection() ?>
