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

<!-- ─────────────────────────────────────────────── header -->
<header class="sticky top-0 z-40 bg-white border-b border-navy/10">
  <nav class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex h-16 items-center justify-between gap-4">

      <a href="#" class="text-xl font-extrabold tracking-tight text-navy shrink-0">
        Sensei<span class="text-brand">Gigs</span>
      </a>

      <ul class="hidden lg:flex items-center gap-7">
        <?php foreach (['Post a Job', 'Available Jobs', 'Sell Your Skill', 'About Us', 'Blog', 'Contact Us'] as $item): ?>
          <li>
            <a href="#" class="text-sm font-medium text-ink hover:text-brand transition-colors">
              <?= esc($item) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

      <div class="hidden lg:flex items-center gap-4 shrink-0">
        <a href="#" class="text-sm font-semibold text-navy hover:text-brand transition-colors">Login</a>
        <a href="#" class="rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-hover transition-colors">
          Create Account
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
          <?php foreach (['Post a Job', 'Available Jobs', 'Sell Your Skill', 'About Us', 'Blog', 'Contact Us', 'Login'] as $item): ?>
            <a href="#" class="block rounded-lg px-3 py-2 text-sm font-medium text-ink hover:bg-cream-25 hover:text-brand">
              <?= esc($item) ?>
            </a>
          <?php endforeach; ?>
          <a href="#" class="mt-1 block rounded-full bg-brand px-3 py-2 text-center text-sm font-semibold text-white">
            Create Account
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
          'For Providers' => ['Sell Your Skill', 'Create your Profile', 'Available Jobs'],
          'For Hirers'    => ['Post a Job', 'Find an Expert', 'Browse Categories'],
          'Resources'     => ['About Us', 'Blog', 'Contact Us'],
      ];
      foreach ($columns as $heading => $links): ?>
        <div>
          <h3 class="text-xs font-bold uppercase tracking-wider text-white"><?= esc($heading) ?></h3>
          <ul class="mt-4 space-y-2.5">
            <?php foreach ($links as $link): ?>
              <li><a href="#" class="text-sm hover:text-brand transition-colors"><?= esc($link) ?></a></li>
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

</body>
</html>
