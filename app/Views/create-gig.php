<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
require __DIR__ . '/partials/form-styles.php';

$categories = [
    'Consultants (General)', 'Industry Experts', 'Accounting & Administrative',
    'Legal Assistance', 'Training & Education', 'Music, Dance, Performances',
    'Writing & Translation', 'Asset Management', 'Corporate & Administrative',
    'Financial', 'Human Resources', 'Industrial & Consumer Design',
    'Information Technology', 'Logistics & Procurement',
    'Marketing & Communication', 'Strategy',
];

$specialisations = [
    'Mergers & Acquisitions', 'Corporate Finance', 'Financial Due Diligence',
    'Interim CFO', 'Audit & Assurance', 'Tax Advisory', 'Treasury & Cash Management',
    'Anti-Money Laundering', 'Regulatory Compliance', 'Risk Management',
    'Internal Controls', 'Business Valuation', 'Restructuring & Turnaround',
    'Supply Chain Optimisation', 'Procurement Strategy', 'Process Improvement',
    'Change Management', 'Organisational Design', 'Talent Acquisition',
    'Compensation & Benefits', 'Learning & Development', 'Brand Strategy',
    'Digital Marketing', 'Market Research', 'Product Management',
    'Data Analytics', 'Cloud Architecture', 'Cybersecurity', 'ERP Implementation',
];
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

<form action="#" method="post" class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-12 space-y-8">

  <!-- ── listing details ────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Fill in your job listing details</h2>

    <div class="mt-6 space-y-5">
      <div>
        <label for="gig_title" class="<?= $labelCls ?>">Gig Title <?= $req ?></label>
        <input type="text" id="gig_title" name="gig_title" required
               placeholder="e.g. Interim CFO for a pre-Series B fintech" class="<?= $inputCls ?>">
      </div>

      <div>
        <label for="gig_summary" class="<?= $labelCls ?>">Short Gig Description <?= $req ?></label>
        <textarea id="gig_summary" name="gig_summary" rows="4" required
                  placeholder="Summarise the engagement in a few sentences." class="<?= $inputCls ?>"></textarea>
        <p class="<?= $hintCls ?>">This is what Hirers see in search results.</p>
      </div>

      <div>
        <label for="gig_document" class="<?= $labelCls ?>">Upload Description</label>
        <input type="file" id="gig_document" name="gig_document" accept=".pdf,.doc,.docx"
               class="<?= $inputCls ?> file:mr-4 file:rounded-full file:border-0 file:bg-cream-50 file:px-4 file:py-1.5 file:text-sm file:font-semibold file:text-navy">
        <p class="<?= $hintCls ?>">Optional brief or scope document. PDF or Word, maximum 4MB.</p>
      </div>

      <div>
        <label for="gig_notes" class="<?= $labelCls ?>">
          Additional Notes <span class="font-normal text-ink/45">(optional)</span>
        </label>
        <textarea id="gig_notes" name="gig_notes" rows="3"
                  placeholder="Budget range, start date, or anything else worth knowing." class="<?= $inputCls ?>"></textarea>
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
        <div class="js-multiselect" data-field="<?= esc($field) ?>">
          <label for="<?= $key ?>_search" class="<?= $labelCls ?>"><?= esc($label) ?> <?= $req ?></label>

          <input type="search" id="<?= $key ?>_search" autocomplete="off"
                 placeholder="Type to filter&hellip;"
                 class="<?= $inputCls ?> js-filter">

          <!-- selected items appear here as removable chips -->
          <div class="js-chips mt-3 flex flex-wrap gap-2 empty:mt-0"></div>

          <div class="js-options mt-3 max-h-56 space-y-1 overflow-y-auto rounded-lg border border-navy/10 p-2">
            <?php foreach ($options as $option): ?>
              <label class="js-option flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink hover:bg-cream-25 has-[:checked]:bg-cream-50 has-[:checked]:font-semibold has-[:checked]:text-navy">
                <input type="checkbox" name="<?= esc($field) ?>[]" value="<?= esc($option) ?>" class="<?= $checkCls ?>">
                <span class="js-label"><?= esc($option) ?></span>
              </label>
            <?php endforeach; ?>
            <p class="js-empty hidden px-3 py-2 text-sm text-ink/45">No matches.</p>
          </div>

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
      chip.textContent = input.value;
      chip.setAttribute('aria-label', 'Remove ' + input.value);
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
