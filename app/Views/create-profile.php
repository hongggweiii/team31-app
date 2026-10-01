<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
use App\Libraries\RichText;

require __DIR__ . '/partials/form-styles.php';

helper('form');

// $categories ([id => name]), $specialisations ([category_id => [id => name]]),
// $seniorityLevels, $locations and $documentCategories are injected by Showcases::new().
$errors = array_merge(validation_errors(), (array) session('errors'));
// Shows the error for $field, or for any of its array items (e.g. "services.0.title").
$err = static function (string $field) use ($errors): string {
    foreach ($errors as $key => $message) {
        if ($key === $field || str_starts_with($key, $field . '.')) {
            return '<p class="mt-1.5 text-xs font-semibold text-red-600">' . esc($message) . '</p>';
        }
    }

    return '';
};

/**
 * Rich-text field: a Quill editor that mirrors its HTML into a hidden input
 * named $name, so the form posts like a normal textarea. $value is cleaned
 * HTML to start from (e.g. re-filled input after a validation error).
 */
$editor = static fn (string $name, string $placeholder, bool $required = true, string $value = ''): string =>
    '<div class="js-rte" data-placeholder="' . esc($placeholder, 'attr') . '"'
    . ($required ? ' data-required' : '') . '>'
    . '<div class="js-rte-body min-h-[7rem] text-sm">' . $value . '</div>'
    . '<input type="hidden" name="' . esc($name, 'attr') . '" value="' . esc($value, 'attr') . '" class="js-rte-input">'
    . '</div>';
$oldRich = static fn (string $name): string => RichText::clean(old($name) ?? '');

$oldLocations = (array) (old('locations') ?? []);
$oldServices  = array_values(array_map(static fn ($s): array => [
    'title'  => (string) ($s['title'] ?? ''),
    'detail' => RichText::clean($s['detail'] ?? ''),
], array_filter((array) (old('services') ?? []), 'is_array')));
$oldTags = array_values(array_filter(array_map('strval', (array) (old('search_tags') ?? []))));
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<style>
  .js-rte .ql-toolbar.ql-snow { border-color: rgb(24 79 126 / .15); border-radius: .5rem .5rem 0 0; background: #fff; }
  .js-rte .ql-container.ql-snow { border-color: rgb(24 79 126 / .15); border-radius: 0 0 .5rem .5rem; background: #fff; font-family: inherit; }
  .js-rte .ql-editor { min-height: 7rem; }
  .js-rte .ql-editor.ql-blank::before { color: rgb(51 51 51 / .4); }
</style>

<div class="bg-cream-25 border-b border-navy/10">
  <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-14">
    <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">For Providers</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl">Create your Showcase</h1>
    <p class="mt-4 max-w-2xl leading-relaxed text-ink/70">
      Tell Hirers what you do and how you work. Fields marked
      <span class="font-semibold text-brand">*</span> are required.
    </p>
  </div>
</div>

<form id="profile_form" action="<?= url_to('showcase-create') ?>" method="post" enctype="multipart/form-data"
      class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-12 space-y-8">
  <?= csrf_field() ?>

  <?php if (session('message')): ?>
    <div class="rounded-card border border-green-600/20 bg-green-50 px-6 py-4 text-sm font-semibold text-green-800" role="status">
      <?= esc(session('message')) ?>
    </div>
  <?php elseif ($errors !== []): ?>
    <div class="rounded-card border border-red-600/20 bg-red-50 px-6 py-4 text-sm text-red-700" role="alert">
      <p class="font-semibold">Please fix the highlighted fields.</p>
      <p class="mt-1">For security, any photo or documents you chose need to be attached again.</p>
      <?php if (isset($errors['showcase'])): ?><p class="mt-1"><?= esc($errors['showcase']) ?></p><?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- ── showcase ───────────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Your showcase</h2>

    <div class="mt-6 grid gap-5 sm:grid-cols-2">
      <div class="sm:col-span-2">
        <label for="service_category" class="<?= $labelCls ?>">Select a Category for your service <?= $req ?></label>
        <select id="service_category" name="service_category" required class="<?= $inputCls ?>">
          <option value="">Please select a category</option>
          <?php foreach ($categories as $id => $cat): ?>
            <option value="<?= esc($id) ?>" <?= (string) old('service_category') === (string) $id ? 'selected' : '' ?>><?= esc($cat) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $err('service_category') ?>
      </div>

      <!-- shown once a category is picked; options come from specialities.category_id -->
      <div id="specialisation_field" class="sm:col-span-2" hidden>
        <label for="specialisation" class="<?= $labelCls ?>">Area of specialisation <?= $req ?></label>
        <select id="specialisation" name="specialisation" class="<?= $inputCls ?>" disabled
                data-old="<?= esc((string) old('specialisation'), 'attr') ?>">
          <option value="">Please select a specialisation</option>
        </select>
        <p id="specialisation_empty" class="<?= $hintCls ?>" hidden>This category has no specialisations.</p>
        <?= $err('specialisation') ?>
      </div>

      <div class="sm:col-span-2">
        <label for="title" class="<?= $labelCls ?>">Title <?= $req ?></label>
        <input type="text" id="title" name="title" required maxlength="255"
               value="<?= esc(old('title', '')) ?>"
               placeholder="e.g. Financial Due Diligence" class="<?= $inputCls ?>">
        <?= $err('title') ?>
      </div>
      <div>
        <label for="years" class="<?= $labelCls ?>">Years of Experience <?= $req ?></label>
        <input type="number" id="years" name="years" min="0" max="70" required
               value="<?= esc(old('years', '')) ?>" class="<?= $inputCls ?>">
        <?= $err('years') ?>
      </div>
      <div>
        <label for="seniority" class="<?= $labelCls ?>">Seniority <?= $req ?></label>
        <select id="seniority" name="seniority" required class="<?= $inputCls ?>">
          <option value="">Select seniority</option>
          <?php foreach ($seniorityLevels as $value => $label): ?>
            <option value="<?= esc($value) ?>" <?= old('seniority') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $err('seniority') ?>
      </div>

      <div class="sm:col-span-2">
        <span class="<?= $labelCls ?>">Credentials <?= $req ?></span>
        <?= $editor('credentials', 'Educational Degrees, significant Certificates, Professional Admissions, Awards and other relevant credentials', true, $oldRich('credentials')) ?>
        <?= $err('credentials') ?>
      </div>

      <div class="sm:col-span-2">
        <span class="<?= $labelCls ?>">Relevant Achievements</span>
        <?= $editor('relevant_achievements', 'Past engagements and results, one per bullet. E.g. Advised banks, captives and independent insurers on...', false, $oldRich('relevant_achievements')) ?>
        <?= $err('relevant_achievements') ?>
      </div>

      <div class="sm:col-span-2">
        <label for="photo" class="<?= $labelCls ?>">Photo</label>
        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"
               class="<?= $inputCls ?> file:mr-4 file:rounded-full file:border-0 file:bg-cream-50 file:px-4 file:py-1.5 file:text-sm file:font-semibold file:text-navy">
        <p class="<?= $hintCls ?>">Half length profile picture. Minimum resolution 325px, maximum file size 4MB.</p>
        <?= $err('photo') ?>
      </div>
    </div>
  </section>

  <!-- ── about ──────────────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">About</h2>

    <div class="mt-6 space-y-5">
      <div>
        <label for="headline" class="<?= $labelCls ?>">Headline <?= $req ?></label>
        <input type="text" id="headline" name="headline" required maxlength="255"
               value="<?= esc(old('headline', '')) ?>"
               placeholder="e.g. Fractional CFO | Strategy &amp; Finance Expert" class="<?= $inputCls ?>">
        <?= $err('headline') ?>
      </div>
      <div>
        <span class="<?= $labelCls ?>">Bio <?= $req ?></span>
        <?= $editor('about', 'Add your bio here...', true, $oldRich('about')) ?>
        <?= $err('about') ?>
      </div>
    </div>
  </section>

  <!-- ── services I offer ───────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Services I offer <?= $req ?></h2>
    <p class="<?= $hintCls ?>">One service per box. Add as many as you offer.</p>
    <?= $err('services') ?>

    <div id="services" class="mt-6 grid gap-5 sm:grid-cols-2">
      <button type="button" id="add_service"
              class="flex min-h-[12rem] items-center justify-center rounded-card border-2 border-dashed border-navy/15 hover:border-brand transition">
        <span class="rounded-full bg-navy px-5 py-2.5 text-sm font-semibold text-white">+ Add Another Service</span>
      </button>
    </div>

    <template id="service_template">
      <div class="js-service rounded-card bg-cream-25 p-5">
        <div class="flex items-start gap-3">
          <input type="text" name="services[__i__][title]" required maxlength="255"
                 placeholder="Service Title" class="<?= $inputCls ?>">
          <button type="button" class="js-remove-service mt-2 text-ink/50 hover:text-brand" aria-label="Remove service">&times;</button>
        </div>
        <div class="mt-3">
          <?= $editor('services[__i__][detail]', 'Enter detail for this service', false) ?>
        </div>
      </div>
    </template>
  </section>

  <!-- ── how I work ─────────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">How I work</h2>

    <div class="mt-6 space-y-6">
      <div>
        <span class="<?= $labelCls ?>">How I Work <?= $req ?></span>
        <?= $editor('how_i_work', 'Describe how you work, e.g. engagement types, travel willingness...', true, $oldRich('how_i_work')) ?>
        <?= $err('how_i_work') ?>
      </div>

      <div>
        <span class="<?= $labelCls ?>">Your preferred location of work <?= $req ?></span>
        <div class="flex flex-wrap gap-2.5">
          <?php foreach ($locations as $loc): ?>
            <label class="<?= $chipCls ?>">
              <input type="checkbox" name="locations[]" value="<?= esc($loc) ?>" class="<?= $checkCls ?>"
                     <?= in_array($loc, $oldLocations, true) ? 'checked' : '' ?>>
              <?= esc($loc) ?>
            </label>
          <?php endforeach; ?>
        </div>
        <?= $err('locations') ?>
      </div>
    </div>
  </section>

  <!-- ── availability and rate ──────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Availability &amp; rate</h2>

    <div class="mt-6 space-y-6">
      <div class="grid gap-5 sm:grid-cols-2">
        <div>
          <label for="available_from" class="<?= $labelCls ?>">Available From <?= $req ?></label>
          <input type="date" id="available_from" name="available_from" required
                 value="<?= esc(old('available_from', '')) ?>" class="<?= $inputCls ?>">
          <label class="mt-2 flex items-center gap-2 text-sm text-ink/75">
            <input type="checkbox" id="available_now" name="available_now" value="1" class="<?= $checkCls ?>"
                   <?= old('available_now') === '1' ? 'checked' : '' ?>>
            Available now
          </label>
          <?= $err('available_from') ?>
        </div>
        <div>
          <label for="capacity" class="<?= $labelCls ?>">Capacity <?= $req ?></label>
          <select id="capacity" name="capacity" required class="<?= $inputCls ?>">
            <option value="">Days per week</option>
            <?php for ($d = 1; $d <= 5; $d++): ?>
              <option value="<?= $d ?>" <?= old('capacity') === (string) $d ? 'selected' : '' ?>><?= $d ?> day<?= $d > 1 ? 's' : '' ?> a week</option>
            <?php endfor; ?>
          </select>
          <?= $err('capacity') ?>
        </div>
      </div>
      <div>
        <span class="<?= $labelCls ?>">Availability Notes</span>
        <?= $editor('availability', 'E.g. On holiday from ___ to ___. | Committed on Monday, Wednesday & Friday afternoons? | Generally Available by Appointment', false, $oldRich('availability')) ?>
      </div>

      <div>
        <label for="rate_per_hour" class="<?= $labelCls ?>">Rate (per hour) <?= $req ?></label>
        <div class="flex sm:max-w-xs">
          <span class="inline-flex items-center rounded-l-lg border border-r-0 border-navy/15 bg-cream-25 px-3 text-sm font-medium text-ink/60">S$</span>
          <input type="number" id="rate_per_hour" name="rate_per_hour" min="1" step="1" required
                 value="<?= esc(old('rate_per_hour', '')) ?>"
                 inputmode="numeric" class="<?= $inputCls ?> rounded-l-none">
        </div>
        <p class="<?= $hintCls ?>">Used to match you with Hirers' budgets.</p>
        <?= $err('rate_per_hour') ?>
      </div>
      <div>
        <span class="<?= $labelCls ?>">Rate Notes</span>
        <?= $editor('rate_notes', 'E.g. Fixed-fee packages, retainers or minimum engagement', false, $oldRich('rate_notes')) ?>
      </div>
    </div>
  </section>

  <!-- ── search tags ────────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Search tags</h2>
    <p class="<?= $hintCls ?>">Keywords Hirers might search for. Press Enter or comma after each tag.</p>

    <div id="search_tags" class="mt-6 flex flex-wrap items-center gap-2 rounded-lg border border-navy/15 bg-cream-25 p-3 focus-within:border-brand focus-within:ring-2 focus-within:ring-brand/20 transition">
      <input type="text" id="search_tag_input" placeholder="e.g. due diligence" maxlength="50"
             class="min-w-[10rem] flex-1 border-0 bg-transparent p-1 text-sm focus:outline-none focus:ring-0">
    </div>
    <?= $err('search_tags') ?>
  </section>

  <!-- ── links ──────────────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Links</h2>
    <p class="<?= $hintCls ?>">Attach supporting documents to each category. Each document belongs to one category.
      PDF, Word, PowerPoint or image files up to 4MB each.</p>

    <ul class="mt-6 divide-y divide-navy/10">
      <?php foreach ($documentCategories as $key => $label): ?>
        <li class="py-4">
          <div class="flex items-center justify-between gap-4">
            <span class="text-sm font-semibold text-ink"><?= esc($label) ?></span>
            <label class="cursor-pointer rounded-lg border border-navy px-3 py-1.5 text-sm font-semibold text-navy hover:bg-navy hover:text-white transition">
              + Attach Document
              <input type="file" name="links[<?= esc($key) ?>][]" multiple
                     accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png" class="js-link-file sr-only">
            </label>
          </div>
          <ul class="js-link-files mt-2 space-y-1 text-xs text-ink/60 empty:mt-0"></ul>
          <?= $err('links.' . $key) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- ── submit ─────────────────────────────────────── -->
  <div class="<?= $cardCls ?>">
    <label class="flex items-start gap-3 text-sm text-ink/75">
      <input type="checkbox" name="terms" value="1" required class="<?= $checkCls ?> mt-0.5">
      <span>By submitting this information, you agree to our
        <a href="#" class="font-semibold text-brand hover:underline">Terms of Service</a>.</span>
    </label>
    <?= $err('terms') ?>

    <button type="submit"
            class="mt-6 w-full rounded-full bg-brand px-8 py-3.5 text-sm font-semibold text-white hover:bg-brand-hover transition-colors sm:w-auto">
      Publish Showcase
    </button>
  </div>

</form>

<script>
/**
 * Category -> specialisation dropdown. Specialisations are keyed by their
 * parent category id (specialities.category_id).
 */
(function () {
  var map      = <?= json_encode((object) $specialisations, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var category = document.getElementById('service_category');
  var field    = document.getElementById('specialisation_field');
  var select   = document.getElementById('specialisation');
  var empty    = document.getElementById('specialisation_empty');

  function render() {
    var options = map[category.value] || {};
    var ids = Object.keys(options).sort(function (a, b) { return options[a].localeCompare(options[b]); });

    select.length = 1; // keep the placeholder
    ids.forEach(function (id) { select.add(new Option(options[id], id)); });

    field.hidden    = category.value === '';
    select.disabled = ids.length === 0;
    select.required = ids.length > 0;
    empty.hidden    = ids.length > 0;
  }

  category.addEventListener('change', render);
  render();
  select.value = select.dataset.old; // re-select after a validation error
})();

/** Rich-text editors (Quill), services repeater, search tags and link file lists. */
(function () {
  var form = document.getElementById('profile_form');
  var toolbar = [['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], ['clean']];

  function initEditor(root) {
    var input = root.querySelector('.js-rte-input');
    var quill = new Quill(root.querySelector('.js-rte-body'), {
      theme: 'snow',
      placeholder: root.dataset.placeholder,
      modules: { toolbar: toolbar },
    });
    quill.on('text-change', function () {
      input.value = quill.getText().trim() === '' ? '' : quill.root.innerHTML;
    });
    root.quill = quill;
  }
  document.querySelectorAll('.js-rte').forEach(initEditor); // template content isn't matched

  // Hidden inputs can't use `required`, so check editors on submit.
  form.addEventListener('submit', function (e) {
    var blank = Array.prototype.find.call(form.querySelectorAll('.js-rte[data-required]'), function (root) {
      return root.querySelector('.js-rte-input').value === '';
    });
    if (blank) {
      e.preventDefault();
      blank.scrollIntoView({ behavior: 'smooth', block: 'center' });
      blank.quill.focus();
    }
  });

  // Services I offer: one box per service, at least one kept.
  var services    = document.getElementById('services');
  var addBtn      = document.getElementById('add_service');
  var tpl         = document.getElementById('service_template');
  var oldServices = <?= json_encode($oldServices, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var next        = 0;

  function addService(service) {
    var html = tpl.innerHTML.replace(/__i__/g, String(next++));
    addBtn.insertAdjacentHTML('beforebegin', html);
    var box = addBtn.previousElementSibling;
    box.querySelectorAll('.js-rte').forEach(initEditor);
    if (service) {
      box.querySelector('input[type=text]').value = service.title;
      // detail was sanitised server-side (RichText::clean).
      if (service.detail) box.querySelector('.js-rte').quill.clipboard.dangerouslyPasteHTML(service.detail);
    }
    box.querySelector('.js-remove-service').addEventListener('click', function () {
      if (services.querySelectorAll('.js-service').length > 1) box.remove();
    });
    return box;
  }
  addBtn.addEventListener('click', function () { addService().querySelector('input').focus(); });
  if (oldServices.length) oldServices.forEach(addService); else addService();

  // Search tags: Enter/comma turns the typed text into a removable chip.
  var tagBox   = document.getElementById('search_tags');
  var tagInput = document.getElementById('search_tag_input');

  function addTag(text) {
    text = text.trim();
    if (!text) return;
    var exists = Array.prototype.some.call(tagBox.querySelectorAll('input[type=hidden]'), function (i) {
      return i.value.toLowerCase() === text.toLowerCase();
    });
    if (exists) return;

    var chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'inline-flex items-center gap-1.5 rounded-full bg-navy px-3 py-1.5 text-xs font-semibold text-white hover:bg-navy-dark transition-colors';
    chip.textContent = text;
    chip.setAttribute('aria-label', 'Remove ' + text);
    chip.insertAdjacentHTML('beforeend', '<span aria-hidden="true">&times;</span>');
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'search_tags[]';
    hidden.value = text;
    chip.appendChild(hidden);
    chip.addEventListener('click', function () { chip.remove(); });
    tagBox.insertBefore(chip, tagInput);
  }
  tagInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      addTag(tagInput.value);
      tagInput.value = '';
    } else if (e.key === 'Backspace' && tagInput.value === '' && tagInput.previousElementSibling) {
      tagInput.previousElementSibling.remove();
    }
  });
  tagInput.addEventListener('blur', function () { addTag(tagInput.value); tagInput.value = ''; });
  <?= json_encode($oldTags, JSON_HEX_TAG | JSON_HEX_AMP) ?>.forEach(addTag);

  // "Available now" stands in for a date (saved as available_now = 1, no date).
  var availNow  = document.getElementById('available_now');
  var availFrom = document.getElementById('available_from');
  function syncAvailability() {
    availFrom.disabled = availFrom.hidden = availNow.checked;
    availFrom.required = !availNow.checked;
  }
  availNow.addEventListener('change', syncAvailability);
  syncAvailability();

  // Links: list the chosen file names under each category.
  document.querySelectorAll('.js-link-file').forEach(function (input) {
    input.addEventListener('change', function () {
      var list = input.closest('li').querySelector('.js-link-files');
      list.innerHTML = '';
      Array.prototype.forEach.call(input.files, function (f) {
        var li = document.createElement('li');
        li.textContent = f.name;
        list.appendChild(li);
      });
    });
  });
})();
</script>

<?= $this->endSection() ?>
