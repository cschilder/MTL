<?php
/**
 * Accepting an invitation: choose a password and the account goes live.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');
?>
<?php $this->start('content') ?>

<div style="max-inline-size: 26rem; margin-inline: auto;">
    <h1><?= e(__('auth.invite_title')) ?></h1>
    <p><?= e(__('auth.invite_intro')) ?></p>

    <form method="post" action="<?= e((string) $this->get('action')) ?>" class="mtl-form">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e((string) $this->get('token')) ?>">

        <div>
            <label for="name"><?= e(__('user.name')) ?></label>
            <input type="text" id="name" name="name" autocomplete="name"
                   value="<?= e((string) $this->get('name', '')) ?>">
        </div>

        <div>
            <label for="password"><?= e(__('auth.new_password')) ?></label>
            <input type="password" id="password" name="password" required
                   autocomplete="new-password" minlength="10">
            <p class="p-form-help-text"><?= e(__('validation.password_short')) ?></p>
            <?php foreach (errors('password') as $message): ?>
                <p class="p-form-validation__message"><?= e($message) ?></p>
            <?php endforeach; ?>
        </div>

        <div>
            <label for="password_confirmation"><?= e(__('auth.confirm_password')) ?></label>
            <input type="password" id="password_confirmation" name="password_confirmation" required
                   autocomplete="new-password">
        </div>

        <button type="submit" class="p-button--positive"><?= e(__('app.create')) ?></button>
    </form>
</div>

<?php $this->end() ?>
