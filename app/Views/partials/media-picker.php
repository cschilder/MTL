<?php
/**
 * The media picker dialog, rendered once per management page.
 *
 * Empty until it is opened: the grid is filled from /admin/api/media, so a
 * library of ten thousand photos costs nothing on page load.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

if (!can('media.upload')) {
    return;
}
?>
<dialog class="mtl-picker" data-media-picker aria-label="<?= e(__('media.select')) ?>">
    <div class="mtl-picker__head">
        <h2 class="p-heading--4" style="margin: 0;"><?= e(__('media.library')) ?></h2>

        <label class="mtl-visually-hidden" for="mtl-picker-search"><?= e(__('app.search')) ?></label>
        <input type="search" id="mtl-picker-search" data-picker-search
               placeholder="<?= e(__('app.search')) ?>" style="margin: 0; max-inline-size: 18rem;">

        <button type="button" class="p-button--base" data-picker-close style="margin: 0 0 0 auto;"
                aria-label="<?= e(__('app.close')) ?>">
            <?= icon('close', 18) ?>
        </button>
    </div>

    <div class="mtl-picker__body">
        <div class="mtl-media-grid" data-picker-grid role="listbox" aria-multiselectable="true"></div>
    </div>

    <div class="mtl-picker__foot">
        <span class="mtl-muted" data-picker-count></span>

        <span style="margin-inline-start: auto; display: flex; gap: var(--mtl-space-2);">
            <button type="button" class="p-button" data-picker-close><?= e(__('app.cancel')) ?></button>
            <button type="button" class="p-button--positive" data-picker-confirm disabled><?= e(__('media.select')) ?></button>
        </span>
    </div>
</dialog>
