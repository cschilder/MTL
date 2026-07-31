<?php
/**
 * The trip list in the management environment.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var list<MTL\Models\Trip> $trips */
$trips = $this->get('trips', []);
/** @var array{q:string,status:string,trashed:string} $filters */
$filters = $this->get('filters', []);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('trip.trips')) ?></h1>

    <div class="mtl-page-head__actions">
        <?php if (can('trip.create')): ?>
            <a class="p-button--positive" href="<?= e(path('/admin/trips/new')) ?>">
                <?= icon('plus', 16) ?> <?= e(__('trip.new')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="mtl-row" style="margin-block-end: var(--mtl-space-4);">
    <label class="mtl-visually-hidden" for="filter-q"><?= e(__('app.search')) ?></label>
    <input type="search" id="filter-q" name="q" value="<?= e($filters['q'] ?? '') ?>"
           placeholder="<?= e(__('app.search')) ?>" style="margin: 0; max-inline-size: 16rem;">

    <label class="mtl-visually-hidden" for="filter-status"><?= e(__('trip.status')) ?></label>
    <select id="filter-status" name="status" style="margin: 0; max-inline-size: 12rem;">
        <option value=""><?= e(__('app.all')) ?></option>
        <?php foreach (['draft', 'published', 'archived'] as $status): ?>
            <option value="<?= e($status) ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>>
                <?= e($status) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="p-button" style="margin: 0;"><?= e(__('app.search')) ?></button>

    <?php if (($filters['trashed'] ?? '') === '1'): ?>
        <a href="<?= e(path('/admin/trips')) ?>" class="mtl-spacer"><?= e(__('app.back')) ?></a>
    <?php else: ?>
        <a href="<?= e(path('/admin/trips', ['trashed' => 1])) ?>" class="mtl-spacer"><?= e(__('media.trash')) ?></a>
    <?php endif; ?>
</form>

<?php if ($trips === []): ?>
    <p class="mtl-empty"><?= e(__('trip.none')) ?></p>
<?php else: ?>
    <div class="mtl-table-wrap">
        <table class="mtl-table mtl-table--stack">
            <thead>
                <tr>
                    <th><?= e(__('trip.title')) ?></th>
                    <th><?= e(__('trip.status')) ?></th>
                    <th><?= e(__('trip.visibility')) ?></th>
                    <th><?= e(__('trip.steps')) ?></th>
                    <th><?= e(__('trip.start_date')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trips as $trip): ?>
                    <tr>
                        <td data-label="<?= e(__('trip.title')) ?>">
                            <a href="<?= e($trip->editUrl()) ?>"><?= e($trip->string('title')) ?></a>
                            <?php if ($trip->isDeleted()): ?>
                                <span class="mtl-status mtl-status--private"><?= e(__('media.trash')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="<?= e(__('trip.status')) ?>">
                            <span class="mtl-status mtl-status--<?= e($trip->string('status')) ?>"><?= e($trip->string('status')) ?></span>
                        </td>
                        <td data-label="<?= e(__('trip.visibility')) ?>">
                            <span class="mtl-status mtl-status--<?= e($trip->string('visibility') === 'public' ? 'published' : 'private') ?>">
                                <?= e($trip->string('visibility')) ?>
                            </span>
                        </td>
                        <td data-label="<?= e(__('trip.steps')) ?>"><?= e((string) $trip->int('step_count')) ?></td>
                        <td data-label="<?= e(__('trip.start_date')) ?>"><?= e($trip->dateRange()) ?></td>
                        <td class="mtl-table__actions">
                            <?php if ($trip->isDeleted()): ?>
                                <form method="post" action="<?= e(path('/admin/trips/' . $trip->id() . '/restore')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="p-button--base"><?= e(__('app.restore')) ?></button>
                                </form>
                            <?php else: ?>
                                <a class="p-button--base" href="<?= e($trip->url()) ?>" title="<?= e(__('app.more')) ?>">
                                    <?= icon('external', 16) ?>
                                </a>
                                <a class="p-button--base" href="<?= e($trip->editUrl()) ?>" title="<?= e(__('app.edit')) ?>">
                                    <?= icon('pencil', 16) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->include('partials/pagination') ?>
<?php endif; ?>

<?php $this->end() ?>
