<?php
/**
 * Site settings.
 *
 * The form is generated from the field list the controller allows, so the two
 * cannot drift apart: a key that is not in the controller's map simply never
 * appears here.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

$group = (string) $this->get('group', 'general');
/** @var list<string> $groups */
$groups = $this->get('groups', []);
/** @var array<string,mixed> $values */
$values = $this->get('values', []);
/** @var list<string> $fields */
$fields = $this->get('fields', []);
/** @var list<string> $locales */
$locales = $this->get('locales', []);

/** Field types, keyed by setting. */
$types = [
    'site.public'              => 'bool',
    'globe.auto_rotate'        => 'bool',
    'globe.show_graticule'     => 'bool',
    'globe.show_terminator'    => 'bool',
    'media.strip_gps'          => 'bool',
    'media.download_original'  => 'bool',
    'registration.open'        => 'bool',
    'maintenance.enabled'      => 'bool',
    'site.accent'              => 'color',
    'site.description'         => 'textarea',
    'maintenance.message'      => 'textarea',
    'media.quota_mb'           => 'number',
    'globe.marker_scale'       => 'number',
    'site.logo_media_id'       => 'media',
    'site.theme'               => ['auto', 'light', 'dark'],
    'site.language'            => $locales,
    'globe.default_view'       => ['globe', 'list'],
    'globe.resolution'         => ['low', 'high'],
    'media.default_visibility' => ['public', 'inherit', 'private'],
    'registration.default_role' => ['admin', 'editor', 'author', 'viewer'],
];

/** A readable label from the key, falling back to the key itself. */
$label = static function (string $key): string {
    $translated = __('setting.' . $key);

    if ($translated !== 'setting.' . $key) {
        return $translated;
    }

    return ucfirst(str_replace(['.', '_'], [' — ', ' '], $key));
};
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('settings.settings')) ?></h1>
</div>

<nav class="p-tabs" aria-label="<?= e(__('settings.settings')) ?>">
    <ul class="p-tabs__list">
        <?php foreach ($groups as $name): ?>
            <li class="p-tabs__item">
                <a class="p-tabs__link" href="<?= e(path('/admin/settings/' . $name)) ?>"
                   <?= $name === $group ? 'aria-selected="true"' : '' ?>>
                    <?= e(__('settings.' . $name)) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>

<form method="post" action="<?= e((string) $this->get('action')) ?>" class="mtl-form" style="margin-block-start: var(--mtl-space-5);">
    <?= csrf_field() ?>
    <?= method_field('PUT') ?>
    <input type="hidden" name="group" value="<?= e($group) ?>">

    <?php foreach ($fields as $key): ?>
        <?php
        $name = str_replace('.', '_', $key);
        $type = $types[$key] ?? 'text';
        $current = $values[$key] ?? '';
        ?>

        <div>
            <?php if ($type === 'bool'): ?>
                <label class="p-checkbox">
                    <input type="checkbox" class="p-checkbox__input" id="<?= e($name) ?>" name="<?= e($name) ?>" value="1"
                           <?= $current ? 'checked' : '' ?>>
                    <span class="p-checkbox__label"><?= e($label($key)) ?></span>
                </label>

            <?php elseif (is_array($type)): ?>
                <label for="<?= e($name) ?>"><?= e($label($key)) ?></label>
                <select id="<?= e($name) ?>" name="<?= e($name) ?>">
                    <?php foreach ($type as $option): ?>
                        <option value="<?= e((string) $option) ?>" <?= (string) $current === (string) $option ? 'selected' : '' ?>>
                            <?= e((string) $option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ($type === 'textarea'): ?>
                <label for="<?= e($name) ?>"><?= e($label($key)) ?></label>
                <textarea id="<?= e($name) ?>" name="<?= e($name) ?>" rows="3"><?= e((string) $current) ?></textarea>

            <?php elseif ($type === 'color'): ?>
                <label for="<?= e($name) ?>"><?= e($label($key)) ?></label>
                <input type="color" id="<?= e($name) ?>" name="<?= e($name) ?>"
                       value="<?= e((string) ($current ?: '#0f7d5c')) ?>"
                       style="inline-size: 4rem; block-size: 2.5rem; padding: 2px;">

            <?php elseif ($type === 'number'): ?>
                <label for="<?= e($name) ?>"><?= e($label($key)) ?></label>
                <input type="number" step="any" id="<?= e($name) ?>" name="<?= e($name) ?>"
                       value="<?= e((string) $current) ?>">

            <?php elseif ($type === 'media'): ?>
                <label for="<?= e($name) ?>"><?= e($label($key)) ?></label>
                <div class="mtl-row">
                    <input type="hidden" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e((string) $current) ?>">
                    <img data-logo-preview alt="" style="block-size: 3rem;" <?= $current ? '' : 'hidden' ?>
                         src="<?= e($current ? path('/media/thumb/' . $current) : '') ?>">
                    <button type="button" class="p-button"
                            data-pick-media="single"
                            data-pick-target="#<?= e($name) ?>"
                            data-pick-preview="[data-logo-preview]">
                        <?= e(__('media.select')) ?>
                    </button>
                </div>

            <?php else: ?>
                <label for="<?= e($name) ?>"><?= e($label($key)) ?></label>
                <input type="text" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e((string) $current) ?>">
            <?php endif; ?>

            <?php foreach (errors($key) as $message): ?>
                <p class="p-form-validation__message"><?= e($message) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="mtl-form-bar">
        <button type="submit" class="p-button--positive" style="margin: 0;"><?= e(__('app.save')) ?></button>
    </div>
</form>

<?php $this->end() ?>
