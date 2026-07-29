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

$this->layout('layouts/base');

$immersive = (bool) $this->get('immersive', false);
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
