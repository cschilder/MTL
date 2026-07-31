<?php
/**
 * Public site layout: header, content, footer.
 *
 * Pages that want the globe to fill the viewport pass immersive = true, which
 * removes the footer and lets the shell run edge to edge.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$immersive = (bool) $this->get('immersive', false);

// The globe page needs the header to float over the sphere. That is a
// body-level state rather than a shell-level one, because the header is the
// shell's sibling.
$this->layout('layouts/base', [
    'bodyClass' => trim((string) $this->get('bodyClass', '') . ($immersive ? ' mtl-page--immersive' : '')),
]);
?>
<?php $this->start('body') ?>

<?= $this->include('partials/header') ?>

<main id="mtl-main" class="mtl-shell <?= $immersive ? 'mtl-shell--immersive' : ($this->get('wide', false) ? 'mtl-shell--wide' : 'mtl-shell--reading') ?>">
    <?= $this->section('content') ?>
</main>

<?= $this->include('partials/notifications') ?>

<?php if (!$immersive): ?>
    <?= $this->include('partials/footer') ?>
<?php endif; ?>

<?php $this->end() ?>
