<?php
/**
 * Tag management.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var list<MTL\Models\Tag> $tags */
$tags = $this->get('tags', []);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('nav.tags')) ?></h1>
</div>

<form method="post" action="<?= e(path('/admin/tags')) ?>" class="mtl-row" style="margin-block-end: var(--mtl-space-5);">
    <?= csrf_field() ?>

    <label class="mtl-visually-hidden" for="new-tag"><?= e(__('user.name')) ?></label>
    <input type="text" id="new-tag" name="name" required maxlength="80"
           placeholder="<?= e(__('nav.tags')) ?>" style="margin: 0; max-inline-size: 16rem;">

    <input type="color" name="color" value="#0f7d5c" style="margin: 0; inline-size: 3rem; block-size: 2.5rem; padding: 2px;"
           aria-label="<?= e(__('trip.color')) ?>">

    <button type="submit" class="p-button--positive" style="margin: 0;">
        <?= icon('plus', 16) ?> <?= e(__('app.create')) ?>
    </button>
</form>

<?php if ($tags === []): ?>
    <p class="mtl-empty"><?= e(__('app.none')) ?></p>
<?php else: ?>
    <div class="mtl-table-wrap">
        <table class="mtl-table mtl-table--stack">
            <thead>
                <tr>
                    <th><?= e(__('user.name')) ?></th>
                    <th><?= e(__('field.slug')) ?></th>
                    <th><?= e(__('app.more')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tags as $tag): ?>
                    <tr>
                        <td data-label="<?= e(__('user.name')) ?>">
                            <form method="post" action="<?= e(path('/admin/tags/' . $tag->id())) ?>" class="mtl-row"
                                  id="tag-<?= e((string) $tag->id()) ?>">
                                <?= csrf_field() ?>
                                <?= method_field('PUT') ?>

                                <input type="text" name="name" value="<?= e($tag->string('name')) ?>"
                                       maxlength="80" required style="margin: 0; max-inline-size: 14rem;"
                                       aria-label="<?= e(__('user.name')) ?>">

                                <input type="color" name="color"
                                       value="<?= e($tag->string('color') ?: '#0f7d5c') ?>"
                                       style="margin: 0; inline-size: 3rem; block-size: 2.5rem; padding: 2px;"
                                       aria-label="<?= e(__('trip.color')) ?>">
                            </form>
                        </td>
                        <td data-label="<?= e(__('field.slug')) ?>"><code><?= e($tag->string('slug')) ?></code></td>
                        <td data-label="<?= e(__('app.more')) ?>"><?= e((string) $tag->int('usage_count')) ?></td>
                        <td class="mtl-table__actions">
                            <button type="submit" form="tag-<?= e((string) $tag->id()) ?>" class="p-button--base">
                                <?= e(__('app.save')) ?>
                            </button>

                            <form method="post" action="<?= e(path('/admin/tags/' . $tag->id())) ?>"
                                  data-confirm="<?= e(__('js.common.confirm_delete')) ?>">
                                <?= csrf_field() ?>
                                <?= method_field('DELETE') ?>
                                <button type="submit" class="p-button--base" aria-label="<?= e(__('app.delete')) ?>">
                                    <?= icon('trash', 16) ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->include('partials/pagination') ?>
<?php endif; ?>

<?php $this->end() ?>
