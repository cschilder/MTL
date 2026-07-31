<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Admin;

use MTL\Core\Logger;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Models\User;
use MTL\Services\AuditService;

defined('MTL_APP') || exit;

/**
 * The audit trail and the error log.
 */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'action'       => $request->string('action'),
            'user_id'      => $request->int('user'),
            'subject_type' => $request->string('type'),
            'search'       => $request->string('q'),
            'from'         => $request->string('from'),
            'to'           => $request->string('to'),
        ];

        $result = AuditService::query($filters)->paginate($this->page($request), 50);

        return view('admin/audit', [
            'title'      => __('audit.audit'),
            'noindex'    => true,
            'entries'    => $result['data'],
            'pagination' => $result,
            'filters'    => $filters,
            'users'      => User::fromRows(User::query()->orderBy('name')->limit(200)->get()),
            'actions'    => $this->distinctActions(),
        ]);
    }

    /**
     * The application's own error log.
     *
     * Shared hosting gives no access to the server's logs, so this is the only
     * place a diagnosis can start from when something goes wrong in production.
     */
    public function logs(Request $request): Response
    {
        $logger = Logger::instance();

        $dates = $logger->availableDates();
        $date = $request->string('date');

        if (!in_array($date, $dates, true)) {
            $date = $dates[0] ?? gmdate('Y-m-d');
        }

        $lines = $logger->tail(400, $date);

        $level = $request->string('level');

        if ($level !== '') {
            $lines = array_values(array_filter(
                $lines,
                static fn (string $line): bool => str_contains($line, '] ' . strtoupper($level) . ':')
            ));
        }

        return view('admin/logs', [
            'title'   => __('audit.error_log'),
            'noindex' => true,
            'lines'   => $lines,
            'dates'   => $dates,
            'date'    => $date,
            'level'   => $level,
        ]);
    }

    /**
     * @return list<string>
     */
    private function distinctActions(): array
    {
        $rows = db()->table('audit_log')
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->limit(200)
            ->get();

        return array_map(static fn (array $row): string => (string) $row['action'], $rows);
    }
}
