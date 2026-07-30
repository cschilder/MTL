<?php
/**
 * The second-factor challenge.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');
?>
<?php $this->start('content') ?>

<div style="max-inline-size: 24rem; margin-inline: auto;">
    <h1><?= e(__('auth.two_factor')) ?></h1>
    <p><?= e(__('auth.two_factor_prompt')) ?></p>

    <form method="post" action="<?= e(path('/login/2fa')) ?>" class="mtl-form">
        <?= csrf_field() ?>

        <div>
            <label for="code"><?= e(__('auth.two_factor_code')) ?></label>
            <?php
            // inputmode numeric brings up the number pad on a phone;
            // one-time-code lets the OS offer the message or the password
            // manager entry.
            ?>
            <input type="text" id="code" name="code" required
                   inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9A-Za-z-]{6,12}" maxlength="12"
                   spellcheck="false" autocapitalize="characters"
                   autofocus
                   style="font-size: 1.5rem; letter-spacing: 0.2em; text-align: center;">
            <p class="p-form-help-text"><?= e(__('auth.two_factor_recovery')) ?></p>
        </div>

        <button type="submit" class="p-button--positive"><?= e(__('auth.sign_in')) ?></button>
    </form>
</div>

<?php $this->end() ?>
