<?php
/**
 * The document shell every layout builds on.
 *
 * Sections a page can fill:
 *   head     extra <meta>/<link> tags
 *   body     the whole document body
 *   scripts  module tags rendered after the body
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Core\Translator;
use MTL\Services\SettingsService;

$siteTitle   = SettingsService::string('site.title', 'MTL');
$pageTitle   = $this->get('title', '');
$description = $this->get('description', SettingsService::string('site.tagline'));
$accent      = SettingsService::string('site.accent', '#0f7d5c');
$theme       = SettingsService::string('site.theme', 'auto');
$currentUser = $this->get('currentUser');

// A signed-in visitor's own preference wins over the site default.
if ($currentUser !== null) {
    $preferred = $currentUser->preference('theme');
    if (is_string($preferred) && $preferred !== '') {
        $theme = $preferred;
    }
}

$themeClass = match ($theme) {
    'light' => 'is-light',
    'dark'  => 'is-dark',
    default => 'is-auto',
};

$canonical = $this->get('canonical', '');
?>
<!doctype html>
<html lang="<?= e(Translator::locale()) ?>" class="<?= e($themeClass) ?>" data-theme="<?= e($theme) ?>" style="--mtl-accent: <?= e($accent) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

<title><?= e($pageTitle === '' ? $siteTitle : $pageTitle . ' — ' . $siteTitle) ?></title>

<?php if ($description !== ''): ?>
<meta name="description" content="<?= e(MTL\Support\Str::excerpt((string) $description, 160)) ?>">
<?php endif; ?>

<?php if ($canonical !== ''): ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<?php endif; ?>

<?php if ($this->get('noindex', false)): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>

<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="<?= e($accent) ?>">

<link rel="stylesheet" href="<?= e(asset('css/mtl.css')) ?>">

<link rel="manifest" href="<?= e(path('/manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(asset('icons/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('icons/icon-180.png')) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= e($siteTitle) ?>" href="<?= e(path('/feed.xml')) ?>">

<?php
// The fonts are the only render-blocking sub-resource; preloading the roman
// face removes the flash of fallback text on a first visit.
?>
<link rel="preload" href="<?= e(asset('vendor/vanilla/fonts/ubuntu-variable-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>

<?php // Open Graph, so a shared trip link previews with its cover photo. ?>
<meta property="og:site_name" content="<?= e($siteTitle) ?>">
<meta property="og:title" content="<?= e($pageTitle === '' ? $siteTitle : $pageTitle) ?>">
<meta property="og:description" content="<?= e(MTL\Support\Str::excerpt((string) $description, 200)) ?>">
<meta property="og:type" content="<?= e($this->get('ogType', 'website')) ?>">
<?php if ($canonical !== ''): ?>
<meta property="og:url" content="<?= e($canonical) ?>">
<?php endif; ?>
<?php if ($this->get('ogImage', '') !== ''): ?>
<meta property="og:image" content="<?= e($this->get('ogImage')) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php else: ?>
<meta name="twitter:card" content="summary">
<?php endif; ?>

<?= $this->section('head') ?>
</head>
<body class="<?= e($this->get('bodyClass', '')) ?>">

<a class="mtl-skip-link" href="#mtl-main"><?= e(__('app.skip_to_content')) ?></a>

<?= $this->section('body') ?>

<?php
/*
 * Bootstrap data for the front end. This is the single inline script in the
 * document, carrying the CSP nonce; everything else is an external module.
 *
 * Encoded with HEX_TAG so a "</script>" inside a value cannot close the block,
 * and read by the modules through window.MTL.
 */
?>
<script nonce="<?= e(nonce()) ?>">
window.MTL = <?= json_encode([
    'base'    => MTL\Core\Request::basePath(),
    'csrf'    => MTL\Core\Csrf::token(),
    'locale'  => Translator::locale(),
    'theme'   => $theme,
    'signedIn' => $currentUser !== null,
    'strings' => Translator::forJavaScript(),
    'routes'  => [
        'preview'       => path('/admin/api/preview'),
        'autosave'      => path('/admin/api/autosave'),
        'media'         => path('/admin/api/media'),
        'uploadInit'    => path('/admin/api/upload/init'),
        'uploadChunk'   => path('/admin/api/upload/chunk'),
        'uploadComplete' => path('/admin/api/upload/complete'),
        'globe'         => path('/api/globe'),
        'search'        => path('/api/search'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>

<script type="module" src="<?= e(asset('js/app.js')) ?>"></script>
<?= $this->section('scripts') ?>

</body>
</html>
