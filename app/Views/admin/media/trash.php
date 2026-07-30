<?php
/**
 * The media bin.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var list<MTL\Models\Media> $media */
$media = $this->get('media', []);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <div>
        <h1><?= e(__('media.trash')) ?></h1>
        <p class="mtl-muted">
            Bestanden blijven hier staan tot ze definitief worden verwijderd, of tot het
            onderhoud ze na dertig dagen opruimt.
        </p>
    </div>

    <div class="mtl-page-head__actions">
        <a class="p-button" href="<?= e(path('/admin/media')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<?php if ($media === []): ?>
    <p class="mtl-empty"><?= e(__('media.trash_empty')) ?></p>
<?php else: ?>
    <div class="mtl-table-wrap">
        <table class="mtl-table mtl-table--stack">
            <thead>
                <tr>
                    <th></th>
                    <th><?= e(__('media.title')) ?></th>
                    <th><?= e(__('media.file_size')) ?></th>
                    <th><?= e(__('audit.when')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($media as $item): ?>
                    <tr>
                        <td data-label="">
                            <?php if ($item->isImage()): ?>
                                <img class="mtl-table__thumb" src="<?= e($item->url('thumb')) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                        </td>
                        <td data-label="<?= e(__('media.title')) ?>"><?= e($item->displayTitle()) ?></td>
                        <td data-label="<?= e(__('media.file_size')) ?>"><?= e($item->sizeLabel()) ?></td>
                        <td data-label="<?= e(__('audit.when')) ?>">
                            <?= e($item->date('deleted_at')?->format('j M Y') ?? '') ?>
                        </td>
                        <td class="mtl-table__actions">
                            <form method="post" action="<?= e(path('/admin/media/' . $item->id() . '/restore')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="p-button--base"><?= e(__('app.restore')) ?></button>
                            </form>

                            <form method="post" action="<?= e(path('/admin/media/' . $item->id())) ?>"
                                  data-confirm="<?= e(__('js.common.confirm_delete')) ?>">
                                <?= csrf_field() ?>
                                <?= method_field('DELETE') ?>
                                <button type="submit" class="p-button--negative is-dense"><?= e(__('app.delete')) ?></button>
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
