<?php
/**
 * Installer step 3: done.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('install/layout', ['step' => 'done', 'title' => $this->get('title')]);
?>
<?php $this->start('content') ?>

<h1><?= e(__('install.done_heading')) ?></h1>
<p><?= e(__('install.done_intro')) ?></p>

<ul class="p-list--divided">
    <li class="p-list__item"><?= e(__('install.done_locked')) ?></li>
    <li class="p-list__item"><?= e(__('install.done_signed_in')) ?></li>
    <li class="p-list__item"><?= e(__('install.done_next')) ?></li>
</ul>

<p class="mtl-row">
    <a class="p-button--positive" href="<?= e(path('/admin')) ?>"><?= e(__('install.done_admin')) ?></a>
    <a class="p-button" href="<?= e(path('/')) ?>"><?= e(__('install.done_site')) ?></a>
</p>

<?php $this->end() ?>
