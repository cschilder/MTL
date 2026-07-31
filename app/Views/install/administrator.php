<?php
/**
 * Installer step 2: the first administrator, plus the site's name.
 *
 * @var MTL\Core\View $this
 * @var int $migrated  number of migrations the previous step ran, -1 if none
 * @var string $siteHost  hostname from app.url, offered as the default title
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('install/layout', ['step' => 'administrator', 'title' => $this->get('title')]);

$migrated = (int) $this->get('migrated', -1);
?>
<?php $this->start('content') ?>

<?php if ($migrated >= 0): ?>
    <div class="p-notification--positive">
        <div class="p-notification__content">
            <p class="p-notification__message">
                <?= e($migrated > 0
                    ? __('install.migrated', ['count' => $migrated])
                    : __('install.migrated_none')) ?>
            </p>
        </div>
    </div>
<?php endif; ?>

<h1><?= e(__('install.admin_heading')) ?></h1>
<p><?= e(__('install.admin_intro')) ?></p>

<form method="post" action="<?= e(path('/install/admin')) ?>" class="mtl-form" style="max-inline-size: 28rem;">
    <?= csrf_field() ?>

    <div>
        <label for="site_title"><?= e(__('install.site_title')) ?></label>
        <input type="text" id="site_title" name="site_title" required maxlength="120"
               value="<?= e((string) (old('site_title') ?: $this->get('siteHost'))) ?>"
               <?= has_errors('site_title') ? 'aria-invalid="true"' : '' ?>>
        <?php foreach (errors('site_title') as $message): ?>
            <p class="p-form-validation__message"><?= e($message) ?></p>
        <?php endforeach; ?>
        <p class="p-form-help-text"><?= e(__('install.site_title_help')) ?></p>
    </div>

    <div>
        <label for="name"><?= e(__('install.admin_name')) ?></label>
        <input type="text" id="name" name="name" required maxlength="120" autocomplete="name"
               value="<?= e((string) old('name')) ?>"
               <?= has_errors('name') ? 'aria-invalid="true"' : '' ?>>
        <?php foreach (errors('name') as $message): ?>
            <p class="p-form-validation__message"><?= e($message) ?></p>
        <?php endforeach; ?>
    </div>

    <div>
        <label for="email"><?= e(__('install.admin_email')) ?></label>
        <input type="email" id="email" name="email" required autocomplete="username"
               value="<?= e((string) old('email')) ?>"
               <?= has_errors('email') ? 'aria-invalid="true"' : '' ?>>
        <?php foreach (errors('email') as $message): ?>
            <p class="p-form-validation__message"><?= e($message) ?></p>
        <?php endforeach; ?>
    </div>

    <div>
        <label for="password"><?= e(__('install.admin_password')) ?></label>
        <input type="password" id="password" name="password" required minlength="10"
               autocomplete="new-password"
               <?= has_errors('password') ? 'aria-invalid="true"' : '' ?>>
        <?php foreach (errors('password') as $message): ?>
            <p class="p-form-validation__message"><?= e($message) ?></p>
        <?php endforeach; ?>
        <p class="p-form-help-text"><?= e(__('install.admin_password_help')) ?></p>
    </div>

    <div>
        <label for="password_confirmation"><?= e(__('install.admin_password_again')) ?></label>
        <input type="password" id="password_confirmation" name="password_confirmation" required
               minlength="10" autocomplete="new-password">
    </div>

    <button type="submit" class="p-button--positive"><?= e(__('install.admin_button')) ?></button>
</form>

<?php $this->end() ?>
