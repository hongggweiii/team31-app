<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
require __DIR__ . '/partials/form-styles.php';

$categories = [
    'Consultants (General)', 'Industry Experts', 'Accounting & Administrative',
    'Legal Assistance', 'Training & Education', 'Music, Dance, Performances',
    'Writing & Translation',
];

$languages = ['English', 'Mandarin', 'Malay', 'Tamil', 'Cantonese', 'Hindi', 'Japanese', 'Korean', 'Other'];

$locations = [
    'Virtual Only', 'Anywhere',
    'Raffles Place, Marina, Cecil', 'Anson, Tanjong Pagar', 'Tiong Bahru, Queenstown',
    'Telok Blangah, Harbourfront', 'High Street, Beach Road', 'Little India',
    'Orchard, Cairnhill, River Valley', 'Bukit Timah, Holland Road, Tanglin',
    'Novena, Thomson', 'Toa Payoh, Serangoon, Balestier',
];

$days  = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$slots = ['Morning', 'Afternoon', 'Evening'];
?>

<div class="bg-cream-25 border-b border-navy/10">
  <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-14">
    <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">For Providers</p>
    <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl">Create your Profile</h1>
    <p class="mt-4 max-w-2xl leading-relaxed text-ink/70">
      Tell Hirers what you do and how you work. Fields marked
      <span class="font-semibold text-brand">*</span> are required.
    </p>
  </div>
</div>

<form action="#" method="post" class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-12 space-y-8">

  <!-- ── personal details ───────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Personal details</h2>

    <div class="mt-6 grid gap-5 sm:grid-cols-2">
      <div>
        <label for="family_name" class="<?= $labelCls ?>">Family Name <?= $req ?></label>
        <input type="text" id="family_name" name="family_name" required class="<?= $inputCls ?>">
      </div>
      <div>
        <label for="first_name" class="<?= $labelCls ?>">First Name <?= $req ?></label>
        <input type="text" id="first_name" name="first_name" required class="<?= $inputCls ?>">
      </div>

      <div>
        <label for="mobile" class="<?= $labelCls ?>">Mobile Number <?= $req ?></label>
        <div class="flex">
          <span class="inline-flex items-center rounded-l-lg border border-r-0 border-navy/15 bg-cream-25 px-3 text-sm font-medium text-ink/60">+65</span>
          <input type="tel" id="mobile" name="mobile" required class="<?= $inputCls ?> rounded-l-none">
        </div>
      </div>
      <div>
        <label for="email" class="<?= $labelCls ?>">Email Address <?= $req ?></label>
        <input type="email" id="email" name="email" required class="<?= $inputCls ?>">
      </div>

      <div>
        <label for="gender" class="<?= $labelCls ?>">Gender <?= $req ?></label>
        <select id="gender" name="gender" required class="<?= $inputCls ?>">
          <option value="">Select gender</option>
          <?php foreach (['Male', 'Female', 'Rather not say'] as $g): ?>
            <option value="<?= esc($g) ?>"><?= esc($g) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="birth_month" class="<?= $labelCls ?>">Year of Birth and Month <?= $req ?></label>
        <input type="month" id="birth_month" name="birth_month" required class="<?= $inputCls ?>">
      </div>

      <div class="sm:col-span-2">
        <span class="<?= $labelCls ?>">Preferred Mode of Contact <?= $req ?></span>
        <div class="flex flex-wrap gap-3">
          <?php foreach (['Email', 'WhatsApp', 'Any'] as $mode): ?>
            <label class="<?= $chipCls ?>">
              <input type="checkbox" name="contact_mode[]" value="<?= esc($mode) ?>" class="<?= $checkCls ?>">
              <?= esc($mode) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="sm:col-span-2">
        <label for="address" class="<?= $labelCls ?>">Residential Address</label>
        <input type="text" id="address" name="address" class="<?= $inputCls ?>">
      </div>

      <div>
        <label for="postcode" class="<?= $labelCls ?>">Postcode <?= $req ?></label>
        <input type="text" id="postcode" name="postcode" required inputmode="numeric" class="<?= $inputCls ?>">
      </div>
      <div>
        <label for="nric" class="<?= $labelCls ?>">NRIC/FIN Number</label>
        <input type="text" id="nric" name="nric" class="<?= $inputCls ?>">
      </div>

      <div class="sm:col-span-2">
        <span class="<?= $labelCls ?>">Residence Status <?= $req ?></span>
        <div class="flex flex-wrap gap-3">
          <?php foreach (['Citizen', 'PR'] as $status): ?>
            <label class="<?= $chipCls ?>">
              <input type="radio" name="residence_status" value="<?= esc($status) ?>" required class="<?= $radioCls ?>">
              <?= esc($status) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <label for="language" class="<?= $labelCls ?>">First (main) Language <?= $req ?></label>
        <select id="language" name="language" required class="<?= $inputCls ?>">
          <option value="">Select language</option>
          <?php foreach ($languages as $lang): ?>
            <option value="<?= esc($lang) ?>"><?= esc($lang) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="other_languages" class="<?= $labelCls ?>">Other Language(s) <?= $req ?></label>
        <input type="text" id="other_languages" name="other_languages" required
               placeholder="Separate with commas" class="<?= $inputCls ?>">
      </div>
    </div>
  </section>

  <!-- ── photo ──────────────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Your photo</h2>
    <div class="mt-6">
      <label for="photo" class="<?= $labelCls ?>">Upload Your Photo <?= $req ?></label>
      <input type="file" id="photo" name="photo" accept="image/*" required
             class="<?= $inputCls ?> file:mr-4 file:rounded-full file:border-0 file:bg-cream-50 file:px-4 file:py-1.5 file:text-sm file:font-semibold file:text-navy">
      <p class="<?= $hintCls ?>">Half length profile picture. Minimum resolution 325px, maximum file size 4MB.</p>
    </div>
  </section>

  <!-- ── service ────────────────────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Your service</h2>

    <div class="mt-6 grid gap-5 sm:grid-cols-2">
      <div class="sm:col-span-2">
        <label for="service_category" class="<?= $labelCls ?>">Select a Category for your service <?= $req ?></label>
        <select id="service_category" name="service_category" required class="<?= $inputCls ?>">
          <option value="">Please select a category</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= esc($cat) ?>"><?= esc($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="skill" class="<?= $labelCls ?>">Identify your skill for the selected category <?= $req ?></label>
        <input type="text" id="skill" name="skill" required class="<?= $inputCls ?>">
      </div>
      <div>
        <label for="specialisation" class="<?= $labelCls ?>">Area of specialisation for that skill <?= $req ?></label>
        <input type="text" id="specialisation" name="specialisation" required class="<?= $inputCls ?>">
      </div>

      <div>
        <label for="years" class="<?= $labelCls ?>">Years of Experience <?= $req ?></label>
        <input type="number" id="years" name="years" min="0" max="70" required class="<?= $inputCls ?>">
      </div>

      <?php foreach ([
          ['about', 'About Myself', 'A short introduction Hirers will read first.'],
          ['services_offered', 'What services I offer?', 'The concrete deliverables you take on.'],
          ['how_i_work', 'How I work?', 'Your process, availability style, and what clients can expect.'],
      ] as [$name, $label, $hint]): ?>
        <div class="sm:col-span-2">
          <label for="<?= $name ?>" class="<?= $labelCls ?>"><?= esc($label) ?> <?= $req ?></label>
          <textarea id="<?= $name ?>" name="<?= $name ?>" rows="4" required class="<?= $inputCls ?>"></textarea>
          <p class="<?= $hintCls ?>"><?= esc($hint) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ── availability and rate ──────────────────────── -->
  <section class="<?= $cardCls ?>">
    <h2 class="<?= $sectionCls ?>">Availability &amp; rate</h2>

    <div class="mt-6 space-y-6">
      <div>
        <span class="<?= $labelCls ?>">Your preferred location of work <?= $req ?></span>
        <div class="flex flex-wrap gap-2.5">
          <?php foreach ($locations as $loc): ?>
            <label class="<?= $chipCls ?>">
              <input type="checkbox" name="locations[]" value="<?= esc($loc) ?>" class="<?= $checkCls ?>">
              <?= esc($loc) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <label for="rate" class="<?= $labelCls ?>">What will you charge for your service <?= $req ?></label>
        <div class="flex flex-wrap gap-3 sm:flex-nowrap">
          <div class="flex flex-1 min-w-[10rem]">
            <span class="inline-flex items-center rounded-l-lg border border-r-0 border-navy/15 bg-cream-25 px-3 text-sm font-medium text-ink/60">S$</span>
            <input type="number" id="rate" name="rate" min="0" step="0.01" required class="<?= $inputCls ?> rounded-l-none">
          </div>
          <select name="rate_unit" required class="<?= $inputCls ?> sm:w-44">
            <?php foreach (['per Hour', 'per Day', 'per Week', 'per Month', 'per Project', 'per Piece'] as $unit): ?>
              <option value="<?= esc($unit) ?>"><?= esc($unit) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div>
        <span class="<?= $labelCls ?>">Available On <?= $req ?></span>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[32rem] border-separate border-spacing-1 text-sm">
            <thead>
              <tr>
                <th class="w-20"><span class="sr-only">Day</span></th>
                <?php foreach ($slots as $slot): ?>
                  <th class="pb-1 text-xs font-semibold uppercase tracking-wide text-ink/50"><?= esc($slot) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($days as $day): ?>
                <tr>
                  <th scope="row" class="pr-2 text-left text-sm font-semibold text-navy"><?= esc($day) ?></th>
                  <?php foreach ($slots as $slot): ?>
                    <td>
                      <label class="flex cursor-pointer items-center justify-center rounded-lg border border-navy/15 py-2 hover:border-brand transition has-[:checked]:border-brand has-[:checked]:bg-cream-50">
                        <input type="checkbox" name="availability[]"
                               value="<?= esc($day . '-' . $slot) ?>" class="<?= $checkCls ?>">
                        <span class="sr-only"><?= esc("$day $slot") ?></span>
                      </label>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <!-- ── submit ─────────────────────────────────────── -->
  <div class="<?= $cardCls ?>">
    <label class="flex items-start gap-3 text-sm text-ink/75">
      <input type="checkbox" name="terms" required class="<?= $checkCls ?> mt-0.5">
      <span>By submitting this information, you agree to our
        <a href="#" class="font-semibold text-brand hover:underline">Terms of Service</a>.</span>
    </label>

    <button type="submit"
            class="mt-6 w-full rounded-full bg-brand px-8 py-3.5 text-sm font-semibold text-white hover:bg-brand-hover transition-colors sm:w-auto">
      Submit Profile
    </button>
  </div>

</form>

<?= $this->endSection() ?>
