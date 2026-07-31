<?php
/**
 * The management landing page.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Support\Str;

$this->layout('layouts/admin');

/** @var array<string,int> $counts */
$counts = $this->get('counts', []);
/** @var array<string,mixed> $statistics */
$statistics = $this->get('statistics', []);
/** @var list<MTL\Models\Trip> $recentTrips */
$recentTrips = $this->get('recentTrips', []);
/** @var list<MTL\Models\Step> $recentSteps */
$recentSteps = $this->get('recentSteps', []);
/** @var list<MTL\Models\Media> $recentMedia */
$recentMedia = $this->get('recentMedia', []);
/** @var list<array<string,mixed>> $activity */
$activity = $this->get('activity', []);
/** @var array<string,mixed> $health */
$health = $this->get('health', []);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <div>
        <h1><?= e(__('admin.dashboard')) ?></h1>
        <p class="mtl-muted"><?= e(__('admin.welcome', ['name' => $this->get('currentUser')?->displayName() ?? ''])) ?></p>
    </div>

    <div class="mtl-page-head__actions">
        <?php if (can('trip.create')): ?>
            <a class="p-button--positive" href="<?= e(path('/admin/trips/new')) ?>">
                <?= icon('plus', 16) ?> <?= e(__('trip.new')) ?>
            </a>
        <?php endif; ?>

        <?php if (can('media.upload')): ?>
            <a class="p-button" href="<?= e(path('/admin/media')) ?>">
                <?= icon('upload', 16) ?> <?= e(__('media.upload')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<dl class="mtl-stats">
    <div class="mtl-stat">
        <dt><?= e(__('trip.trips')) ?></dt>
        <dd><?= e((string) ($counts['trips'] ?? 0)) ?></dd>
    </div>
    <div class="mtl-stat">
        <dt><?= e(__('step.steps')) ?></dt>
        <dd><?= e((string) ($counts['steps'] ?? 0)) ?></dd>
    </div>
    <div class="mtl-stat">
        <dt><?= e(__('media.media')) ?></dt>
        <dd><?= e((string) ($counts['media'] ?? 0)) ?></dd>
    </div>
    <div class="mtl-stat">
        <dt><?= e(__('trip.countries')) ?></dt>
        <dd><?= e((string) ($statistics['countries'] ?? 0)) ?></dd>
    </div>
    <div class="mtl-stat">
        <dt><?= e(__('trip.distance')) ?></dt>
        <dd><?= e(number_format((float) ($statistics['distance_km'] ?? 0), 0, ',', '.')) ?><small> km</small></dd>
    </div>
</dl>

<div class="mtl-editor-layout" style="margin-block-start: var(--mtl-space-6);">
    <div>
        <?php // ---- Recent trips -------------------------------------------- ?>
        <h2><?= e(__('trip.trips')) ?></h2>

        <?php if ($recentTrips === []): ?>
            <p class="mtl-empty"><?= e(__('trip.none')) ?></p>
        <?php else: ?>
            <div class="mtl-table-wrap">
                <table class="mtl-table mtl-table--stack">
                    <thead>
                        <tr>
                            <th><?= e(__('trip.title')) ?></th>
                            <th><?= e(__('trip.status')) ?></th>
                            <th><?= e(__('trip.steps')) ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTrips as $trip): ?>
                            <tr>
                                <td data-label="<?= e(__('trip.title')) ?>">
                                    <a href="<?= e($trip->editUrl()) ?>"><?= e($trip->string('title')) ?></a>
                                </td>
                                <td data-label="<?= e(__('trip.status')) ?>">
                                    <span class="mtl-status mtl-status--<?= e($trip->string('status')) ?>">
                                        <?= e($trip->string('status')) ?>
                                    </span>
                                </td>
                                <td data-label="<?= e(__('trip.steps')) ?>"><?= e((string) $trip->int('step_count')) ?></td>
                                <td class="mtl-table__actions">
                                    <a class="p-button--base" href="<?= e($trip->url()) ?>" title="<?= e(__('app.more')) ?>">
                                        <?= icon('external', 16) ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php // ---- Recent steps -------------------------------------------- ?>
        <?php if ($recentSteps !== []): ?>
            <h2 style="margin-block-start: var(--mtl-space-6);"><?= e(__('step.steps')) ?></h2>
            <ul class="p-list--divided">
                <?php foreach ($recentSteps as $step): ?>
                    <li class="p-list__item">
                        <a href="<?= e($step->editUrl()) ?>"><?= e($step->string('title')) ?></a>
                        <span class="mtl-muted"> · <?= e($step->string('location_name')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php // ---- Recent media -------------------------------------------- ?>
        <?php if ($recentMedia !== []): ?>
            <h2 style="margin-block-start: var(--mtl-space-6);"><?= e(__('media.media')) ?></h2>
            <div class="mtl-media-grid">
                <?php foreach ($recentMedia as $item): ?>
                    <a class="mtl-media-tile" href="<?= e(path('/admin/media/' . $item->id())) ?>">
                        <img src="<?= e($item->url('thumb')) ?>" alt="<?= e($item->alt()) ?>" loading="lazy"
                             style="<?= e($item->placeholderStyle()) ?>">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <aside class="mtl-editor-layout__side">
        <?php if ($activity !== []): ?>
            <div class="mtl-form__section">
                <h2><?= e(__('admin.recent_activity')) ?></h2>
                <ul class="p-list" style="margin: 0;">
                    <?php foreach ($activity as $entry): ?>
                        <li class="p-list__item" style="font-size: 0.875rem;">
                            <strong><?= e((string) $entry['user_label']) ?></strong>
                            <?= e((string) $entry['action']) ?>
                            <?php if (($entry['subject_label'] ?? '') !== ''): ?>
                                — <?= e((string) $entry['subject_label']) ?>
                            <?php endif; ?>
                            <br>
                            <span class="mtl-muted"><?= e(date('j M, H:i', (int) strtotime((string) $entry['created_at'] . ' UTC'))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if (can('audit.view')): ?>
                    <p><a href="<?= e(path('/admin/audit')) ?>"><?= e(__('app.more')) ?></a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($health !== []): ?>
            <div class="mtl-form__section" style="margin-block-start: var(--mtl-space-4);">
                <h2><?= e(__('admin.system')) ?></h2>

                <dl style="display: grid; grid-template-columns: auto 1fr; gap: var(--mtl-space-1) var(--mtl-space-3); font-size: 0.875rem;">
                    <dt class="mtl-muted">PHP</dt><dd style="margin: 0;"><?= e((string) $health['php_version']) ?></dd>
                    <dt class="mtl-muted">Database</dt><dd style="margin: 0;"><?= e((string) $health['database_version']) ?></dd>
                    <dt class="mtl-muted"><?= e(__('admin.storage_used')) ?></dt>
                    <dd style="margin: 0;"><?= e(Str::bytes((int) $health['media_bytes'])) ?></dd>
                    <dt class="mtl-muted"><?= e(__('user.users')) ?></dt><dd style="margin: 0;"><?= e((string) $health['users']) ?></dd>
                </dl>

                <p><a href="<?= e(path('/admin/maintenance')) ?>"><?= e(__('maintenance.maintenance')) ?></a></p>
            </div>
        <?php endif; ?>
    </aside>
</div>

<?php $this->end() ?>
