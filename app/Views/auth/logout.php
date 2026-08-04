<?php
/**
 * Signing out, reachable by URL.
 *
 * The actual sign-out is a POST (a GET that changes state gets prefetched by
 * browsers), but people type /logout and get sent links to it, so the GET
 * shows this one-button confirmation instead of a 404.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');

/** @var MTL\Models\User|null $currentUser */
$currentUser = $this->get('currentUser');
?>
<?php $this->start('content') ?>

<div style="max-inline-size: 24rem; margin-inline: auto; padding-block: var(--mtl-space-6);">
    <h1><?= e(__('nav.sign_out')) ?></h1>

    <p class="mtl-muted">
        <?= e(__('auth.signed_in_as', ['name' => $currentUser?->displayName() ?? ''])) ?>
    </p>

    <form method="post" action="<?= e(path('/logout')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="p-button--positive" style="margin: 0;">
            <?= e(__('nav.sign_out')) ?>
        </button>
        <a class="p-button" href="<?= e(path('/')) ?>" style="margin: 0;"><?= e(__('app.back')) ?></a>
    </form>
</div>

<?php $this->end() ?>
