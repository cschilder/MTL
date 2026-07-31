<?php
/**
 * The application error log.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var list<string> $lines */
$lines = $this->get('lines', []);
/** @var list<string> $dates */
$dates = $this->get('dates', []);
$date = (string) $this->get('date', '');
$level = (string) $this->get('level', '');
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('audit.error_log')) ?></h1>

    <div class="mtl-page-head__actions">
        <a class="p-button" href="<?= e(path('/admin/audit')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<form method="get" class="mtl-row" style="margin-block-end: var(--mtl-space-4);">
    <label class="mtl-visually-hidden" for="filter-date"><?= e(__('audit.when')) ?></label>
    <select id="filter-date" name="date" style="margin: 0; max-inline-size: 12rem;">
        <?php foreach ($dates as $available): ?>
            <option value="<?= e($available) ?>" <?= $available === $date ? 'selected' : '' ?>><?= e($available) ?></option>
        <?php endforeach; ?>
    </select>

    <label class="mtl-visually-hidden" for="filter-level">Level</label>
    <select id="filter-level" name="level" style="margin: 0; max-inline-size: 10rem;">
        <option value=""><?= e(__('app.all')) ?></option>
        <?php foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $option): ?>
            <option value="<?= e($option) ?>" <?= $level === $option ? 'selected' : '' ?>><?= e($option) ?></option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="p-button" style="margin: 0;"><?= e(__('app.search')) ?></button>
</form>

<?php if ($lines === []): ?>
    <p class="mtl-empty"><?= e(__('audit.empty')) ?></p>
<?php else: ?>
    <?php // Newest first, and each line kept intact: wrapping a stack trace
          // makes it unreadable, so the block scrolls sideways instead. ?>
    <pre style="max-block-size: 70dvh; overflow: auto;"><code><?php
        foreach ($lines as $line) {
            echo e($line), "\n";
        }
    ?></code></pre>
<?php endif; ?>

<?php $this->end() ?>
