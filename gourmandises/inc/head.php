<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#2F2E4E">
<title><?= e(($title ?? '') ? $title . ' · ' : '') ?><?= e(cfg('app_name')) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset('assets/img/favicon.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('assets/img/logo-afpar.png')) ?>">
<link rel="manifest" href="<?= e(asset('manifest.webmanifest')) ?>">
<link rel="preload" href="<?= e(asset('assets/fonts/nunito.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('assets/css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
