<?php
/**
 * The sign-in form.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');
?>
<?php $this->start('content') ?>

<div style="max-inline-size: 24rem; margin-inline: auto;">
    <h1><?= e(__('auth.sign_in')) ?></h1>

    <form method="post" action="<?= e(path('/login')) ?>" class="mtl-form">
        <?= csrf_field() ?>

        <div>
            <label for="email"><?= e(__('auth.email')) ?></label>
            <input type="email" id="email" name="email" required autocomplete="username"
                   autocapitalize="none" spellcheck="false"
                   value="<?= e((string) old('email')) ?>"
                   <?= has_errors('email') ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>
                   autofocus>
            <?php foreach (errors('email') as $message): ?>
                <p class="p-form-validation__message" id="email-error"><?= e($message) ?></p>
            <?php endforeach; ?>
        </div>

        <div>
            <label for="password"><?= e(__('auth.password')) ?></label>
            <input type="password" id="password" name="password" required autocomplete="current-password"
                   <?= has_errors('password') ? 'aria-invalid="true"' : '' ?>>
            <?php foreach (errors('password') as $message): ?>
                <p class="p-form-validation__message"><?= e($message) ?></p>
            <?php endforeach; ?>
        </div>

        <label class="p-checkbox">
            <input type="checkbox" class="p-checkbox__input" name="remember" value="1">
            <span class="p-checkbox__label"><?= e(__('auth.remember')) ?></span>
        </label>

        <div class="mtl-row">
            <button type="submit" class="p-button--positive" style="margin: 0;"><?= e(__('auth.sign_in')) ?></button>
            <a href="<?= e(path('/password/forgot')) ?>"><?= e(__('auth.forgot')) ?></a>
        </div>
    </form>
</div>

<?php $this->end() ?>
