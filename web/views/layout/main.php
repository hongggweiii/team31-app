<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title ?? 'Team 31') ?></title>

<!--
  Tailwind Play CDN — no build step, no npm.
  Swap for a compiled build (Tailwind CLI or PostCSS) before this ships.
-->
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="antialiased text-slate-800">

<?= $this->renderSection('content') ?>

</body>
</html>
