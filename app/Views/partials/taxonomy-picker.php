<?php
/**
 * Category -> Specialisations -> Search tags picker, shared by the gig and
 * showcase forms. require'd inside a <form> after partials/form-styles.php.
 *
 * Expects $categoryGroups ([service => [id => name]]), $specialisations
 * ([category_id => [id => name]]) and the form's $err closure. Posts
 * categories[] and specialisations[]. Search tags ("Specialisation (Category)")
 * are only displayed; they are derived from the ticked specialisations.
 */
$categoryNames = array_replace([], ...array_values($categoryGroups));
$oldCategories = array_map('strval', (array) old('categories', [], false));
$oldSpecs      = array_map('strval', (array) old('specialisations', [], false));

$listCardCls = 'rounded-card border border-navy/10 bg-white overflow-hidden';
$searchCls   = 'w-full border-0 border-y border-navy/10 px-6 py-3 text-sm placeholder:text-ink/35 focus:outline-none focus:ring-0';
?>
<div class="js-taxonomy space-y-6">
  <div class="grid gap-6 sm:grid-cols-2">
    <section class="<?= $listCardCls ?>">
      <h2 class="px-6 pt-5 pb-3 <?= $sectionCls ?>">Category <?= $req ?></h2>
      <input type="search" placeholder="Search by Category" aria-label="Search by Category" class="js-cat-search <?= $searchCls ?>">
      <div class="max-h-80 space-y-5 overflow-y-auto px-6 py-4">
        <?php foreach ($categoryGroups as $service => $cats): ?>
          <div class="js-cat-group">
            <p class="mb-1.5 text-sm font-bold text-navy"><?= esc($service) ?></p>
            <?php foreach ($cats as $id => $name): ?>
              <label class="js-cat-row flex cursor-pointer items-center gap-3 py-1.5 text-sm text-ink" data-search="<?= esc(mb_strtolower($name), 'attr') ?>">
                <input type="checkbox" name="categories[]" value="<?= esc($id) ?>" class="js-cat <?= $checkCls ?>"
                       <?= in_array((string) $id, $oldCategories, true) ? 'checked' : '' ?>>
                <?= esc($name) ?>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="<?= $listCardCls ?>">
      <h2 class="px-6 pt-5 pb-3 <?= $sectionCls ?>">Specialisations <?= $req ?></h2>
      <input type="search" placeholder="Search by Specialisation" aria-label="Search by Specialisation" class="js-spec-search <?= $searchCls ?>">
      <div class="max-h-80 overflow-y-auto px-6 py-4">
        <p class="js-spec-empty text-sm text-ink/50">Tick a category to see its specialisations.</p>
        <?php foreach ($specialisations as $categoryId => $specs): ?>
          <?php if (! isset($categoryNames[$categoryId])) continue; // parent category is inactive ?>
          <?php foreach ($specs as $id => $name): ?>
            <?php $tag = $name . ' (' . $categoryNames[$categoryId] . ')'; ?>
            <label class="js-spec-row hidden cursor-pointer items-start gap-3 py-1.5 text-sm text-ink"
                   data-category="<?= esc($categoryId) ?>" data-search="<?= esc(mb_strtolower($tag), 'attr') ?>">
              <input type="checkbox" name="specialisations[]" value="<?= esc($id) ?>" data-tag="<?= esc($tag, 'attr') ?>"
                     class="mt-0.5 <?= $checkCls ?>" <?= in_array((string) $id, $oldSpecs, true) ? 'checked' : '' ?>>
              <?= esc($tag) ?>
            </label>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
  <?= $err('categories') ?>
  <?= $err('specialisations') ?>

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
  var cats       = Array.prototype.slice.call(root.querySelectorAll('.js-cat'));
  var specRows   = Array.prototype.slice.call(root.querySelectorAll('.js-spec-row'));
  var catSearch  = root.querySelector('.js-cat-search');
  var specSearch = root.querySelector('.js-spec-search');
  var tagBox     = root.querySelector('.js-tags');

  // Elements with a Tailwind display class need the .hidden class, not [hidden].
  function show(el, visible) { el.classList.toggle('hidden', !visible); }
  function matches(row, query) { return row.dataset.search.indexOf(query.trim().toLowerCase()) !== -1; }
  function specBox(row) { return row.querySelector('input'); }

  function filterCategories() {
    root.querySelectorAll('.js-cat-group').forEach(function (group) {
      var any = false;
      group.querySelectorAll('.js-cat-row').forEach(function (row) {
        var ok = matches(row, catSearch.value);
        show(row, ok);
        any = any || ok;
      });
      show(group, any);
    });
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
      chip.addEventListener('click', function () { box.checked = false; renderTags(); });
      tagBox.appendChild(chip);
    });
    show(root.querySelector('.js-tags-empty'), tagBox.children.length === 0);
  }

  // Only specialisations of ticked categories are listed (and stay ticked).
  function syncSpecialisations() {
    var chosen = {};
    cats.forEach(function (c) { if (c.checked) chosen[c.value] = true; });

    specRows.forEach(function (row) {
      var inCategory = !!chosen[row.dataset.category];
      var visible    = inCategory && matches(row, specSearch.value);
      if (!inCategory) specBox(row).checked = false;
      show(row, visible);
      row.classList.toggle('flex', visible);
    });
    show(root.querySelector('.js-spec-empty'), Object.keys(chosen).length === 0);
    renderTags();
  }

  catSearch.addEventListener('input', filterCategories);
  specSearch.addEventListener('input', syncSpecialisations);
  cats.forEach(function (c) { c.addEventListener('change', syncSpecialisations); });
  specRows.forEach(function (row) { specBox(row).addEventListener('change', renderTags); });
  syncSpecialisations();

  // Checkbox groups can't be `required`, so check on submit.
  root.closest('form').addEventListener('submit', function (e) {
    var missing = !cats.some(function (c) { return c.checked; }) ? catSearch
      : !specRows.some(function (r) { return specBox(r).checked; }) ? specSearch
      : null;
    if (missing) {
      e.preventDefault();
      missing.scrollIntoView({ behavior: 'smooth', block: 'center' });
      missing.focus();
    }
  });
});
</script>
