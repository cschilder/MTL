<?php
/**
 * The account request form.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');
?>
<?php $this->start('content') ?>

<div style="max-inline-size: 24rem; margin-inline: auto;">
    <h1><?= e(__('auth.register')) ?></h1>

    <p class="mtl-muted"><?= e(__('auth.register_intro')) ?></p>

    <form method="post" action="<?= e(path('/register')) ?>" class="mtl-form">
        <?= csrf_field() ?>

        <div>
            <label for="name"><?= e(__('auth.name')) ?></label>
            <input type="text" id="name" name="name" required maxlength="120"
                   autocomplete="name" value="<?= e((string) old('name')) ?>"
                   <?= has_errors('name') ? 'aria-invalid="true"' : '' ?> autofocus>
            <?php foreach (errors('name') as $message): ?>
                <p class="p-form-validation__message"><?= e($message) ?></p>
            <?php endforeach; ?>
        </div>

        <div>
            <label for="email"><?= e(__('auth.email')) ?></label>
            <input type="email" id="email" name="email" required autocomplete="email"
                   autocapitalize="none" spellcheck="false"
                   value="<?= e((string) old('email')) ?>"
                   <?= has_errors('email') ? 'aria-invalid="true"' : '' ?>>
            <?php foreach (errors('email') as $message): ?>
                <p class="p-form-validation__message"><?= e($message) ?></p>
            <?php endforeach; ?>
        </div>

        <div>
            <label for="password"><?= e(__('auth.password')) ?></label>
            <input type="password" id="password" name="password" required autocomplete="new-password"
                   <?= has_errors('password') ? 'aria-invalid="true"' : '' ?>>
            <?php foreach (errors('password') as $message): ?>
                <p class="p-form-validation__message"><?= e($message) ?></p>
            <?php endforeach; ?>
        </div>

        <div>
            <label for="password_confirmation"><?= e(__('auth.password_confirm')) ?></label>
            <input type="password" id="password_confirmation" name="password_confirmation" required
                   autocomplete="new-password">
        </div>

        <div class="mtl-row">
            <button type="submit" class="p-button--positive" style="margin: 0;"><?= e(__('auth.register_submit')) ?></button>
            <a href="<?= e(path('/login')) ?>"><?= e(__('auth.sign_in')) ?></a>
        </div>
    </form>
</div>

<?php $this->end() ?>
