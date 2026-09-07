<?php
/**
 * The travel-report editor: StackEdit, hosted by this site.
 *
 * The <textarea data-editor-field> is the real form field and the only source
 * of truth — markdown in, markdown out, nothing converted in between. With
 * JavaScript it is hidden behind a rendered preview and a button that opens
 * StackEdit full-screen; without JavaScript it is a perfectly usable markdown
 * field on its own, which is why it carries the label and the name.
 *
 * @var MTL\Core\View $this
 * @var string $name        form field name
 * @var string $value       markdown
 * @var string $autosaveKey
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$name = (string) $this->get('name', 'body_md');
$value = (string) $this->get('value', '');
$autosaveKey = (string) $this->get('autosaveKey', '');
$label = (string) $this->get('label', __('step.report'));

// The preview is the published rendering, minus the heading permalink anchors
// that make no sense on a form.
$html = trim($value) === ''
    ? ''
    : MTL\Markdown\Markdown::render($value, MTL\Markdown\MarkdownOptions::editing());

$id = 'editor-' . preg_replace('/[^a-z0-9]/i', '', $name);
?>
<div class="mtl-editor"
     data-editor
     data-editor-stackedit="<?= e(asset('stackedit/index.html')) ?>"
     <?= $autosaveKey === '' ? '' : 'data-editor-autosave="' . e(path('/admin/api/autosave')) . '" data-editor-key="' . e($autosaveKey) . '"' ?>>

    <div class="mtl-editor__bar">
        <button type="button" class="p-button--positive mtl-editor__open" data-editor-open hidden>
            <?= icon('pencil', 16) ?> <?= e(__('js.editor.open')) ?>
        </button>

        <span class="mtl-editor__status">
            <span class="mtl-editor__status-dot is-saved" data-editor-dot></span>
            <span data-editor-status></span>
        </span>
    </div>

    <?php
    // The rendered report. Clicking it opens the editor too: on a phone the
    // text itself is the biggest target there is.
    ?>
    <div class="mtl-editor__preview mtl-prose"
         data-editor-preview
         data-placeholder="<?= e(__('js.editor.empty')) ?>"
         hidden><?= $html /* already rendered and escaped by the markdown renderer */ ?></div>

    <label class="mtl-visually-hidden" for="<?= e($id) ?>"><?= e($label) ?></label>
    <textarea class="mtl-editor__source"
              id="<?= e($id) ?>"
              name="<?= e($name) ?>"
              data-editor-field
              spellcheck="true"
              placeholder="<?= e(__('js.editor.placeholder')) ?>"><?= e($value) ?></textarea>
</div>
