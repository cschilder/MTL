<?php
/**
 * Installer step 1: the environment.
 *
 * Shown after every repair the installer can do by itself has been done, so
 * what is listed here is the state as it now stands — and anything red is
 * something only the person at the keyboard can fix.
 *
 * @var MTL\Core\View $this
 * @var list<string> $repairs
 * @var list<array{label:string,ok:bool,required:bool,detail:string}> $checks
 * @var bool $ready
 * @var array{ready:bool,version:string,error:string,pending:int,applied:int}|null $database
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('install/layout', ['step' => 'environment', 'title' => $this->get('title')]);
?>
<?php $this->start('content') ?>

<h1><?= e(__('install.environment_heading')) ?></h1>
<p><?= e(__('install.environment_intro')) ?></p>

<?php if ($repairs !== []): ?>
    <div class="p-notification--positive">
        <div class="p-notification__content">
            <p class="p-notification__message"><?= e(__('install.repaired_intro')) ?></p>
        </div>
    </div>
    <ul>
        <?php foreach ($repairs as $repair): ?>
            <li><?= e($repair) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<table class="mtl-table">
    <tbody>
    <?php foreach ($checks as $check): ?>
        <tr>
            <td><?= e($check['label']) ?></td>
            <td>
                <?php if ($check['ok']): ?>
                    <span class="mtl-status mtl-status--published"><?= e(__('install.status_ok')) ?></span>
                <?php elseif ($check['required']): ?>
                    <span class="mtl-status mtl-status--private"><?= e(__('install.status_blocking')) ?></span>
                <?php else: ?>
                    <span class="mtl-status mtl-status--draft"><?= e(__('install.status_optional')) ?></span>
                <?php endif; ?>
            </td>
            <td class="mtl-muted"><?= $check['ok'] ? e($check['detail'] !== '' && $check['label'] === __('install.check_php') ? $check['detail'] : '') : e($check['detail']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if (!$ready): ?>
    <div class="p-notification--negative">
        <div class="p-notification__content">
            <p class="p-notification__message"><?= e(__('install.environment_blocked')) ?></p>
        </div>
    </div>
    <p><a class="p-button" href="<?= e(path('/install')) ?>"><?= e(__('install.recheck')) ?></a></p>
<?php else: ?>

    <h2><?= e(__('install.database_heading')) ?></h2>

    <?php if ($database !== null && !$database['ready']): ?>
        <div class="p-notification--negative">
            <div class="p-notification__content">
                <p class="p-notification__message">
                    <?= e(__('install.database_unreachable')) ?><br>
                    <code><?= e($database['error']) ?></code>
                </p>
            </div>
        </div>
        <p><?= e(__('install.database_unreachable_hint')) ?></p>
        <p><a class="p-button" href="<?= e(path('/install')) ?>"><?= e(__('install.recheck')) ?></a></p>
    <?php elseif ($database !== null): ?>
        <p>
            <?= e(__('install.database_connected', ['version' => $database['version']])) ?>
            <?php if ($database['pending'] > 0): ?>
                <?= e(__('install.database_pending', ['count' => $database['pending']])) ?>
            <?php else: ?>
                <?= e(__('install.database_current')) ?>
            <?php endif; ?>
        </p>

        <form method="post" action="<?= e(path('/install/database')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="p-button--positive">
                <?= e($database['pending'] > 0 ? __('install.database_button') : __('install.database_continue')) ?>
            </button>
            <a class="p-button" href="<?= e(path('/install')) ?>"><?= e(__('install.recheck')) ?></a>
        </form>
    <?php endif; ?>

<?php endif; ?>

<?php $this->end() ?>
