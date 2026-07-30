<?php
/**
 * Pager.
 *
 * Plain links, so it works without JavaScript and is crawlable. The current
 * query string is preserved so filters survive a page change.
 *
 * @var MTL\Core\View $this
 * @var array{page:int,pages:int,total:int,per_page:int} $pagination
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

/** @var array{page:int,pages:int,total:int,per_page:int} $pagination */
$pagination = $this->get('pagination', ['page' => 1, 'pages' => 1, 'total' => 0, 'per_page' => 20]);

if (($pagination['pages'] ?? 1) < 2) {
    return;
}

$page = (int) $pagination['page'];
$pages = (int) $pagination['pages'];

/** @var MTL\Core\Request|null $request */
$request = $this->get('request');

$base = $request?->path ?? '/';
$query = $request?->query ?? [];
unset($query['page']);

$link = static fn (int $number): string => path($base, $query + ['page' => $number]);

// A window around the current page, so a hundred-page list does not render a
// hundred links.
$from = max(1, $page - 2);
$to = min($pages, $page + 2);
?>
<nav class="p-pagination" aria-label="<?= e(__('app.more')) ?>">
    <ol class="p-pagination__items">
        <li class="p-pagination__item">
            <?php if ($page > 1): ?>
                <a class="p-pagination__link--previous" href="<?= e($link($page - 1)) ?>" rel="prev">
                    <?= icon('chevron-left', 16) ?>
                    <span class="mtl-visually-hidden"><?= e(__('app.back')) ?></span>
                </a>
            <?php endif; ?>
        </li>

        <?php if ($from > 1): ?>
            <li class="p-pagination__item"><a class="p-pagination__link" href="<?= e($link(1)) ?>">1</a></li>
            <?php if ($from > 2): ?>
                <li class="p-pagination__item p-pagination__item--truncation">…</li>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($number = $from; $number <= $to; ++$number): ?>
            <li class="p-pagination__item">
                <?php if ($number === $page): ?>
                    <a class="p-pagination__link is-active" href="<?= e($link($number)) ?>" aria-current="page"><?= e((string) $number) ?></a>
                <?php else: ?>
                    <a class="p-pagination__link" href="<?= e($link($number)) ?>"><?= e((string) $number) ?></a>
                <?php endif; ?>
            </li>
        <?php endfor; ?>

        <?php if ($to < $pages): ?>
            <?php if ($to < $pages - 1): ?>
                <li class="p-pagination__item p-pagination__item--truncation">…</li>
            <?php endif; ?>
            <li class="p-pagination__item"><a class="p-pagination__link" href="<?= e($link($pages)) ?>"><?= e((string) $pages) ?></a></li>
        <?php endif; ?>

        <li class="p-pagination__item">
            <?php if ($page < $pages): ?>
                <a class="p-pagination__link--next" href="<?= e($link($page + 1)) ?>" rel="next">
                    <?= icon('chevron-right', 16) ?>
                    <span class="mtl-visually-hidden"><?= e(__('app.more')) ?></span>
                </a>
            <?php endif; ?>
        </li>
    </ol>
</nav>

<p class="mtl-muted" style="text-align: center;">
    <?= e((string) $pagination['total']) ?> —
    <?= e((string) $page) ?> / <?= e((string) $pages) ?>
</p>
