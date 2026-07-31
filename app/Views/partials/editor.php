<?php
/**
 * The travel-report editor.
 *
 * The <textarea data-editor-field> is the real form field: everything else is
 * an editing surface over it. Without JavaScript that textarea is a perfectly
 * usable markdown editor on its own, which is why it is the element the form
 * actually submits.
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

/**
 * The seed for the rich surface is rendered here from the markdown, rather than
 * taken from the stored body_html.
 *
 * Two reasons. The stored HTML is the *published* rendering, which carries the
 * heading permalink anchors — and the editor serialises its surface back to
 * markdown, so those anchors would be saved as `[#](#kop)` links the author
 * never wrote, one more on every save. And after a failed validation the form
 * redisplays the submitted markdown, which the stored HTML no longer matches.
 */
$html = trim($value) === ''
    ? ''
    : MTL\Markdown\Markdown::render($value, MTL\Markdown\MarkdownOptions::editing());
$label = (string) $this->get('label', __('step.report'));

$mode = $this->get('currentUser')?->preference('editor.mode', 'rich') ?? 'rich';

$id = 'editor-' . preg_replace('/[^a-z0-9]/i', '', $name);

/**
 * The toolbar, in Slack's order: text styles, then lists, then blocks, then
 * insertions.
 *
 * [command, value, icon, label key, keyboard hint]
 */
$groups = [
    [
        ['bold', '', 'bold', 'js.editor.bold', '⌘B'],
        ['italic', '', 'italic', 'js.editor.italic', '⌘I'],
        ['strikeThrough', '', 'strike', 'js.editor.strike', '⌘⇧X'],
        ['code', '', 'code', 'js.editor.code', '⌘E'],
    ],
    [
        ['link', '', 'link', 'js.editor.link', '⌘K'],
        ['quote', '', 'quote', 'js.editor.quote', '⌘⇧9'],
        ['insertUnorderedList', '', 'list-bullet', 'js.editor.bullet_list', '⌘⇧8'],
        ['insertOrderedList', '', 'list-ordered', 'js.editor.ordered_list', '⌘⇧7'],
        ['taskList', '', 'list-task', 'js.editor.task_list', ''],
    ],
    [
        ['heading', '1', 'heading', 'js.editor.heading', ''],
        ['codeBlock', '', 'code-block', 'js.editor.code_block', ''],
        ['table', '', 'table', 'js.editor.table', ''],
        ['divider', '', 'divider', 'js.editor.divider', ''],
    ],
    [
        ['image', '', 'image', 'js.editor.image', ''],
    ],
];
?>
<div class="mtl-editor"
     data-editor
     data-editor-mode="<?= e($mode) ?>"
     <?= $autosaveKey === '' ? '' : 'data-editor-autosave="' . e(path('/admin/api/autosave')) . '" data-editor-key="' . e($autosaveKey) . '"' ?>>

    <div class="mtl-editor__toolbar" role="toolbar" aria-label="<?= e($label) ?>" aria-controls="<?= e($id) ?>">
        <?php foreach ($groups as $group): ?>
            <div class="mtl-editor__tool-group">
                <?php foreach ($group as [$command, $value_, $iconName, $labelKey, $hint]): ?>
                    <?php $title = __($labelKey) . ($hint === '' ? '' : ' (' . $hint . ')'); ?>
                    <button type="button"
                            class="mtl-editor__tool"
                            data-command="<?= e($command) ?>"
                            <?= $value_ === '' ? '' : 'data-command-value="' . e($value_) . '"' ?>
                            aria-pressed="false"
                            title="<?= e($title) ?>"
                            aria-label="<?= e($title) ?>">
                        <?= icon($iconName, 18) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <?php // Mode switch, pushed to the far end. ?>
        <div class="mtl-editor__tool-group" style="margin-inline-start: auto;">
            <button type="button" class="mtl-editor__tool" data-editor-mode-button="rich"
                    aria-pressed="<?= $mode === 'rich' ? 'true' : 'false' ?>"
                    title="<?= e(__('js.editor.rich')) ?>" aria-label="<?= e(__('js.editor.rich')) ?>">
                <?= icon('eye', 18) ?>
            </button>
            <button type="button" class="mtl-editor__tool" data-editor-mode-button="source"
                    aria-pressed="<?= $mode === 'source' ? 'true' : 'false' ?>"
                    title="<?= e(__('js.editor.source')) ?>" aria-label="<?= e(__('js.editor.source')) ?>">
                <?= icon('markdown', 18) ?>
            </button>
            <button type="button" class="mtl-editor__tool" data-editor-mode-button="preview"
                    aria-pressed="false"
                    title="<?= e(__('js.editor.preview')) ?>" aria-label="<?= e(__('js.editor.preview')) ?>">
                <?= icon('photo-stack', 18) ?>
            </button>
        </div>
    </div>

    <?php // The rich surface. Seeded from the server-rendered HTML below. ?>
    <div class="mtl-editor__surface mtl-prose"
         data-editor-surface
         data-placeholder="<?= e(__('js.editor.placeholder')) ?>"
         hidden></div>

    <?php
    // The real field. Visible and fully usable when JavaScript never runs,
    // which is why it carries the label and the name.
    ?>
    <label class="mtl-visually-hidden" for="<?= e($id) ?>"><?= e($label) ?></label>
    <textarea class="mtl-editor__source"
              id="<?= e($id) ?>"
              name="<?= e($name) ?>"
              data-editor-field
              data-editor-source
              spellcheck="true"
              placeholder="<?= e(__('js.editor.placeholder')) ?>"><?= e($value) ?></textarea>

    <div class="mtl-editor__preview mtl-prose" data-editor-preview hidden></div>

    <div class="mtl-editor__status">
        <span>
            <span class="mtl-editor__status-dot is-saved" data-editor-dot></span>
            <span data-editor-status></span>
        </span>
    </div>

    <?php
    // The rendered HTML the rich surface starts from. A <template> so the
    // markup is inert until the editor copies it across, and so it does not
    // appear twice for a reader without JavaScript.
    ?>
    <template data-editor-initial><?= $html /* already rendered and escaped by the markdown renderer */ ?></template>
</div>
