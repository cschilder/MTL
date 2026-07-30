<?php
/**
 * The media library.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Support\Str;

$this->layout('layouts/admin');

/** @var list<MTL\Models\Media> $media */
$media = $this->get('media', []);
/** @var array{q:string,kind:string,status:string,orphans:string} $filters */
$filters = $this->get('filters', []);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <div>
        <h1><?= e(__('media.library')) ?></h1>
        <p class="mtl-muted">
            <?= e(__('admin.storage_used')) ?>: <?= e(Str::bytes((int) $this->get('usage', 0))) ?>
        </p>
    </div>

    <div class="mtl-page-head__actions">
        <?php if (can('media.delete')): ?>
            <a class="p-button" href="<?= e(path('/admin/media/trash')) ?>">
                <?= icon('trash', 16) ?> <?= e(__('media.trash')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="mtl-dropzone" data-uploader data-uploader-list="[data-upload-list]">
    <?= icon('upload', 28) ?>
    <span><?= e(__('media.drop_here')) ?></span>
    <input type="file" multiple accept="image/*,video/*" aria-label="<?= e(__('media.upload')) ?>">
</div>

<div class="mtl-uploads" data-upload-list></div>

<form method="get" class="mtl-row" style="margin-block: var(--mtl-space-4);">
    <label class="mtl-visually-hidden" for="filter-q"><?= e(__('app.search')) ?></label>
    <input type="search" id="filter-q" name="q" value="<?= e($filters['q'] ?? '') ?>"
           placeholder="<?= e(__('app.search')) ?>" style="margin: 0; max-inline-size: 16rem;">

    <label class="mtl-visually-hidden" for="filter-kind"><?= e(__('media.media')) ?></label>
    <select id="filter-kind" name="kind" style="margin: 0; max-inline-size: 10rem;">
        <option value=""><?= e(__('app.all')) ?></option>
        <?php foreach (['image', 'video', 'audio', 'document'] as $kind): ?>
            <option value="<?= e($kind) ?>" <?= ($filters['kind'] ?? '') === $kind ? 'selected' : '' ?>><?= e($kind) ?></option>
        <?php endforeach; ?>
    </select>

    <label class="p-checkbox" style="margin: 0;">
        <input type="checkbox" class="p-checkbox__input" name="orphans" value="1"
               <?= ($filters['orphans'] ?? '') === '1' ? 'checked' : '' ?>>
        <span class="p-checkbox__label"><?= e(__('app.none')) ?></span>
    </label>

    <button type="submit" class="p-button" style="margin: 0;"><?= e(__('app.search')) ?></button>
</form>

<?php if ($media === []): ?>
    <p class="mtl-empty"><?= e(__('media.none')) ?></p>
<?php else: ?>
    <form method="post" action="<?= e(path('/admin/media/bulk')) ?>">
        <?= csrf_field() ?>

        <div class="mtl-media-grid">
            <?php foreach ($media as $item): ?>
                <?php
                // The tile is a link to the detail page; the checkbox sits on
                // top of it as a real input, so both selecting and opening work
                // without any script.
                ?>
                <div class="mtl-media-tile" style="display: block;">
                    <a href="<?= e(path('/admin/media/' . $item->id())) ?>"
                       aria-label="<?= e($item->displayTitle()) ?>"
                       style="display: block; block-size: 100%;">
                        <?php if ($item->isImage()): ?>
                            <img src="<?= e($item->url('thumb')) ?>" alt="<?= e($item->alt()) ?>"
                                 loading="lazy" decoding="async"
                                 style="<?= e($item->placeholderStyle()) ?>">
                        <?php else: ?>
                            <span style="display: grid; place-items: center; block-size: 100%;">
                                <?= icon($item->isVideo() ? 'photo-stack' : 'file', 28) ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <label style="position: absolute; inset-block-start: var(--mtl-space-1); inset-inline-start: var(--mtl-space-1); background: rgb(0 0 0 / 55%); border-radius: var(--mtl-radius); padding: 0.15rem 0.3rem;">
                        <input type="checkbox" name="ids[]" value="<?= e((string) $item->id()) ?>"
                               style="margin: 0;"
                               aria-label="<?= e($item->displayTitle()) ?>">
                    </label>

                    <?php if ($item->string('status') === 'failed'): ?>
                        <span class="mtl-media-tile__badge" style="background: #c7162b;" title="<?= e($item->string('processing_error')) ?>">!</span>
                    <?php elseif ($item->isVideo()): ?>
                        <span class="mtl-media-tile__badge"><?= e($item->durationLabel() ?: 'video') ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mtl-form-bar">
            <label class="mtl-visually-hidden" for="bulk-action"><?= e(__('app.edit')) ?></label>
            <select id="bulk-action" name="action" style="margin: 0; max-inline-size: 14rem;">
                <option value="delete"><?= e(__('app.delete')) ?></option>
                <option value="public"><?= e(__('trip.visibility')) ?>: public</option>
                <option value="private"><?= e(__('trip.visibility')) ?>: private</option>
                <option value="inherit"><?= e(__('trip.visibility')) ?>: inherit</option>
                <option value="rebuild"><?= e(__('maintenance.rebuild_media')) ?></option>
            </select>

            <button type="submit" class="p-button" style="margin: 0;"
                    data-confirm="<?= e(__('app.confirm')) ?>">
                <?= e(__('app.save')) ?>
            </button>

            <span class="mtl-form-bar__status"><?= e(__('media.selected', ['count' => count($media)])) ?></span>
        </div>
    </form>

    <?= $this->include('partials/pagination') ?>
<?php endif; ?>

<?php $this->end() ?>
