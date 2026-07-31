<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Auth\AuthManager;
use MTL\Core\Database;
use MTL\Core\QueryBuilder;
use MTL\Core\Request;
use MTL\Models\Model;
use MTL\Models\User;

defined('MTL_APP') || exit;

/**
 * The audit trail: an append-only record of who changed what.
 *
 * Entries survive the deletion of both the actor and the subject, which is
 * what makes them useful when something has gone wrong.
 */
final class AuditService
{
    /**
     * @param Model|null           $subject the record acted on
     * @param array<string,mixed>  $meta    context, e.g. changed fields
     */
    public static function log(string $action, ?Model $subject = null, array $meta = [], ?User $actor = null): void
    {
        try {
            $actor ??= AuthManager::instance()->user();
            $request = Request::current();

            Database::instance()->table('audit_log')->insert([
                'user_id'       => $actor?->id(),
                'user_label'    => $actor?->displayName() ?? 'systeem',
                'action'        => substr($action, 0, 80),
                'subject_type'  => $subject === null ? '' : self::typeOf($subject),
                'subject_id'    => $subject?->id(),
                'subject_label' => substr(self::labelOf($subject), 0, 191),
                'meta'          => $meta === [] ? null : (string) json_encode(
                    self::redact($meta),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
                ),
                'ip'            => $request?->ip() ?? '',
                'user_agent'    => substr($request?->userAgent() ?? '', 0, 255),
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // An audit failure must never take the actual operation down with
            // it; the log file records that the trail has a hole.
            logger()->error('Audit write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Records the difference between two versions of a record, keeping the
     * entry small by storing only the fields that actually changed.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function logChange(string $action, Model $subject, array $before, array $after): void
    {
        $changes = [];

        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;

            if ((string) $oldValue === (string) $newValue) {
                continue;
            }

            // Long text is summarised: the audit log records that the report
            // changed and by how much, not a second copy of it.
            $changes[$key] = [
                'from' => self::summarise($oldValue),
                'to'   => self::summarise($newValue),
            ];
        }

        if ($changes === []) {
            return;
        }

        self::log($action, $subject, ['changes' => $changes]);
    }

    private static function summarise(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > 120) {
            return '[' . strlen($value) . ' characters]';
        }

        if (is_array($value)) {
            return '[' . count($value) . ' items]';
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $meta
     *
     * @return array<string,mixed>
     */
    private static function redact(array $meta): array
    {
        foreach ($meta as $key => $value) {
            $lower = strtolower((string) $key);

            if (str_contains($lower, 'password') || str_contains($lower, 'secret') || str_contains($lower, 'token')) {
                $meta[$key] = '[redacted]';
            }
        }

        return $meta;
    }

    private static function typeOf(Model $subject): string
    {
        $short = substr($subject::class, strrpos($subject::class, '\\') + 1);

        return strtolower($short);
    }

    private static function labelOf(?Model $subject): string
    {
        if ($subject === null) {
            return '';
        }

        foreach (['title', 'name', 'email', 'original_name', 'slug'] as $field) {
            $value = $subject->string($field);

            if ($value !== '') {
                return $value;
            }
        }

        return '#' . $subject->id();
    }

    /**
     * @param array{action?:string,user_id?:int,subject_type?:string,from?:string,to?:string,search?:string} $filters
     */
    public static function query(array $filters = []): QueryBuilder
    {
        $query = Database::instance()->table('audit_log')->latest('created_at');

        if (($filters['action'] ?? '') !== '') {
            $query->whereLike('action', (string) $filters['action']);
        }

        if (($filters['user_id'] ?? 0) > 0) {
            $query->where('user_id', '=', (int) $filters['user_id']);
        }

        if (($filters['subject_type'] ?? '') !== '') {
            $query->where('subject_type', '=', (string) $filters['subject_type']);
        }

        if (($filters['from'] ?? '') !== '') {
            $query->where('created_at', '>=', (string) $filters['from']);
        }

        if (($filters['to'] ?? '') !== '') {
            $query->where('created_at', '<=', (string) $filters['to']);
        }

        if (($filters['search'] ?? '') !== '') {
            $term = (string) $filters['search'];
            $query->whereGroup(static function (QueryBuilder $q) use ($term): void {
                $q->whereLike('subject_label', $term)->whereLike('user_label', $term, 'OR');
            });
        }

        return $query;
    }

    /**
     * Trims the trail. Entries about users and settings are kept longer than
     * routine content edits, because they are the ones that matter months
     * later.
     */
    public static function prune(int $days = 365): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        return Database::instance()
            ->table('audit_log')
            ->where('created_at', '<', $cutoff)
            ->whereRaw("action NOT LIKE 'user.%' AND action NOT LIKE 'settings.%'")
            ->delete();
    }
}
