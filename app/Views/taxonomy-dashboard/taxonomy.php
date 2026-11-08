<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
require __DIR__ . '/../partials/form-styles.php';

// $tree (Taxonomy::tree()) and $usage (Taxonomy::usage()) come from
// TaxonomyDashboard::taxonomy(). Clicking a name fills the edit form.
$used       = static fn (int $n): string => $n > 0 ? '<span class="ml-1 text-ink/45" title="Gigs and showcases using it">' . $n . '</span>' : '';
$primaryCls = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white hover:bg-brand-hover transition-colors';
$quietCls   = 'rounded-full border border-navy/20 px-5 py-2 text-sm font-semibold text-navy hover:border-red-600 hover:text-red-700 transition-colors';
$addForm    = static fn (string $kind): string => csrf_field() . '<input type="hidden" name="action" value="add"><input type="hidden" name="kind" value="' . $kind . '">';
?>

<?= $this->include('taxonomy-dashboard/header') ?>

<div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-8 grid gap-8 lg:grid-cols-3">

  <!-- ── the taxonomy ──────────────────────────────────── -->
  <section class="lg:col-span-2 space-y-4">
    <input type="search" placeholder="Search categories, specialisations and skills" aria-label="Search the taxonomy"
           class="js-tax-search <?= $inputCls ?>">
    <p class="<?= $hintCls ?>">Click a name to rename or remove it. Numbers count the gigs and showcases using it.</p>

    <?php foreach ($tree as $category): ?>
      <details class="js-tax-cat rounded-card border border-navy/10 bg-white" data-name="<?= esc(mb_strtolower($category['name']), 'attr') ?>">
        <summary class="flex cursor-pointer items-center justify-between gap-4 px-6 py-4">
          <span class="font-bold text-navy"><?= esc($category['name']) ?></span>
          <span class="text-xs text-ink/50"><?= count($category['specialisations']) ?> specialisation(s)</span>
        </summary>
        <div class="space-y-5 border-t border-navy/10 px-6 py-5">
          <button type="button" class="js-pick text-xs font-semibold text-navy/70 hover:text-brand"
                  data-kind="category" data-id="<?= $category['id'] ?>" data-name="<?= esc($category['name'], 'attr') ?>">
            Rename or remove this category
          </button>
          <?php foreach ($category['specialisations'] as $spec): ?>
            <div class="js-tax-spec" data-search="<?= esc(mb_strtolower($spec['name'] . ' ' . implode(' ', $spec['skills'])), 'attr') ?>">
              <button type="button" class="js-pick text-left text-sm font-semibold text-navy hover:text-brand"
                      data-kind="specialisation" data-id="<?= $spec['id'] ?>" data-name="<?= esc($spec['name'], 'attr') ?>"
                      data-where="<?= esc($category['name'], 'attr') ?>">
                <?= esc($spec['name']) ?><?= $used($usage['specialisations'][$spec['id']] ?? 0) ?>
              </button>
              <div class="mt-2 flex flex-wrap gap-1.5">
                <?php foreach ($spec['skills'] as $skillId => $skill): ?>
                  <button type="button" class="js-pick rounded-full border border-navy/15 px-3 py-1 text-xs text-ink hover:border-brand"
                          data-kind="skill" data-id="<?= $skillId ?>" data-spec="<?= $spec['id'] ?>" data-name="<?= esc($skill, 'attr') ?>"
                          data-where="<?= esc($spec['search_tag'], 'attr') ?>">
                    <?= esc($skill) ?><?= $used($usage['skills']["{$spec['id']}:{$skillId}"] ?? 0) ?>
                  </button>
                <?php endforeach; ?>
                <?php if ($spec['skills'] === []): ?><span class="text-xs text-ink/40">No skills</span><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </section>

  <aside class="space-y-6 lg:sticky lg:top-24 lg:self-start">
    <!-- ── edit the clicked item ───────────────────────── -->
    <form method="post" action="<?= url_to('taxonomy-edit') ?>" class="js-edit <?= $cardCls ?> hidden space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="kind" class="js-edit-kind">
      <input type="hidden" name="id" class="js-edit-id">
      <input type="hidden" name="specialisation_id" class="js-edit-spec">
      <div>
        <h2 class="<?= $sectionCls ?>">Edit <span class="js-edit-label"></span></h2>
        <p class="js-edit-where mt-1 text-xs text-ink/50"></p>
      </div>
      <div>
        <label class="<?= $labelCls ?>" for="edit-name">Name</label>
        <input id="edit-name" name="name" required class="js-edit-name <?= $inputCls ?>">
      </div>
      <div class="flex flex-wrap gap-3">
        <button type="submit" name="action" value="rename" class="<?= $primaryCls ?>">Rename</button>
        <button type="submit" name="action" value="remove" class="js-remove <?= $quietCls ?>" formnovalidate>Remove</button>
      </div>
      <p class="<?= $hintCls ?>">Only what no gig, showcase or pending term uses can be removed. A skill is removed from this specialisation only.</p>
    </form>

    <!-- ── add ─────────────────────────────────────────── -->
    <section class="<?= $cardCls ?> space-y-6">
      <h2 class="<?= $sectionCls ?>">Add</h2>

      <form method="post" action="<?= url_to('taxonomy-edit') ?>" class="space-y-3">
        <?= $addForm('skill') ?>
        <p class="<?= $labelCls ?>">Skill</p>
        <select name="category_id" required aria-label="Category" class="js-skill-category <?= $inputCls ?>">
          <option value="">1. Choose a category</option>
          <?php foreach ($tree as $category): ?>
            <option value="<?= $category['id'] ?>"><?= esc($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="parent_id" required disabled aria-label="Specialisation" class="js-skill-spec <?= $inputCls ?> disabled:bg-cream-25 disabled:text-ink/40">
          <option value="">2. Choose a specialisation</option>
          <?php foreach ($tree as $category): ?>
            <?php foreach ($category['specialisations'] as $spec): ?>
              <option value="<?= $spec['id'] ?>" data-category="<?= $category['id'] ?>"><?= esc($spec['name']) ?></option>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </select>
        <input name="name" required maxlength="255" placeholder="Skill name" aria-label="Skill name" class="<?= $inputCls ?>">
        <button type="submit" class="<?= $primaryCls ?>">Add skill</button>
      </form>

      <form method="post" action="<?= url_to('taxonomy-edit') ?>" class="space-y-3 border-t border-navy/10 pt-6">
        <?= $addForm('specialisation') ?>
        <label class="<?= $labelCls ?>" for="add-spec-parent">Specialisation, under</label>
        <select id="add-spec-parent" name="parent_id" required class="<?= $inputCls ?>">
          <option value="">Choose a category</option>
          <?php foreach ($tree as $category): ?>
            <option value="<?= $category['id'] ?>"><?= esc($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input name="name" required maxlength="150" placeholder="Specialisation name" aria-label="Specialisation name" class="<?= $inputCls ?>">
        <button type="submit" class="<?= $primaryCls ?>">Add specialisation</button>
      </form>

      <form method="post" action="<?= url_to('taxonomy-edit') ?>" class="space-y-3 border-t border-navy/10 pt-6">
        <?= $addForm('category') ?>
        <label class="<?= $labelCls ?>" for="add-category">Category</label>
        <input id="add-category" name="name" required maxlength="150" placeholder="Category name" class="<?= $inputCls ?>">
        <button type="submit" class="<?= $primaryCls ?>">Add category</button>
      </form>
    </section>
  </aside>
</div>

<script>
(() => {
  const edit = document.querySelector('.js-edit');
  const field = (cls) => edit.querySelector(cls);
  let picked = '';

  document.querySelectorAll('.js-pick').forEach((button) => button.addEventListener('click', () => {
    const d = button.dataset;
    picked = d.name;
    field('.js-edit-kind').value = d.kind;
    field('.js-edit-id').value = d.id;
    field('.js-edit-spec').value = d.spec || '';
    field('.js-edit-name').value = d.name;
    field('.js-edit-label').textContent = d.kind;
    field('.js-edit-where').textContent = d.where ? `${d.kind === 'skill' ? 'Under' : 'In'} ${d.where}` : '';
    edit.classList.remove('hidden');
    field('.js-edit-name').focus();
  }));
  field('.js-remove').addEventListener('click', (e) => {
    if (!confirm(`Remove "${picked}"?`)) e.preventDefault();
  });

  // Add skill: the specialisation list shows only the chosen category's.
  const skillCategory = document.querySelector('.js-skill-category');
  const skillSpec = document.querySelector('.js-skill-spec');
  // Rebuilt rather than hidden: Safari ignores hidden <option>s.
  const allSpecs = [...skillSpec.querySelectorAll('option[data-category]')];
  const showSpecs = () => {
    allSpecs.forEach((option) => option.remove());
    allSpecs.filter((option) => option.dataset.category === skillCategory.value).forEach((option) => skillSpec.append(option));
    skillSpec.value = '';
    skillSpec.disabled = skillCategory.value === '';
  };
  skillCategory.addEventListener('change', showSpecs);
  showSpecs();

  // A category stays shown when its name matches; otherwise only its matching specialisations.
  const search = document.querySelector('.js-tax-search');
  search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    document.querySelectorAll('.js-tax-cat').forEach((cat) => {
      const whole = !q || cat.dataset.name.includes(q);
      let any = whole;
      cat.querySelectorAll('.js-tax-spec').forEach((spec) => {
        spec.hidden = !whole && !spec.dataset.search.includes(q);
        any = any || !spec.hidden;
      });
      cat.hidden = !any;
      cat.open = q !== '' && any;
    });
  });
})();
</script>

<?= $this->endSection() ?>
