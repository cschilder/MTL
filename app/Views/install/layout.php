<?php
/**
 * The installer's own shell.
 *
 * Deliberately not layouts/base: that shell reads site settings, navigation
 * and a signed-in user, none of which exist yet. This one needs the
 * stylesheet and nothing else, so it cannot break on the situations the
 * installer exists to fix.
 *
 * @var MTL\Core\View $this
 * @var string $step  environment | administrator | done
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$step = (string) $this->get('step', 'environment');

$steps = [
    'environment'   => __('install.step_environment'),
    'administrator' => __('install.step_administrator'),
    'done'          => __('install.step_done'),
];

$order = array_keys($steps);
$position = (int) array_search($step, $order, true);
?>
<!doctype html>
<html lang="<?= e(MTL\Core\Translator::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e((string) $this->get('title', 'MTL')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/mtl.css')) ?>">
</head>
<body>
<header class="mtl-header">
    <span class="mtl-header__brand"><?= icon('globe', 22) ?> MTL</span>
    <span class="mtl-muted"><?= e(__('install.title')) ?></span>
</header>

<main class="mtl-shell mtl-shell--reading">
    <?php // The step rail: done, current, still to come. A plain list on
          // purpose — Vanilla's p-stepped-list forces flex-direction: column,
          // which stacked the three steps as if they were paragraphs. ?>
    <ol style="display: flex; flex-wrap: wrap; gap: var(--mtl-space-4); list-style: none; margin: var(--mtl-space-5) 0; padding: 0;">
        <?php foreach (array_values($steps) as $index => $label): ?>
            <li class="mtl-row" style="gap: var(--mtl-space-2); <?= $index === $position ? 'font-weight: 550;' : ($index > $position ? 'opacity: 0.55;' : '') ?>">
                <?php if ($index < $position): ?>
                    <?= icon('check', 16) ?>
                <?php else: ?>
                    <span><?= $index + 1 ?>.</span>
                <?php endif; ?>
                <?= e($label) ?>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php foreach (['error', 'positive'] as $kind): ?>
        <?php $message = MTL\Core\Session::flashed($kind === 'error' ? 'error' : 'message'); ?>
        <?php if (is_string($message) && $message !== ''): ?>
            <div class="p-notification--<?= $kind === 'error' ? 'negative' : 'positive' ?>">
                <div class="p-notification__content">
                    <p class="p-notification__message"><?= e($message) ?></p>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?= $this->section('content') ?>

    <p class="mtl-muted" style="margin-block-start: var(--mtl-space-7);">
        <?= e(__('install.footer')) ?>
    </p>
</main>
</body>
</html>
