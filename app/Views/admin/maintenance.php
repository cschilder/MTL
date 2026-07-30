<?php
/**
 * Maintenance tasks and the system report.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Support\Str;

$this->layout('layouts/admin');

/** @var array<string,array{label:string,description:string,slow:bool}> $tasks */
$tasks = $this->get('tasks', []);
/** @var array<string,mixed> $health */
$health = $this->get('health', []);
/** @var list<array{version:string,applied:bool,checksum_matches:bool}> $migrations */
$migrations = $this->get('migrations', []);

$pending = array_values(array_filter($migrations, static fn (array $m): bool => !$m['applied']));
$changed = array_values(array_filter($migrations, static fn (array $m): bool => !$m['checksum_matches']));
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('maintenance.maintenance')) ?></h1>
</div>

<?php if ($pending !== []): ?>
    <div class="p-notification--caution">
        <div class="p-notification__content">
            <h2 class="p-notification__title"><?= e((string) count($pending)) ?> migratie(s)</h2>
            <p class="p-notification__message">
                Er staan databasemigraties klaar die nog niet zijn uitgevoerd. Voer de taak
                <em>migrate</em> hieronder uit voordat je verder werkt.
            </p>
        </div>
    </div>
<?php endif; ?>

<?php if ($changed !== []): ?>
    <div class="p-notification--negative">
        <div class="p-notification__content">
            <p class="p-notification__message">
                Een al uitgevoerde migratie is achteraf gewijzigd
                (<?= e(implode(', ', array_column($changed, 'version'))) ?>).
                Twee installaties kunnen daardoor stilzwijgend uit elkaar lopen.
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="mtl-editor-layout">
    <div>
        <h2><?= e(__('maintenance.run')) ?></h2>

        <div class="mtl-stack">
            <?php foreach ($tasks as $key => $task): ?>
                <form method="post" action="<?= e(path('/admin/maintenance/' . $key)) ?>"
                      class="mtl-form__section"
                      <?= $task['slow'] ? 'data-confirm="' . e(__('app.confirm')) . '"' : '' ?>>
                    <?= csrf_field() ?>

                    <div class="mtl-row">
                        <div style="flex: 1; min-inline-size: 12rem;">
                            <h3 style="margin: 0; font-size: 1rem;"><?= e(__($task['label'])) ?></h3>
                            <p class="mtl-muted" style="margin: var(--mtl-space-1) 0 0;">
                                <?= e($task['description']) ?>
                            </p>
                        </div>

                        <button type="submit" class="p-button<?= $task['slow'] ? '' : '--positive' ?>" style="margin: 0;">
                            <?= e(__('maintenance.run')) ?>
                        </button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>

    <aside class="mtl-editor-layout__side mtl-stack">
        <div class="mtl-form__section">
            <h2><?= e(__('maintenance.health')) ?></h2>

            <dl style="display: grid; grid-template-columns: auto 1fr; gap: var(--mtl-space-1) var(--mtl-space-3); font-size: 0.875rem;">
                <dt class="mtl-muted">PHP</dt><dd style="margin: 0;"><?= e((string) ($health['php_version'] ?? '')) ?></dd>
                <dt class="mtl-muted">Database</dt><dd style="margin: 0;"><?= e((string) ($health['database_version'] ?? '')) ?></dd>
                <dt class="mtl-muted"><?= e(__('user.users')) ?></dt><dd style="margin: 0;"><?= e((string) ($health['users'] ?? 0)) ?></dd>
                <dt class="mtl-muted"><?= e(__('trip.trips')) ?></dt><dd style="margin: 0;"><?= e((string) ($health['trips'] ?? 0)) ?></dd>
                <dt class="mtl-muted"><?= e(__('step.steps')) ?></dt><dd style="margin: 0;"><?= e((string) ($health['steps'] ?? 0)) ?></dd>
                <dt class="mtl-muted"><?= e(__('media.media')) ?></dt><dd style="margin: 0;"><?= e((string) ($health['media'] ?? 0)) ?></dd>
                <dt class="mtl-muted"><?= e(__('admin.storage_used')) ?></dt>
                <dd style="margin: 0;"><?= e(Str::bytes((int) ($health['media_bytes'] ?? 0))) ?></dd>
                <dt class="mtl-muted"><?= e(__('media.trash')) ?></dt><dd style="margin: 0;"><?= e((string) $this->get('trashCount', 0)) ?></dd>
                <dt class="mtl-muted">Vrije schijf</dt>
                <dd style="margin: 0;"><?= e(Str::bytes((int) ($health['disk_free'] ?? 0))) ?></dd>
            </dl>

            <?php if ((int) $this->get('failedMedia', 0) > 0): ?>
                <p class="p-notification--caution" style="margin-block-start: var(--mtl-space-3);">
                    <span class="p-notification__content">
                        <span class="p-notification__message">
                            <?= e((string) $this->get('failedMedia')) ?> bestand(en) konden niet verkleind worden.
                        </span>
                    </span>
                </p>
            <?php endif; ?>
        </div>

        <div class="mtl-form__section">
            <h2>Migraties</h2>

            <ul class="p-list" style="font-size: 0.8125rem;">
                <?php foreach ($migrations as $migration): ?>
                    <li class="p-list__item">
                        <?= e($migration['version']) ?>
                        <span class="mtl-status mtl-status--<?= $migration['applied'] ? 'published' : 'draft' ?>">
                            <?= $migration['applied'] ? 'ok' : 'open' ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </aside>
</div>

<?php $this->end() ?>
