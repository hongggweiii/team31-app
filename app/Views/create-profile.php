<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
use App\Libraries\RichText;

require __DIR__ . '/partials/form-styles.php';

helper('form');

// $taxonomy (App\Libraries\Taxonomy::tree()), $documentCategories and $limits
// (most boxes per repeatable section) are injected by Showcases::new().
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
$editor = static fn (string $name, string $placeholder, bool $required = false, string $value = ''): string =>
    '<div class="js-rte" data-placeholder="' . esc($placeholder, 'attr') . '"'
    . ($required ? ' data-required' : '') . '>'
    . '<div class="js-rte-body min-h-[7rem] text-sm">' . $value . '</div>'
    . '<input type="hidden" name="' . esc($name, 'attr') . '" value="' . esc($value, 'attr') . '" class="js-rte-input">'
    . '</div>';
// Old input is read raw (old()'s default HTML-escaping would turn "<p>" into literal
// "&lt;p&gt;" text on re-submit); everything below is sanitised or escaped on output.
$oldRich = static fn (string $name): string => RichText::clean(old($name, '', false));

$boxes        = static fn (string $field): array => array_values(array_filter((array) old($field, [], false), 'is_array'));
$oldCredentials = array_values(array_filter((array) old('credentials', [], false), 'is_string'));
$oldServices  = array_map(static fn (array $s): array => [
    'title'  => (string) ($s['title'] ?? ''),
    'detail' => RichText::clean($s['detail'] ?? ''),
], $boxes('services'));
$oldAchievements = array_values(array_filter(array_map(
    static fn ($a): string => is_string($a) ? RichText::clean($a) : '',
    (array) old('achievements', [], false)
)));
$oldSkillGroups = array_map(static fn (array $g): array => [
    'name'   => (string) ($g['name'] ?? ''),
    'skills' => array_values(array_filter((array) ($g['skills'] ?? []), 'is_string')),
], $boxes('skill_groups'));

$json = static fn ($value): string => json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$boxCls      = 'rounded-card bg-cream-25 p-5';
$addBoxCls   = 'flex min-h-[10rem] items-center justify-center rounded-card border-2 border-dashed border-navy/15 hover:border-brand transition';
$addBtnCls   = 'rounded-full bg-navy px-5 py-2.5 text-sm font-semibold text-white';
$chipBoxCls  = 'js-chip-box flex flex-wrap items-center gap-2 rounded-full border border-navy/15 bg-white px-3 py-2 focus-within:border-brand';
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
      data-loading="Publishing your showcase…"
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

  <!-- ── photo, name, title, experience, credentials ── -->
  <section class="<?= $cardCls ?>">
    <div class="flex flex-col items-center">
      <label for="photo" class="relative cursor-pointer" title="Upload photo">
        <span class="flex h-28 w-28 items-center justify-center overflow-hidden rounded-full border-2 border-navy bg-sky-50">
          <img id="photo_preview" alt="" class="hidden h-full w-full object-cover">
          <svg id="photo_placeholder" class="h-16 w-16 text-navy/30" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"/>
          </svg>
        </span>
        <span class="absolute -bottom-1 -right-1 flex h-9 w-9 items-center justify-center rounded-full bg-white shadow">
          <svg class="h-5 w-5 text-ink" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>
          </svg>
        </span>
        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" class="sr-only">
      </label>
      <p class="<?= $hintCls ?> text-center">Half length photo, at least 325px, up to 4MB.</p>
      <?= $err('photo') ?>

      <label for="name" class="sr-only">Name</label>
      <input type="text" id="name" name="name" required maxlength="150"
             value="<?= esc(old('name', '', false)) ?>" placeholder="Your name *"
             class="mt-4 w-full max-w-sm border-0 border-b-2 border-navy/15 bg-transparent text-center text-xl font-semibold text-ink placeholder:text-ink/35 focus:border-brand focus:outline-none focus:ring-0">
      <?= $err('name') ?>
    </div>

    <div class="mt-8 space-y-6">
      <div>
        <label for="title" class="<?= $labelCls ?>">Title <?= $req ?></label>
        <input type="text" id="title" name="title" required maxlength="255"
               value="<?= esc(old('title', '', false)) ?>"
               placeholder="e.g. Forensic Accountant" class="<?= $inputCls ?>">
        <?= $err('title') ?>
        <p class="<?= $hintCls ?>">Your job title in 2–8 words.</p>
      </div>
      <div>
        <label for="years_experience" class="<?= $labelCls ?>">Years of Experience</label>
        <input type="number" id="years_experience" name="years_experience" min="0" max="60" step="1"
               value="<?= esc(old('years_experience', '', false)) ?>" placeholder="e.g. 12" class="<?= $inputCls ?> max-w-[8rem]">
        <?= $err('years_experience') ?>
        <p class="<?= $hintCls ?>">Years working in your field.</p>
      </div>
      <div>
        <span class="<?= $labelCls ?>">Credentials</span>
        <p class="<?= $hintCls ?> mt-0 mb-3">Professional registrations, certifications and memberships, one per box (up to <?= $limits['credentials'] ?>).</p>
        <?= $err('credentials') ?>
        <div id="credentials" class="space-y-3">
          <button type="button" class="js-add text-sm font-semibold text-brand hover:underline">+ Add Another Credential</button>
        </div>
        <template id="credential_template">
          <div class="js-box flex items-center gap-3">
            <input type="text" name="credentials[__i__]" maxlength="255"
                   placeholder="e.g. Certified Public Accountant (CPA)" class="<?= $inputCls ?>">
            <button type="button" class="js-remove text-ink/50 hover:text-brand" aria-label="Remove credential">&times;</button>
          </div>
        </template>
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
               value="<?= esc(old('headline', '', false)) ?>"
               placeholder="e.g. Fractional CFO | Strategy &amp; Finance Expert" class="<?= $inputCls ?>">
        <?= $err('headline') ?>
        <p class="<?= $hintCls ?>">One line, up to 20 words.</p>
      </div>
      <div>
        <span class="<?= $labelCls ?>">Bio <?= $req ?></span>
        <?= $editor('about', 'Add your bio here...', true, $oldRich('about')) ?>
        <?= $err('about') ?>
        <p class="<?= $hintCls ?>">About 70–100 words: your specialisation and past roles.</p>
      </div>
    </div>
  </section>

  <!-- ── availability and rate ──────────────────────── -->
  <section class="<?= $cardCls ?>">
    <div class="space-y-6">
      <div>
        <label for="availability" class="<?= $sectionCls ?> mb-3 block">Availability</label>
        <input type="text" id="availability" name="availability" maxlength="255"
               value="<?= esc(old('availability', '', false)) ?>"
               placeholder="e.g. Available from November 2026, 2 days a week" class="<?= $inputCls ?>">
        <?= $err('availability') ?>
      </div>
      <div>
        <label for="rate" class="<?= $sectionCls ?> mb-3 block">Rate</label>
        <input type="text" id="rate" name="rate" maxlength="255"
               value="<?= esc(old('rate', '', false)) ?>"
               placeholder="e.g. From S$3,000 per day" class="<?= $inputCls ?>">
        <?= $err('rate') ?>
      </div>
    </div>
  </section>

  <!-- ── services I offer ───────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Services I offer <?= $req ?></h2>
    <p class="<?= $hintCls ?>">One service per box, best fit first (up to <?= $limits['services'] ?>).</p>
    <?= $err('services') ?>

    <div id="services" class="mt-6 grid gap-5 sm:grid-cols-2">
      <button type="button" class="js-add <?= $addBoxCls ?>"><span class="<?= $addBtnCls ?>">+ Add Another Service</span></button>
    </div>

    <template id="service_template">
      <div class="js-box <?= $boxCls ?>">
        <div class="flex items-start gap-3">
          <input type="text" name="services[__i__][title]" required maxlength="255"
                 placeholder="Service Title" class="<?= $inputCls ?>">
          <button type="button" class="js-remove mt-2 text-ink/50 hover:text-brand" aria-label="Remove service">&times;</button>
        </div>
        <div class="mt-3">
          <?= $editor('services[__i__][detail]', 'Enter detail for this service') ?>
        </div>
      </div>
    </template>
  </section>

  <!-- ── relevant achievements ──────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Relevant achievements</h2>
    <p class="<?= $hintCls ?>">One outcome you personally achieved per box (up to <?= $limits['achievements'] ?>).</p>
    <?= $err('achievements') ?>

    <div id="achievements" class="mt-6 space-y-5">
      <button type="button" class="js-add <?= $addBoxCls ?> w-full"><span class="<?= $addBtnCls ?>">+ Add Another Achievement</span></button>
    </div>

    <template id="achievement_template">
      <div class="js-box <?= $boxCls ?> flex items-start gap-3">
        <div class="flex-1">
          <?= $editor('achievements[__i__]', 'Add relevant achievement here... (One achievement per box)') ?>
        </div>
        <button type="button" class="js-remove mt-2 text-ink/50 hover:text-brand" aria-label="Remove achievement">&times;</button>
      </div>
    </template>
  </section>

  <!-- ── technical proficiency ──────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Technical proficiency</h2>
    <p class="<?= $hintCls ?>">Group the tools, methods and standards you use (up to <?= $limits['skill_groups'] ?> categories,
      <?= $limits['skills'] ?> skills each). Press Enter or comma after each skill.</p>
    <?= $err('skill_groups') ?>

    <div id="skill_groups" class="mt-6 grid gap-5 sm:grid-cols-2">
      <button type="button" class="js-add <?= $addBoxCls ?>"><span class="<?= $addBtnCls ?>">+ Add Another Category</span></button>
    </div>

    <template id="skill_group_template">
      <div class="js-box <?= $boxCls ?>">
        <div class="flex items-start gap-3">
          <input type="text" name="skill_groups[__i__][name]" maxlength="100"
                 placeholder="Category (e.g. Data &amp; Analytics)" class="<?= $inputCls ?>">
          <button type="button" class="js-remove mt-2 text-ink/50 hover:text-brand" aria-label="Remove category">&times;</button>
        </div>
        <div class="mt-3 <?= $chipBoxCls ?>" data-name="skill_groups[__i__][skills][]">
          <input type="text" class="js-chip-input min-w-[8rem] flex-1 border-0 bg-transparent p-1 text-sm focus:outline-none focus:ring-0"
                 placeholder="Add skills (e.g. SAP, Python, Tableau)" maxlength="50">
        </div>
      </div>
    </template>
  </section>

  <!-- ── how I work ─────────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?> mb-3">How I work</h2>
    <?= $editor('how_i_work', 'Describe how you work, e.g. engagement types, availability, travel willingness...', false, $oldRich('how_i_work')) ?>
  </section>

  <!-- ── links ──────────────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Links</h2>
    <p class="<?= $hintCls ?>">Attach documents to each category. Each document can only be linked to one category.
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

  <!-- ── category, specialisations, search tags ─────── -->
  <!-- Last, so "Suggest tags with AI" reads everything above. -->
  <?php $suggestType = 'showcase'; require __DIR__ . '/partials/taxonomy-picker.php'; ?>

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
(function () {
  var form = document.getElementById('profile_form');
  var chipCls = 'inline-flex items-center gap-1.5 rounded-full bg-navy px-3 py-1.5 text-xs font-semibold text-white hover:bg-navy-dark transition-colors';

  // Elements with a Tailwind display class need the .hidden class, not [hidden].
  function show(el, visible) { el.classList.toggle('hidden', !visible); }

  function chip(text, onRemove) {
    var el = document.createElement('button');
    el.type = 'button';
    el.className = chipCls;
    el.textContent = text;
    el.setAttribute('aria-label', 'Remove ' + text);
    el.insertAdjacentHTML('beforeend', '<span aria-hidden="true">&times;</span>');
    el.addEventListener('click', function () { onRemove(el); });
    return el;
  }

  /* ── photo preview ─────────────────────────────── */
  var photo = document.getElementById('photo');
  photo.addEventListener('change', function () {
    var file = photo.files[0];
    var img  = document.getElementById('photo_preview');
    if (file) img.src = URL.createObjectURL(file);
    show(img, !!file);
    show(document.getElementById('photo_placeholder'), !file);
  });

  /* ── rich-text editors (Quill) ─────────────────── */
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

  /* ── chip inputs (technical skills) ────────────── */
  function initChipBox(box, values, max) {
    var input = box.querySelector('.js-chip-input');

    function add(text) {
      text = text.trim();
      if (!text || box.querySelectorAll('input[type=hidden]').length >= max) return;
      var exists = Array.prototype.some.call(box.querySelectorAll('input[type=hidden]'), function (i) {
        return i.value.toLowerCase() === text.toLowerCase();
      });
      if (exists) return;

      var el = chip(text, function (c) { c.remove(); });
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = box.dataset.name;
      hidden.value = text;
      el.appendChild(hidden);
      box.insertBefore(el, input);
    }

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        add(input.value);
        input.value = '';
      } else if (e.key === 'Backspace' && input.value === '' && input.previousElementSibling) {
        input.previousElementSibling.remove();
      }
    });
    input.addEventListener('blur', function () { add(input.value); input.value = ''; });
    (values || []).forEach(add);
  }

  /* ── repeatable boxes ──────────────────────────── */
  // Each box comes from a <template>; "__i__" becomes a unique index. At least
  // one box is always kept, and the add button hides once there are max.
  // fill(box, value) restores re-filled input.
  function repeater(containerId, templateId, values, fill, max) {
    var container = document.getElementById(containerId);
    var addBtn    = container.querySelector('.js-add');
    var tpl       = document.getElementById(templateId);
    var next      = 0;

    function count() { return container.querySelectorAll('.js-box').length; }
    function refresh() { show(addBtn, count() < max); }

    function add(value) {
      addBtn.insertAdjacentHTML('beforebegin', tpl.innerHTML.replace(/__i__/g, String(next++)));
      var box = addBtn.previousElementSibling;
      box.querySelectorAll('.js-rte').forEach(initEditor);
      fill(box, value);
      box.querySelector('.js-remove').addEventListener('click', function () {
        if (count() > 1) box.remove();
        refresh();
      });
      refresh();
      return box;
    }

    addBtn.addEventListener('click', function () {
      var box = add();
      (box.querySelector('input[type=text]') || box.querySelector('.ql-editor')).focus();
    });
    if (values.length) values.slice(0, max).forEach(add); else add();
  }

  // Re-filled rich text was sanitised server-side (RichText::clean).
  function paste(box, html) {
    if (html) box.querySelector('.js-rte').quill.clipboard.dangerouslyPasteHTML(html);
  }

  var limits = <?= $json($limits) ?>;

  repeater('credentials', 'credential_template', <?= $json($oldCredentials) ?>, function (box, c) {
    if (c) box.querySelector('input[type=text]').value = c;
  }, limits.credentials);
  repeater('services', 'service_template', <?= $json($oldServices) ?>, function (box, s) {
    if (!s) return;
    box.querySelector('input[type=text]').value = s.title;
    paste(box, s.detail);
  }, limits.services);
  repeater('achievements', 'achievement_template', <?= $json($oldAchievements) ?>, paste, limits.achievements);
  repeater('skill_groups', 'skill_group_template', <?= $json($oldSkillGroups) ?>, function (box, g) {
    if (g) box.querySelector('input[type=text]').value = g.name;
    initChipBox(box.querySelector('.js-chip-box'), g ? g.skills : [], limits.skills);
  }, limits.skill_groups);

  /* ── links: list chosen file names ─────────────── */
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

  /* ── required rich text (hidden inputs can't use `required`) ── */
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
})();
</script>

<?= $this->endSection() ?>
