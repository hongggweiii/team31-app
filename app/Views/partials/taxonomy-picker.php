<?php
/**
 * SkillsFuture taxonomy picker (category -> specialisations -> skills, plus the
 * derived search tags), shared by the gig and showcase forms. require'd inside
 * a <form> after partials/form-styles.php.
 *
 * Expects $taxonomy (App\Libraries\Taxonomy::tree()) and the form's $err
 * closure. Posts categories[], specialisations[] and skills[<specialisation
 * id>][]. Search tags ("Specialisation (Category)") are only displayed. The
 * caps (Taxonomy::MAX_*) are enforced here and again by Taxonomy::check().
 *
 * With $suggestType ('gig' or 'showcase') set, "Suggest tags with AI" posts the
 * form as filled in so far to TagSuggestions: it ticks the taxonomy's tags and
 * lists the terms the taxonomy lacks as pending review, posting their ids as
 * proposals[] so create() links them to the saved gig / showcase.
 */
use App\Libraries\Taxonomy;

$oldCategories = array_map('strval', (array) old('categories', [], false));
$oldSpecs      = array_map('strval', (array) old('specialisations', [], false));
$oldSkills     = (array) old('skills', [], false);
$oldSkillIds   = static fn (int $specId): array => array_map('strval', (array) ($oldSkills[$specId] ?? []));

$listCardCls = 'rounded-card border border-navy/10 bg-white overflow-hidden';
$searchCls   = 'w-full border-0 border-y border-navy/10 px-6 py-3 text-sm placeholder:text-ink/35 focus:outline-none focus:ring-0';
$capCls      = 'text-xs font-normal text-ink/50';
?>
<div class="js-taxonomy space-y-6" data-max-categories="<?= Taxonomy::MAX_CATEGORIES ?>"
     data-max-specs="<?= Taxonomy::MAX_SPECIALISATIONS ?>" data-max-skills="<?= Taxonomy::MAX_SKILLS ?>"
     data-suggest-type="<?= esc($suggestType ?? '', 'attr') ?>" data-suggest-url="<?= url_to('tag-suggestions') ?>">
  <?php if (isset($suggestType)): ?>
    <section class="js-suggest rounded-card border border-dashed border-brand/50 bg-cream-25 px-6 py-5">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 class="<?= $sectionCls ?>">Not sure what to choose?</h2>
          <p class="<?= $hintCls ?>">AI ticks matching tags below from what you have written above, and suggests new ones our list doesn't have yet (marked <span class="rounded-full bg-brand/15 px-1.5 text-[10px] font-bold uppercase text-brand">AI</span>).</p>
        </div>
        <button type="button" class="js-suggest-btn shrink-0 rounded-full bg-navy px-5 py-2.5 text-sm font-semibold text-white hover:bg-navy-dark transition-colors disabled:opacity-60">
          Suggest tags with AI
        </button>
      </div>
      <p class="js-suggest-status mt-3 hidden text-sm text-ink/70" role="status"></p>
      <div class="js-suggest-pending mt-4 hidden">
        <p class="text-xs font-bold uppercase tracking-wide text-ink/50">Not in our list yet: sent for review</p>
        <p class="<?= $hintCls ?> !mt-1">An admin will review these. Once approved, they are added to your tags.</p>
        <div class="js-suggest-chips mt-2 flex flex-wrap gap-2"></div>
      </div>
    </section>
  <?php endif; ?>
  <div class="grid gap-6 sm:grid-cols-2">
    <section class="<?= $listCardCls ?>">
      <h2 class="px-6 pt-5 pb-3 <?= $sectionCls ?>">Category <?= $req ?>
        <span class="<?= $capCls ?>">up to <?= Taxonomy::MAX_CATEGORIES ?></span></h2>
      <input type="search" placeholder="Search by Category" aria-label="Search by Category" class="js-cat-search <?= $searchCls ?>">
      <div class="max-h-80 overflow-y-auto px-6 py-4">
        <?php foreach ($taxonomy as $category): ?>
          <label class="js-cat-row flex cursor-pointer items-center gap-3 py-1.5 text-sm text-ink has-[:disabled]:cursor-not-allowed has-[:disabled]:text-ink/40"
                 data-search="<?= esc(mb_strtolower($category['name']), 'attr') ?>">
            <input type="checkbox" name="categories[]" value="<?= esc((string) $category['id']) ?>" class="js-cat <?= $checkCls ?>"
                   <?= in_array((string) $category['id'], $oldCategories, true) ? 'checked' : '' ?>>
            <?= esc($category['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="<?= $listCardCls ?>">
      <h2 class="px-6 pt-5 pb-3 <?= $sectionCls ?>">Specialisations <?= $req ?>
        <span class="<?= $capCls ?>">1–<?= Taxonomy::MAX_SPECIALISATIONS ?> per category</span></h2>
      <input type="search" placeholder="Search by Specialisation" aria-label="Search by Specialisation" class="js-spec-search <?= $searchCls ?>">
      <div class="max-h-80 overflow-y-auto px-6 py-4">
        <p class="js-spec-empty text-sm text-ink/50">Tick a category to see its specialisations.</p>
        <?php foreach ($taxonomy as $category): ?>
          <?php foreach ($category['specialisations'] as $spec): ?>
            <label class="js-spec-row hidden cursor-pointer items-start gap-3 py-1.5 text-sm text-ink has-[:disabled]:cursor-not-allowed has-[:disabled]:text-ink/40"
                   data-category="<?= esc((string) $category['id']) ?>" data-search="<?= esc(mb_strtolower($spec['search_tag']), 'attr') ?>">
              <input type="checkbox" name="specialisations[]" value="<?= esc((string) $spec['id']) ?>" data-tag="<?= esc($spec['search_tag'], 'attr') ?>"
                     class="js-spec mt-0.5 <?= $checkCls ?>" <?= in_array((string) $spec['id'], $oldSpecs, true) ? 'checked' : '' ?>>
              <?= esc($spec['search_tag']) ?>
            </label>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
  <?= $err('categories') ?>
  <?= $err('specialisations') ?>

  <section class="<?= $listCardCls ?>">
    <h2 class="px-6 pt-5 pb-1 <?= $sectionCls ?>">Skills
      <span class="<?= $capCls ?>">optional, up to <?= Taxonomy::MAX_SKILLS ?> per specialisation</span></h2>
    <p class="px-6 pb-4 <?= $hintCls ?>">SkillsFuture skills linked to each specialisation you tick.</p>
    <div class="space-y-5 border-t border-navy/10 px-6 py-5">
      <p class="js-skills-empty text-sm text-ink/50">Tick a specialisation to choose its skills.</p>
      <?php foreach ($taxonomy as $category): ?>
        <?php foreach ($category['specialisations'] as $spec): ?>
          <div class="js-skill-block hidden" data-spec="<?= esc((string) $spec['id']) ?>">
            <p class="mb-2 text-sm font-semibold text-navy"><?= esc($spec['search_tag']) ?></p>
            <?php if ($spec['skills'] === []): ?>
              <p class="text-sm text-ink/50">No skills are listed for this specialisation.</p>
            <?php else: ?>
              <?php if (count($spec['skills']) > 12): ?>
                <input type="search" placeholder="Search skills" aria-label="Search skills for <?= esc($spec['search_tag'], 'attr') ?>"
                       class="js-skill-search mb-3 w-full rounded-lg border border-navy/15 px-3 py-2 text-sm placeholder:text-ink/35 focus:border-brand focus:outline-none focus:ring-0">
              <?php endif; ?>
              <div class="flex flex-wrap gap-2">
                <?php foreach ($spec['skills'] as $skillId => $skill): ?>
                  <label class="js-skill-row <?= $chipCls ?> has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-40"
                         data-search="<?= esc(mb_strtolower($skill), 'attr') ?>">
                    <input type="checkbox" name="skills[<?= esc((string) $spec['id']) ?>][]" value="<?= esc((string) $skillId) ?>" class="js-skill sr-only"
                           <?= in_array((string) $skillId, $oldSkillIds($spec['id']), true) ? 'checked' : '' ?>>
                    <?= esc($skill) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?= $err('skills') ?>

  <section class="<?= $listCardCls ?>">
    <h2 class="px-6 py-5 <?= $sectionCls ?>">Search tags</h2>
    <div class="border-t border-navy/10 bg-cream-25 px-6 py-5">
      <div class="js-tags flex flex-wrap gap-2"></div>
      <p class="js-tags-empty text-sm text-ink/50">Tags are created from the specialisations you tick.</p>
    </div>
  </section>
</div>

<script>
document.querySelectorAll('.js-taxonomy').forEach(function (root) {
  var maxCats    = +root.dataset.maxCategories;
  var maxSpecs   = +root.dataset.maxSpecs;
  var maxSkills  = +root.dataset.maxSkills;
  var cats       = Array.prototype.slice.call(root.querySelectorAll('.js-cat'));
  var specRows   = Array.prototype.slice.call(root.querySelectorAll('.js-spec-row'));
  var blocks     = Array.prototype.slice.call(root.querySelectorAll('.js-skill-block'));
  var catSearch  = root.querySelector('.js-cat-search');
  var specSearch = root.querySelector('.js-spec-search');
  var tagBox     = root.querySelector('.js-tags');

  // Elements with a Tailwind display class need the .hidden class, not [hidden].
  function show(el, visible) { el.classList.toggle('hidden', !visible); }
  function matches(row, query) { return row.dataset.search.indexOf(query.trim().toLowerCase()) !== -1; }
  function specBox(row) { return row.querySelector('input'); }
  function checked(boxes) { return boxes.filter(function (b) { return b.checked; }); }
  // At the cap, the unticked boxes are disabled; below it, all are enabled.
  function cap(boxes, max) {
    var full = checked(boxes).length >= max;
    boxes.forEach(function (b) { b.disabled = full && !b.checked; });
  }
  function filterCategories() {
    root.querySelectorAll('.js-cat-row').forEach(function (row) { show(row, matches(row, catSearch.value)); });
  }

  function renderTags() {
    tagBox.innerHTML = '';
    specRows.forEach(function (row) {
      var box = specBox(row);
      if (!box.checked) return;

      // Removing a tag unticks its specialisation.
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'inline-flex items-center gap-1.5 rounded-full bg-navy px-3 py-1.5 text-xs font-semibold text-white hover:bg-navy-dark transition-colors';
      chip.textContent = box.dataset.tag;
      chip.setAttribute('aria-label', 'Remove ' + box.dataset.tag);
      chip.insertAdjacentHTML('beforeend', '<span aria-hidden="true">&times;</span>');
      chip.addEventListener('click', function () { box.checked = false; sync(); });
      tagBox.appendChild(chip);
    });
    show(root.querySelector('.js-tags-empty'), tagBox.children.length === 0);
  }

  // Only specialisations of ticked categories are listed (and stay ticked), and
  // only skills of ticked specialisations; the caps disable further ticks.
  function sync() {
    var chosen = {};
    cats.forEach(function (c) { if (c.checked) chosen[c.value] = true; });
    cap(cats, maxCats);

    var perCategory = {};
    specRows.forEach(function (row) {
      var inCategory = !!chosen[row.dataset.category];
      var visible    = inCategory && matches(row, specSearch.value);
      if (!inCategory) specBox(row).checked = false;
      show(row, visible);
      row.classList.toggle('flex', visible);
      (perCategory[row.dataset.category] = perCategory[row.dataset.category] || []).push(specBox(row));
    });
    Object.keys(perCategory).forEach(function (c) { cap(perCategory[c], maxSpecs); });
    show(root.querySelector('.js-spec-empty'), Object.keys(chosen).length === 0);

    var anyBlock = false;
    blocks.forEach(function (block) {
      var spec  = root.querySelector('.js-spec[value="' + block.dataset.spec + '"]');
      var on    = spec.checked;
      var boxes = Array.prototype.slice.call(block.querySelectorAll('.js-skill'));
      if (!on) boxes.forEach(function (b) { b.checked = false; });
      cap(boxes, maxSkills);
      show(block, on);
      anyBlock = anyBlock || on;
    });
    show(root.querySelector('.js-skills-empty'), !anyBlock);
    renderTags();
  }

  catSearch.addEventListener('input', filterCategories);
  specSearch.addEventListener('input', sync);
  root.addEventListener('change', function (e) {
    if (e.target.matches('.js-cat, .js-spec, .js-skill')) sync();
  });
  root.querySelectorAll('.js-skill-search').forEach(function (search) {
    search.addEventListener('input', function () {
      search.closest('.js-skill-block').querySelectorAll('.js-skill-row').forEach(function (row) {
        show(row, matches(row, search.value));
      });
    });
  });
  sync();

  // "Suggest tags with AI": ticks the suggested tags (categories first, so
  // sync() lists their specialisations, then those, then their skills) and
  // lists the terms the taxonomy lacks, which the save links via proposals[].
  var suggestBtn = root.querySelector('.js-suggest-btn');
  if (suggestBtn) {
    var form       = root.closest('form');
    var status     = root.querySelector('.js-suggest-status');
    var pendingBox = root.querySelector('.js-suggest-pending');
    var chips      = root.querySelector('.js-suggest-chips');
    var say = function (text) { status.textContent = text; show(status, text !== ''); };
    var tick = function (box) { if (box && !box.disabled) box.checked = true; };

    suggestBtn.addEventListener('click', function () {
      var data = new FormData(form);
      Array.from(data.keys()).forEach(function (key) {
        if (data.get(key) instanceof File) data.delete(key); // no uploads
      });
      data.append('type', root.dataset.suggestType);
      suggestBtn.disabled = true;
      say('Reading what you have written...');

      fetch(root.dataset.suggestUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
        .then(function (res) {
          var body = res.body;
          if (body.csrf) { // the token changes after every POST: the form must send the new one
            form.querySelectorAll('input[name="' + body.csrf.name + '"]').forEach(function (i) { i.value = body.csrf.hash; });
          }
          if (!res.ok) throw new Error(body.error || 'Suggestions are unavailable right now.');

          var specs = 0;
          body.tags.forEach(function (c) { tick(root.querySelector('.js-cat[value="' + c.id + '"]')); });
          sync();
          body.tags.forEach(function (c) { c.specialisations.forEach(function (s) { tick(root.querySelector('.js-spec[value="' + s.id + '"]')); specs++; }); });
          sync();
          body.tags.forEach(function (c) { c.specialisations.forEach(function (s) {
            s.skills.forEach(function (k) { tick(root.querySelector('input[name="skills[' + s.id + '][]"][value="' + k.id + '"]')); });
          }); });
          sync();

          chips.innerHTML = '';
          body.pending.forEach(function (p) {
            var chip = document.createElement('span');
            chip.className = 'inline-flex items-center gap-1.5 rounded-full border border-dashed border-brand/60 bg-white px-3 py-1.5 text-xs text-navy';
            chip.insertAdjacentHTML('afterbegin', '<span class="rounded-full bg-brand/15 px-1.5 text-[10px] font-bold uppercase text-brand" title="Suggested by AI">AI</span>');
            chip.appendChild(document.createTextNode(p.name));
            chip.insertAdjacentHTML('beforeend', '<span class="text-ink/50">&middot; pending review</span>');
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = 'proposals[]'; input.value = p.id;
            chip.appendChild(input);
            chips.appendChild(chip);
          });
          show(pendingBox, body.pending.length > 0);
          say(specs > 0 ? 'Ticked ' + specs + ' specialisation(s) below. Untick any that don\'t fit before you submit.'
                        : 'Nothing in our list fits yet. Please choose your tags below.');
        })
        .catch(function (e) { say(e.message + ' You can still choose your tags below.'); })
        .then(function () { suggestBtn.disabled = false; });
    });
  }

  // Checkbox groups can't be `required`, so check on submit: every ticked
  // category needs at least one of its specialisations.
  root.closest('form').addEventListener('submit', function (e) {
    var bare = checked(cats).some(function (c) {
      return !specRows.some(function (r) { return r.dataset.category === c.value && specBox(r).checked; });
    });
    var missing = checked(cats).length === 0 ? catSearch : bare ? specSearch : null;
    if (missing) {
      e.preventDefault();
      missing.scrollIntoView({ behavior: 'smooth', block: 'center' });
      missing.focus();
    }
  });
});
</script>
