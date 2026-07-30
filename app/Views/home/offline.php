<?php
/**
 * Shown by the service worker when a page is requested with no connection and
 * nothing usable in the cache.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');
?>
<?php $this->start('content') ?>

<div class="u-align--center" style="padding-block: var(--mtl-space-7);">
    <p style="color: var(--mtl-accent);"><?= icon('globe', 56) ?></p>

    <h1><?= e(__('js.common.offline')) ?></h1>
    <p><?= e(__('error.offline')) ?></p>

    <p style="margin-block-start: var(--mtl-space-5);">
        <?php // A plain reload: by the time it is pressed the connection may be back. ?>
        <a class="p-button--positive" href="<?= e(path('/')) ?>"><?= e(__('error.go_home')) ?></a>
    </p>
</div>

<?php $this->end() ?>
