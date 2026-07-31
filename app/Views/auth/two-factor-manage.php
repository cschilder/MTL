<?php
/**
 * Two-factor management for an account that already has it enabled.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Core\Session;

$this->layout('layouts/admin');

/** @var list<string>|mixed $freshCodes */
$freshCodes = Session::flashed('_recovery_codes', []);
$remaining = (int) $this->get('remaining', 0);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('auth.two_factor')) ?></h1>
    <div class="mtl-page-head__actions">
        <a class="p-button" href="<?= e(path('/admin/profile')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="p-notification--positive">
    <div class="p-notification__content">
        <p class="p-notification__message"><?= e(__('auth.two_factor_enabled')) ?></p>
    </div>
</div>

<?php if (is_array($freshCodes) && $freshCodes !== []): ?>
    <?php
    // Shown once, immediately after generation. Only hashes are stored, so
    // this is the only chance to write them down.
    ?>
    <section style="max-inline-size: 34rem; margin-block-start: var(--mtl-space-5);">
        <h2><?= e(__('auth.recovery_codes')) ?></h2>
        <p><?= e(__('auth.recovery_codes_intro')) ?></p>

        <pre style="user-select: all;"><code><?= e(implode("\n", array_map('strval', $freshCodes))) ?></code></pre>

        <button type="button" class="p-button" data-copy="<?= e(implode("\n", array_map('strval', $freshCodes))) ?>">
            <?= icon('copy', 16) ?> <?= e(__('app.more')) ?>
        </button>
    </section>
<?php else: ?>
    <p class="mtl-muted">
        <?= e(__('auth.recovery_codes')) ?>: <?= e((string) $remaining) ?>
    </p>
<?php endif; ?>

<div class="mtl-form__row" style="max-inline-size: 46rem; margin-block-start: var(--mtl-space-6);">
    <form method="post" action="<?= e(path('/admin/profile/2fa/recovery')) ?>" class="mtl-form__section">
        <h2><?= e(__('auth.recovery_codes')) ?></h2>
        <?= csrf_field() ?>

        <div>
            <label for="recovery-password"><?= e(__('auth.current_password')) ?></label>
            <input type="password" id="recovery-password" name="password" required autocomplete="current-password">
        </div>

        <button type="submit" class="p-button"><?= e(__('app.create')) ?></button>
    </form>

    <form method="post" action="<?= e(path('/admin/profile/2fa')) ?>" class="mtl-form__section"
          data-confirm="<?= e(__('app.confirm')) ?>">
        <h2><?= e(__('auth.two_factor_disabled')) ?></h2>
        <?= csrf_field() ?>
        <?= method_field('DELETE') ?>

        <div>
            <label for="disable-password"><?= e(__('auth.current_password')) ?></label>
            <input type="password" id="disable-password" name="password" required autocomplete="current-password">
        </div>

        <button type="submit" class="p-button--negative"><?= e(__('app.delete')) ?></button>
    </form>
</div>

<?php $this->end() ?>
