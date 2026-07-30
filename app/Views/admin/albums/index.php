<?php
/**
 * The album list in the management environment.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var list<MTL\Models\Album> $albums */
$albums = $this->get('albums', []);
/** @var array{q:string} $filters */
$filters = $this->get('filters', []);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('album.albums')) ?></h1>

    <div class="mtl-page-head__actions">
        <?php if (can('album.create')): ?>
            <a class="p-button--positive" href="<?= e(path('/admin/albums/new')) ?>">
                <?= icon('plus', 16) ?> <?= e(__('album.new')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="mtl-row" style="margin-block-end: var(--mtl-space-4);">
    <label class="mtl-visually-hidden" for="filter-q"><?= e(__('app.search')) ?></label>
    <input type="search" id="filter-q" name="q" value="<?= e($filters['q'] ?? '') ?>"
           placeholder="<?= e(__('app.search')) ?>" style="margin: 0; max-inline-size: 16rem;">
    <button type="submit" class="p-button" style="margin: 0;"><?= e(__('app.search')) ?></button>
</form>

<?php if ($albums === []): ?>
    <p class="mtl-empty"><?= e(__('album.none')) ?></p>
<?php else: ?>
    <div class="mtl-table-wrap">
        <table class="mtl-table mtl-table--stack">
            <thead>
                <tr>
                    <th></th>
                    <th><?= e(__('album.title')) ?></th>
                    <th><?= e(__('media.media')) ?></th>
                    <th><?= e(__('trip.status')) ?></th>
                    <th><?= e(__('album.layout')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($albums as $album): ?>
                    <?php $cover = $album->coverMedia(); ?>
                    <tr>
                        <td data-label="">
                            <?php if ($cover !== null): ?>
                                <img class="mtl-table__thumb" src="<?= e($cover->url('thumb')) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                        </td>
                        <td data-label="<?= e(__('album.title')) ?>">
                            <a href="<?= e($album->editUrl()) ?>"><?= e($album->string('title')) ?></a>
                        </td>
                        <td data-label="<?= e(__('media.media')) ?>"><?= e((string) $album->int('media_count')) ?></td>
                        <td data-label="<?= e(__('trip.status')) ?>">
                            <span class="mtl-status mtl-status--<?= e($album->string('status')) ?>"><?= e($album->string('status')) ?></span>
                        </td>
                        <td data-label="<?= e(__('album.layout')) ?>"><?= e($album->layout()) ?></td>
                        <td class="mtl-table__actions">
                            <a class="p-button--base" href="<?= e($album->url()) ?>"><?= icon('external', 16) ?></a>
                            <a class="p-button--base" href="<?= e($album->editUrl()) ?>"><?= icon('pencil', 16) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->include('partials/pagination') ?>
<?php endif; ?>

<?php $this->end() ?>
