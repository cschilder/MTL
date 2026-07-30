<?php
/**
 * Two-factor enrolment.
 *
 * The QR code is rendered inline by MTL's own encoder, so the TOTP secret is
 * never sent to a chart service.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

$secret = (string) $this->get('secret', '');
$qr = (string) $this->get('qr', '');
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('auth.two_factor')) ?></h1>
    <div class="mtl-page-head__actions">
        <a class="p-button" href="<?= e(path('/admin/profile')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div style="max-inline-size: 34rem;">
    <p><?= e(__('auth.two_factor_scan')) ?></p>

    <?php if ($qr !== ''): ?>
        <div style="background: #fff; padding: var(--mtl-space-3); border-radius: var(--mtl-radius); display: inline-block;">
            <?= $qr /* generated SVG, no external references */ ?>
        </div>
    <?php endif; ?>

    <p class="p-form-help-text" style="margin-block-start: var(--mtl-space-4);">
        <?= e(__('auth.two_factor_manual')) ?>
    </p>

    <div class="mtl-row">
        <?php // Grouped in fours, which is how authenticator apps display it. ?>
        <input type="text" readonly value="<?= e(trim(chunk_split($secret, 4, ' '))) ?>"
               style="margin: 0; flex: 1; font-family: var(--mtl-mono, monospace); letter-spacing: 0.1em;">
        <button type="button" class="p-button" data-copy="<?= e($secret) ?>">
            <?= icon('copy', 16) ?>
        </button>
    </div>

    <form method="post" action="<?= e(path('/admin/profile/2fa')) ?>" class="mtl-form" style="margin-block-start: var(--mtl-space-5);">
        <?= csrf_field() ?>

        <div>
            <label for="code"><?= e(__('auth.two_factor_code')) ?></label>
            <input type="text" id="code" name="code" required
                   inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]{6}" maxlength="6"
                   style="font-size: 1.5rem; letter-spacing: 0.2em; text-align: center; max-inline-size: 10rem;">
        </div>

        <button type="submit" class="p-button--positive"><?= e(__('auth.two_factor_enabled')) ?></button>
    </form>
</div>

<?php $this->end() ?>
