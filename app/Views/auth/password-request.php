<?php
/**
 * "Forgotten your password" form.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');
?>
<?php $this->start('content') ?>

<div style="max-inline-size: 26rem; margin-inline: auto;">
    <h1><?= e(__('auth.forgot_title')) ?></h1>
    <p><?= e(__('auth.forgot_intro')) ?></p>

    <form method="post" action="<?= e(path('/password/forgot')) ?>" class="mtl-form">
        <?= csrf_field() ?>

        <div>
            <label for="email"><?= e(__('auth.email')) ?></label>
            <input type="email" id="email" name="email" required autocomplete="username"
                   autocapitalize="none" spellcheck="false" autofocus
                   value="<?= e((string) old('email')) ?>">
            <?php foreach (errors('email') as $message): ?>
                <p class="p-form-validation__message"><?= e($message) ?></p>
            <?php endforeach; ?>
        </div>

        <div class="mtl-row">
            <button type="submit" class="p-button--positive" style="margin: 0;"><?= e(__('app.save')) ?></button>
            <a href="<?= e(path('/login')) ?>"><?= e(__('app.back')) ?></a>
        </div>
    </form>
</div>

<?php $this->end() ?>
