<?php
/**
 * The audit trail.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var list<array<string,mixed>> $entries */
$entries = $this->get('entries', []);
/** @var list<MTL\Models\User> $users */
$users = $this->get('users', []);
/** @var list<string> $actions */
$actions = $this->get('actions', []);
/** @var array<string,mixed> $filters */
$filters = $this->get('filters', []);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('audit.audit')) ?></h1>

    <div class="mtl-page-head__actions">
        <a class="p-button" href="<?= e(path('/admin/audit/logs')) ?>">
            <?= icon('warning', 16) ?> <?= e(__('audit.error_log')) ?>
        </a>
    </div>
</div>

<form method="get" class="mtl-row" style="margin-block-end: var(--mtl-space-4);">
    <label class="mtl-visually-hidden" for="filter-q"><?= e(__('app.search')) ?></label>
    <input type="search" id="filter-q" name="q" value="<?= e((string) ($filters['search'] ?? '')) ?>"
           placeholder="<?= e(__('app.search')) ?>" style="margin: 0; max-inline-size: 14rem;">

    <label class="mtl-visually-hidden" for="filter-action"><?= e(__('audit.what')) ?></label>
    <select id="filter-action" name="action" style="margin: 0; max-inline-size: 14rem;">
        <option value=""><?= e(__('app.all')) ?></option>
        <?php foreach ($actions as $action): ?>
            <option value="<?= e($action) ?>" <?= ($filters['action'] ?? '') === $action ? 'selected' : '' ?>>
                <?= e($action) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="mtl-visually-hidden" for="filter-user"><?= e(__('audit.who')) ?></label>
    <select id="filter-user" name="user" style="margin: 0; max-inline-size: 14rem;">
        <option value=""><?= e(__('app.all')) ?></option>
        <?php foreach ($users as $user): ?>
            <option value="<?= e((string) $user->id()) ?>" <?= (int) ($filters['user_id'] ?? 0) === $user->id() ? 'selected' : '' ?>>
                <?= e($user->displayName()) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="p-button" style="margin: 0;"><?= e(__('app.search')) ?></button>
</form>

<?php if ($entries === []): ?>
    <p class="mtl-empty"><?= e(__('audit.empty')) ?></p>
<?php else: ?>
    <div class="mtl-table-wrap">
        <table class="mtl-table mtl-table--stack">
            <thead>
                <tr>
                    <th><?= e(__('audit.when')) ?></th>
                    <th><?= e(__('audit.who')) ?></th>
                    <th><?= e(__('audit.what')) ?></th>
                    <th><?= e(__('audit.subject')) ?></th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td data-label="<?= e(__('audit.when')) ?>">
                            <?= e(date('j M Y, H:i', (int) strtotime((string) $entry['created_at'] . ' UTC'))) ?>
                        </td>
                        <td data-label="<?= e(__('audit.who')) ?>"><?= e((string) $entry['user_label']) ?></td>
                        <td data-label="<?= e(__('audit.what')) ?>"><code><?= e((string) $entry['action']) ?></code></td>
                        <td data-label="<?= e(__('audit.subject')) ?>">
                            <?= e((string) $entry['subject_label']) ?>

                            <?php if (($entry['meta'] ?? null) !== null): ?>
                                <details>
                                    <summary class="mtl-muted"><?= e(__('app.more')) ?></summary>
                                    <pre style="font-size: 0.75rem;"><code><?= e((string) $entry['meta']) ?></code></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td data-label="IP"><span class="mtl-muted"><?= e((string) $entry['ip']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->include('partials/pagination') ?>
<?php endif; ?>

<?php $this->end() ?>
