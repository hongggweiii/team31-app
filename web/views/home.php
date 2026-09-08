<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
$categories = [
    'Asset Management', 'Corporate & Administrative', 'Financial', 'Human Resources',
    'Industrial & Consumer Design', 'Information Technology', 'Logistics & Procurement',
    'Marketing & Communication', 'Miscellaneous', 'Strategy',
];

$values = [
    ['Hirers come here seeking experience', 'The marketplace Hirers know as the place to find deep experience. Our providers have honed their skills over a lifetime of practice.'],
    ['Choice & quick matches', 'We are tapping the fastest growing talent pool in Singapore, with a skills matching engine that simplifies how Hirers find the right Provider.'],
    ['Focus on security', 'Every job is secured with contract protection, insurance coverage, payment assurance and a rating system you can rely on.'],
];

$services = [
    ['Expert Advisers', 'After long careers, our experts offer industry expertise, development advice, take board seats, or mentor. Seek their help for that leg-up.', 'bg-cream-50'],
    ['Skilled Consultants', 'Architects, engineers, lawyers, finance professionals. Get expert help to manage your next improvement or development project.', 'bg-sky-50'],
    ['Other Professionals', 'Administrators, accountants, part-time counsel or interim CFO. Hire experienced professionals part time and save.', 'bg-cream-100'],
    ['Entertainers & Artists', 'Celebrating? Hire DJs, musicians, magicians, comics and mimics for celebrations and club gigs.', 'bg-sky-100'],
];

$faqs = [
    ['Is the SenseiGigs app fully functional?', 'Not yet. Hirers can register and post a job, and we will list it on the Available Jobs page. Providers can register and create a profile. Until the matching engine is live, we match requirements manually.'],
    ['What services can you offer currently?', 'Expert Advisers, Skilled Consultants, Other Professionals, and Entertainers & Artists — across ten categories, with more opening as Providers join.'],
    ['How much does it cost?', 'Providers pay 2% on completed gigs and Hirers pay 5%. There is no cost to create a profile or post a job.'],
];
?>

<!-- ─────────────────────────────────────────────── hero -->
<section class="relative overflow-hidden bg-cream-25">
  <div aria-hidden="true" class="pointer-events-none absolute -left-32 -top-28 h-96 w-96 rounded-full bg-cream-100 opacity-60"></div>

  <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20 lg:py-28">
    <div class="grid items-center gap-14 lg:grid-cols-12">

      <div class="lg:col-span-7">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">Where experience lives</p>
        <h1 class="mt-4 text-4xl font-extrabold leading-[1.1] tracking-tight text-navy sm:text-5xl lg:text-6xl">
          The marketplace connecting hirers to white-collar experts
        </h1>
        <p class="mt-5 max-w-xl text-lg leading-relaxed text-ink/70">
          Get project work done — fast, flexibly, and on terms that suit both sides.
          Access specialist expertise without the cost of a permanent hire.
        </p>

        <div class="mt-9 flex flex-wrap gap-4">
          <a href="#categories" class="rounded-full bg-brand px-7 py-3.5 text-sm font-semibold text-white hover:bg-brand-hover transition-colors">
            Find an Expert
          </a>
          <a href="#" class="rounded-full border-2 border-navy px-7 py-3.5 text-sm font-semibold text-navy hover:bg-navy hover:text-white transition-colors">
            Post a Job
          </a>
        </div>

        <dl class="mt-12 flex flex-wrap gap-10 border-t border-navy/15 pt-7">
          <?php foreach ([['593', 'Specialities'], ['109', 'Categories'], ['5/5', 'Average rating']] as [$num, $label]): ?>
            <div>
              <dt class="text-2xl font-extrabold text-navy"><?= esc($num) ?></dt>
              <dd class="mt-1 text-sm text-ink/60"><?= esc($label) ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </div>

      <div class="lg:col-span-5">
        <div class="rounded-card border border-navy/10 bg-white p-8 shadow-sm">
          <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">Matched in seconds</p>
          <h2 class="mt-3 text-xl font-bold text-navy">Interim CFO, pre-Series B</h2>
          <p class="mt-3 text-sm leading-relaxed text-ink/70">
            Cap table modelling, term sheet review, monthly board reporting and cash
            runway forecasting — six months, starting immediately.
          </p>
          <div class="mt-5 flex flex-wrap gap-2">
            <?php foreach (['Financial', 'Corporate Finance', 'Strategy'] as $tag): ?>
              <span class="rounded-full bg-cream-50 px-3 py-1.5 text-xs font-semibold text-navy"><?= esc($tag) ?></span>
            <?php endforeach; ?>
          </div>
          <a href="#" class="mt-6 inline-block rounded-full bg-navy px-6 py-3 text-sm font-semibold text-white hover:bg-navy-dark transition-colors">
            View 6 matched Providers
          </a>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ─────────────────────────────────────────────── testimonials -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
  <div class="mx-auto max-w-2xl text-center">
    <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">Our Providers</p>
    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl">
      Solve problems, get work done
    </h2>
  </div>

  <div class="mt-12 grid gap-6 md:grid-cols-2">
    <?php
    $quotes = [
        ['J', 'Jane, 55', 'Career Consultant', 'bg-cream-100',
         'I operate throughout the region in a holistic career-mentoring role. I don\'t only help you decide what is best and next for your career, but also what helps make you a balanced person, able to take on whatever comes next.'],
        ['A', 'Adrian, 60', 'Infrastructure Consultant, Sommelier', 'bg-sky-100',
         'Having developed large scale manufacturing and logistics facilities, I will prepare your company to design, cost, finance and develop its infrastructure and transport facilities. We can discuss and sip wine after work.'],
    ];
    foreach ($quotes as [$initial, $name, $role, $tint, $quote]): ?>
      <figure class="rounded-card border border-navy/10 bg-white p-8">
        <blockquote class="text-base leading-relaxed text-ink/80">&ldquo;<?= esc($quote) ?>&rdquo;</blockquote>
        <figcaption class="mt-6 flex items-center gap-4">
          <span class="grid h-12 w-12 place-items-center rounded-full <?= $tint ?> font-bold text-navy" aria-hidden="true">
            <?= esc($initial) ?>
          </span>
          <span>
            <span class="block text-sm font-bold text-navy"><?= esc($name) ?></span>
            <span class="block text-sm text-ink/60"><?= esc($role) ?></span>
          </span>
        </figcaption>
      </figure>
    <?php endforeach; ?>
  </div>
</section>

<!-- ─────────────────────────────────────────────── categories -->
<section id="categories" class="bg-cream-25 py-20">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-6">
      <div>
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">Browse</p>
        <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl">
          Browse experienced Providers by category
        </h2>
        <p class="mt-3 text-ink/60">Consultants (General)</p>
      </div>
      <span class="inline-flex items-center gap-2 rounded-full bg-navy px-4 py-2 text-sm font-semibold text-white">
        <span class="text-brand" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
        5/5 average rating
      </span>
    </div>

    <ul class="mt-10 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
      <?php foreach ($categories as $category): ?>
        <li>
          <a href="#" class="flex h-full items-center justify-center rounded-card border border-navy/10 bg-white p-5 text-center text-sm font-semibold text-navy hover:border-brand hover:text-brand transition-colors">
            <?= esc($category) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="mt-10 text-center">
      <a href="#" class="rounded-full border-2 border-navy px-7 py-3.5 text-sm font-semibold text-navy hover:bg-navy hover:text-white transition-colors">
        View More Categories
      </a>
    </div>
  </div>
</section>

<!-- ─────────────────────────────────────────────── for providers -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
  <div class="flex flex-wrap items-center justify-between gap-8 rounded-card bg-navy p-10 lg:p-14">
    <div class="max-w-2xl">
      <p class="text-xs font-bold uppercase tracking-[0.2em] text-cream-100">For Providers</p>
      <h2 class="mt-3 text-2xl font-extrabold text-white sm:text-3xl">
        Offer your skill and experience as services to Hirers
      </h2>
      <p class="mt-4 leading-relaxed text-white/75">
        Create up to five showcase profiles describing your expertise. Get noticed by
        Hirers looking for exactly what you have spent a career learning — and pay
        nothing until a gig completes.
      </p>
    </div>
    <a href="#" class="rounded-full bg-brand px-7 py-3.5 text-sm font-semibold text-white hover:bg-brand-hover transition-colors">
      Create your Profile
    </a>
  </div>
</section>

<!-- ─────────────────────────────────────────────── why -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
  <div class="mx-auto max-w-2xl text-center">
    <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">The difference</p>
    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl">Why SenseiGigs?</h2>
  </div>

  <div class="mt-12 grid gap-6 md:grid-cols-3">
    <?php foreach ($values as [$heading, $body]): ?>
      <div class="rounded-card border border-navy/10 bg-white p-8">
        <h3 class="text-lg font-bold text-navy"><?= esc($heading) ?></h3>
        <p class="mt-3 text-sm leading-relaxed text-ink/70"><?= esc($body) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ─────────────────────────────────────────────── services -->
<section class="bg-cream-25 py-20">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-6">
      <div>
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">What you can hire</p>
        <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl">Services</h2>
      </div>
      <a href="#" class="rounded-full border-2 border-navy px-7 py-3.5 text-sm font-semibold text-navy hover:bg-navy hover:text-white transition-colors">
        Explore All
      </a>
    </div>

    <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
      <?php foreach ($services as [$title, $body, $tint]): ?>
        <div class="rounded-card <?= $tint ?> p-8">
          <h3 class="text-lg font-bold text-navy"><?= esc($title) ?></h3>
          <p class="mt-3 text-sm leading-relaxed text-ink/70"><?= esc($body) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ─────────────────────────────────────────────── faq -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
  <div class="grid gap-12 lg:grid-cols-12">

    <div class="lg:col-span-4">
      <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand">Asked</p>
      <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy sm:text-4xl">Most commonly</h2>
      <p class="mt-4 leading-relaxed text-ink/60">
        Everything Hirers and Providers ask us before getting started.
      </p>
      <a href="#" class="mt-6 inline-block rounded-full bg-brand px-7 py-3.5 text-sm font-semibold text-white hover:bg-brand-hover transition-colors">
        Read all FAQs
      </a>
    </div>

    <div class="lg:col-span-8 space-y-3">
      <?php foreach ($faqs as [$question, $answer]): ?>
        <details class="group rounded-card border border-navy/10 bg-white">
          <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 text-base font-semibold text-navy">
            <?= esc($question) ?>
            <svg class="h-5 w-5 shrink-0 text-brand transition-transform group-open:rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M12 5v14M5 12h14"/>
            </svg>
          </summary>
          <p class="px-5 pb-5 text-sm leading-relaxed text-ink/70"><?= esc($answer) ?></p>
        </details>
      <?php endforeach; ?>
    </div>

  </div>
</section>

<?= $this->endSection() ?>
