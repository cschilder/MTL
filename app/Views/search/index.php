<?php
/**
 * Search results.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');

$query = (string) $this->get('query', '');
/** @var list<array<string,mixed>> $results */
$results = $this->get('results', []);
$total = (int) $this->get('total', 0);
/** @var list<string> $types */
$types = $this->get('types', []);
/** @var list<MTL\Models\Tag> $popularTags */
$popularTags = $this->get('popularTags', []);

$typeLabels = [
    'trip'  => __('trip.trip'),
    'step'  => __('step.step'),
    'album' => __('album.album'),
    'media' => __('media.media'),
];
?>
<?php $this->start('content') ?>

<h1><?= e(__('app.search')) ?></h1>

<form method="get" action="<?= e(path('/search')) ?>" role="search" style="margin-block: var(--mtl-space-4);">
    <label class="mtl-visually-hidden" for="mtl-search-q"><?= e(__('search.placeholder')) ?></label>

    <div class="mtl-row">
        <input type="search" id="mtl-search-q" name="q" value="<?= e($query) ?>"
               placeholder="<?= e(__('search.placeholder')) ?>"
               style="margin: 0; flex: 1; min-inline-size: 14rem;" autofocus>
        <button type="submit" class="p-button--positive" style="margin: 0;"><?= e(__('app.search')) ?></button>
    </div>

    <fieldset style="border: 0; margin: var(--mtl-space-3) 0 0; padding: 0;">
        <legend class="mtl-visually-hidden"><?= e(__('app.all')) ?></legend>

        <?php foreach ($typeLabels as $value => $label): ?>
            <label class="p-checkbox" style="display: inline-flex; margin-inline-end: var(--mtl-space-4);">
                <input type="checkbox" class="p-checkbox__input" name="type[]" value="<?= e($value) ?>"
                       <?= in_array($value, $types, true) ? 'checked' : '' ?>>
                <span class="p-checkbox__label"><?= e($label) ?></span>
            </label>
        <?php endforeach; ?>
    </fieldset>
</form>

<?php if ($query === ''): ?>
    <?php if ($popularTags !== []): ?>
        <h2><?= e(__('nav.tags')) ?></h2>
        <p>
            <?php foreach ($popularTags as $tag): ?>
                <a class="p-chip" href="<?= e($tag->url()) ?>">
                    <span class="p-chip__value"><?= e($tag->string('name')) ?></span>
                    <span class="mtl-muted">&nbsp;<?= e((string) $tag->int('usage_count')) ?></span>
                </a>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
<?php elseif ($results === []): ?>
    <p class="mtl-empty"><?= e(__('search.none', ['query' => $query])) ?></p>
<?php else: ?>
    <p class="mtl-muted"><?= e(__('search.count', ['count' => $total])) ?></p>

    <?php foreach ($results as $result): ?>
        <article class="mtl-result">
            <p class="mtl-muted" style="margin: 0;">
                <?= e($typeLabels[$result['subject_type']] ?? (string) $result['subject_type']) ?>
                <?php if (!empty($result['occurred_at'])): ?>
                    · <?= e(date('j M Y', (int) strtotime((string) $result['occurred_at'] . ' UTC'))) ?>
                <?php endif; ?>
            </p>

            <h2 style="margin: var(--mtl-space-1) 0;">
                <a href="<?= e((string) $result['url']) ?>">
                    <?= $result['title_html'] /* escaped by SearchService, with <mark> around matches */ ?>
                </a>
            </h2>

            <p><?= $result['snippet'] /* escaped by SearchService */ ?></p>
        </article>
    <?php endforeach; ?>

    <?= $this->include('partials/pagination', [
        'pagination' => ['page' => $this->get('page', 1), 'pages' => $this->get('pages', 1), 'total' => $total, 'per_page' => 20],
    ]) ?>
<?php endif; ?>

<?php $this->end() ?>
