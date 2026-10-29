<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title ?? 'SenseiGigs — Where Experience Lives') ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<!--
  Tailwind Play CDN — compiles in the browser, no build step.
  Swap for a compiled build before shipping.
-->
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          brand:  { DEFAULT: '#FF8200', hover: '#EE7900' },
          navy:   { DEFAULT: '#184F7E', dark: '#123D62' },
          ink:    '#333333',
          cream:  { 25: '#FCF6EF', 50: '#FCF1E5', 100: '#F5E4D1' },
          sky:    { 50: '#D5E3EE', 100: '#C9DAED' },
        },
        fontFamily: { sans: ['Heebo', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
        borderRadius: { card: '20px' },
      },
    },
  };
</script>
</head>
<body class="font-sans text-ink antialiased">

<?php
/**
 * Site navigation. Value is the route to link to, or null while the page
 * does not exist yet (rendered as a "#" placeholder).
 */
$navLinks = [
    'Post a Job'      => 'create-gig',
    'Available Jobs'  => null,
    'Sell Your Skill' => 'create-profile',
    'About Us'        => null,
    'Blog'            => null,
    'Contact Us'      => null,
];
$href = static fn (?string $route): string => $route === null ? '#' : base_url($route);

// The user's email lives in auth_identities: kept in the session (for that
// user) so every page doesn't spend a query on the navbar.
$email = static function (): string {
    $saved = session('navbar_email');
    if (! is_array($saved) || $saved['user'] !== auth()->id()) {
        $saved = ['user' => auth()->id(), 'email' => (string) auth()->user()->email];
        session()->set('navbar_email', $saved);
    }

    return $saved['email'];
};

// Shield auth routes: [label, route] for the secondary link and the primary button.
[$authLink, $authButton] = auth()->loggedIn()
    ? [[$email(), null], ['Logout', 'logout']]
    : [['Login', 'login'], ['Create Account', 'register']];
?>

<!-- ─────────────────────────────────────────────── header -->
<header class="sticky top-0 z-40 bg-white border-b border-navy/10">
  <nav class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex h-16 items-center justify-between gap-4">

      <a href="<?= base_url() ?>" class="text-xl font-extrabold tracking-tight text-navy shrink-0">
        Sensei<span class="text-brand">Gigs</span>
      </a>

      <ul class="hidden lg:flex items-center gap-7">
        <?php foreach ($navLinks as $item => $route): ?>
          <li>
            <a href="<?= $href($route) ?>" class="text-sm font-medium text-ink hover:text-brand transition-colors">
              <?= esc($item) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

      <div class="hidden lg:flex items-center gap-4 shrink-0">
        <a href="<?= $href($authLink[1]) ?>" class="text-sm font-semibold text-navy hover:text-brand transition-colors"><?= esc($authLink[0]) ?></a>
        <a href="<?= $href($authButton[1]) ?>" class="rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-hover transition-colors">
          <?= esc($authButton[0]) ?>
        </a>
      </div>

      <!-- mobile menu: <details> keeps this zero-JS -->
      <details class="lg:hidden relative group">
        <summary class="list-none cursor-pointer p-2 -mr-2" aria-label="Menu">
          <svg class="w-6 h-6 text-navy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M4 7h16M4 12h16M4 17h16"/>
          </svg>
        </summary>
        <div class="absolute right-0 mt-2 w-56 rounded-card border border-navy/10 bg-white p-2 shadow-lg">
          <?php foreach ($navLinks + [$authLink[0] => $authLink[1]] as $item => $route): ?>
            <a href="<?= $href($route) ?>" class="block rounded-lg px-3 py-2 text-sm font-medium text-ink hover:bg-cream-25 hover:text-brand">
              <?= esc($item) ?>
            </a>
          <?php endforeach; ?>
          <a href="<?= $href($authButton[1]) ?>" class="mt-1 block rounded-full bg-brand px-3 py-2 text-center text-sm font-semibold text-white">
            <?= esc($authButton[0]) ?>
          </a>
        </div>
      </details>

    </div>
  </nav>
</header>

<?= $this->renderSection('content') ?>

<!-- ─────────────────────────────────────────────── footer -->
<footer class="bg-navy text-white/75">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-14">
    <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-5">

      <div class="lg:col-span-2">
        <div class="text-xl font-extrabold text-white">Sensei<span class="text-brand">Gigs</span></div>
        <p class="mt-3 max-w-xs text-sm leading-relaxed">
          The marketplace connecting hirers to white-collar experts — a Singapore
          social venture tapping the country's fastest growing talent pool.
        </p>
      </div>

      <?php
      $columns = [
          'For Providers' => [
              'Sell Your Skill'     => 'create-profile',
              'Create your Profile' => 'create-profile',
              'Available Jobs'      => null,
          ],
          'For Hirers' => [
              'Post a Job'        => 'create-gig',
              'Find an Expert'    => null,
              'Browse Categories' => null,
          ],
          'Resources' => [
              'About Us'   => null,
              'Blog'       => null,
              'Contact Us' => null,
          ],
      ];
      foreach ($columns as $heading => $links): ?>
        <div>
          <h3 class="text-xs font-bold uppercase tracking-wider text-white"><?= esc($heading) ?></h3>
          <ul class="mt-4 space-y-2.5">
            <?php foreach ($links as $link => $route): ?>
              <li><a href="<?= $href($route) ?>" class="text-sm hover:text-brand transition-colors"><?= esc($link) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>

    </div>

    <div class="mt-12 border-t border-white/15 pt-6 flex flex-wrap justify-between gap-3 text-sm text-white/60">
      <p>&copy; <?= date('Y') ?> SenseiGigs Pte Ltd. A Singapore Social Venture.</p>
      <p>POLLINATE, 71 Ayer Rajah Crescent, #04-01 Singapore 139951</p>
    </div>
  </div>
</footer>

<!-- ─────────────────────────────────── form loading overlay -->
<!--
  A <form data-loading="message"> that is submitted (and passes validation)
  disables its submit button, which shows a spinner, and puts this overlay up
  with the message until the next page loads. A second submit is ignored, so a
  slow request can't be sent twice.
-->
<div id="form-loading" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-navy/40 px-4 backdrop-blur-sm"
     role="status" aria-live="polite">
  <div class="flex max-w-sm items-center gap-4 rounded-card bg-white px-7 py-6 shadow-lg">
    <svg class="h-7 w-7 shrink-0 animate-spin text-brand" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle>
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
    </svg>
    <div>
      <p class="js-form-loading-message font-semibold text-navy"></p>
      <p class="mt-1 text-sm text-ink/60">This can take a few seconds. Please don't refresh or close the page.</p>
    </div>
  </div>
</div>

<script>
(function () {
  var overlay = document.getElementById('form-loading');
  var spinner = '<svg class="mr-2 inline h-4 w-4 animate-spin align-[-2px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    + '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle>'
    + '<path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path></svg>';

  // Bubbling, so it runs after a page's own submit checks: a submit they cancel
  // (e.g. an empty required rich-text field) shows nothing.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches('form[data-loading]') || e.defaultPrevented) {
      return;
    }
    if (form.dataset.submitting) {
      e.preventDefault();
      return;
    }
    form.dataset.submitting = '1';

    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
      button.dataset.label = button.innerHTML;
      button.innerHTML = spinner + 'Submitting…';
      button.disabled = true;
      button.classList.add('cursor-wait', 'opacity-80');
    });
    overlay.querySelector('.js-form-loading-message').textContent = form.dataset.loading;
    overlay.classList.remove('hidden');
  });

  // Coming back with the Back button can restore the page as it was left,
  // mid-submit: put the form back to normal.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) {
      return;
    }
    overlay.classList.add('hidden');
    document.querySelectorAll('form[data-submitting]').forEach(function (form) {
      delete form.dataset.submitting;
      form.querySelectorAll('button[type="submit"]').forEach(function (button) {
        button.innerHTML = button.dataset.label;
        button.disabled = false;
        button.classList.remove('cursor-wait', 'opacity-80');
      });
    });
  });
})();
</script>

</body>
</html>
