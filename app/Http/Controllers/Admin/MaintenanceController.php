<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Admin;

use MTL\Core\Assets;
use MTL\Core\Autoloader;
use MTL\Core\HttpException;
use MTL\Core\Migrator;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Models\Media;
use MTL\Models\Step;
use MTL\Models\Tag;
use MTL\Services\AuditService;
use MTL\Services\GeocodeService;
use MTL\Services\MaintenanceService;
use MTL\Services\MediaService;
use MTL\Services\SearchService;
use MTL\Services\StepService;

defined('MTL_APP') || exit;

/**
 * Maintenance tasks, runnable from the browser.
 *
 * Not every Strato plan offers SSH or cron, so everything the console can do
 * has to be reachable from here as well — otherwise a site could reach a state
 * only a shell could fix, on a host that has no shell.
 */
final class MaintenanceController extends Controller
{
    /**
     * Tasks, with a rough sense of what each costs. Anything marked slow warns
     * before it runs, because a shared host will kill a request that overruns.
     *
     * @var array<string,array{label:string,description:string,slow:bool}>
     */
    private const TASKS = [
        'cleanup' => [
            'label'       => 'maintenance.cleanup',
            'description' => 'Verwijdert verlopen tokens, afgebroken uploads, tijdelijke bestanden en oude aanvraaglimieten.',
            'slow'        => false,
        ],
        'recount' => [
            'label'       => 'maintenance.recount',
            'description' => 'Herberekent het aantal stops, foto\'s en labels per reis en album.',
            'slow'        => false,
        ],
        'reindex' => [
            'label'       => 'maintenance.reindex',
            'description' => 'Bouwt de zoekindex opnieuw op vanuit alle reizen, stops, albums en media.',
            'slow'        => true,
        ],
        'rebuild-media' => [
            'label'       => 'maintenance.rebuild_media',
            'description' => 'Genereert ontbrekende of mislukte afbeeldingsformaten opnieuw. Werkt in stappen van 25 bestanden.',
            'slow'        => true,
        ],
        'purge-trash' => [
            'label'       => 'media.trash',
            'description' => 'Verwijdert media die langer dan 30 dagen in de prullenbak staat definitief van de schijf.',
            'slow'        => true,
        ],
        'prune-tags' => [
            'label'       => 'nav.tags',
            'description' => 'Verwijdert labels die nergens meer aan hangen.',
            'slow'        => false,
        ],
        'geocode' => [
            'label'       => 'trip.geocode_all',
            'description' => 'Zoekt coördinaten voor alle stops die er geen hebben — uit de GPS-gegevens van hun foto\'s, hun locatie of hun titel. Meldt ook of de server Nominatim (OpenStreetMap) kan bereiken. Werkt in stappen van 40 stops.',
            'slow'        => true,
        ],
        'clear-cache' => [
            'label'       => 'maintenance.clear_cache',
            'description' => 'Leegt de klassenkaart en de asset-index; die worden bij de volgende aanvraag opnieuw opgebouwd.',
            'slow'        => false,
        ],
        'optimize' => [
            'label'       => 'admin.system',
            'description' => 'Bouwt de klassenkaart en de asset-index opnieuw op. Doe dit na elke upload van nieuwe bestanden.',
            'slow'        => false,
        ],
        'migrate' => [
            'label'       => 'admin.system',
            'description' => 'Voert openstaande databasemigraties uit.',
            'slow'        => false,
        ],
    ];

    public function index(Request $request): Response
    {
        $migrator = new Migrator(db());

        return view('admin/maintenance', [
            'title'    => __('maintenance.maintenance'),
            'noindex'  => true,
            'tasks'    => self::TASKS,
            'health'   => MaintenanceService::healthReport(),
            'migrations' => $migrator->status(),
            'failedMedia' => Media::active()->where('status', '=', 'failed')->count(),
            'trashCount'  => Media::query()->whereNotNull('deleted_at')->count(),
        ]);
    }

    public function run(Request $request): Response
    {
        $task = (string) $request->param('task', '');

        if (!isset(self::TASKS[$task])) {
            throw HttpException::notFound();
        }

        $started = microtime(true);

        $result = match ($task) {
            'cleanup'       => MaintenanceService::runAll(),
            'recount'       => ['rows' => MaintenanceService::recalculateCounters()],
            'reindex'       => ['entries' => SearchService::reindex()],
            'rebuild-media' => $this->rebuildMedia(),
            'purge-trash'   => ['removed' => MediaService::purgeTrash((int) $request->int('days', 30))],
            'prune-tags'    => ['removed' => Tag::pruneUnused()],
            'geocode'       => $this->geocodeSteps(),
            'clear-cache'   => $this->clearCache(),
            'optimize'      => [
                'classes' => Autoloader::buildClassmap(),
                'assets'  => Assets::buildManifest(),
            ],
            'migrate'       => ['applied' => (new Migrator(db()))->migrate()],
            default         => [],
        };

        $elapsed = (int) round((microtime(true) - $started) * 1000);

        AuditService::log('maintenance.' . $task, null, $result + ['ms' => $elapsed]);

        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'task' => $task, 'result' => $result, 'ms' => $elapsed]);
        }

        return $this->back(
            path('/admin/maintenance'),
            __('maintenance.done') . ' (' . $this->summarise($result) . ', ' . $elapsed . ' ms)'
        );
    }

    /**
     * Regenerates image variants in batches.
     *
     * A site with thousands of photos cannot rebuild them all inside one
     * request, so this does twenty-five at a time and reports how many are
     * left; the screen offers to run it again.
     *
     * @return array<string,int>
     */
    private function rebuildMedia(): array
    {
        $rows = Media::active()
            ->where('kind', '=', 'image')
            ->whereGroup(static function ($q): void {
                $q->where('status', '=', 'failed')->orWhereNull('variants');
            })
            ->limit(25)
            ->get();

        $rebuilt = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if (MediaService::rebuildVariants(Media::fromRow($row))) {
                ++$rebuilt;
            } else {
                ++$failed;
            }
        }

        $remaining = Media::active()
            ->where('kind', '=', 'image')
            ->whereGroup(static function ($q): void {
                $q->where('status', '=', 'failed')->orWhereNull('variants');
            })
            ->count();

        return ['rebuilt' => $rebuilt, 'failed' => $failed, 'remaining' => $remaining];
    }

    /**
     * Places stops without coordinates, in batches like the media rebuild.
     *
     * Also the diagnostic for "no place name ever resolves": the result names
     * whether Nominatim could be reached at all, which separates "the server
     * cannot make outbound requests" from "these names are not places".
     *
     * @return array<string,int|string>
     */
    private function geocodeSteps(): array
    {
        @set_time_limit(300);

        $reachable = GeocodeService::reachable();

        $unplaced = static fn () => Step::active()
            ->whereNull('latitude')
            ->orderBy('trip_id')
            ->orderBy('position');

        $placed = 0;
        $left = 0;

        foreach (Step::fromRows($unplaced()->limit(40)->get()) as $step) {
            if (StepService::place($step)) {
                ++$placed;
            } else {
                ++$left;
            }
        }

        return [
            'nominatim' => $reachable ? 'bereikbaar' : 'NIET bereikbaar',
            'placed'    => $placed,
            'not_found' => $left,
            'remaining' => $unplaced()->count(),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function clearCache(): array
    {
        $removed = 0;

        foreach (glob(storage_path('cache/*')) ?: [] as $file) {
            if (is_file($file) && basename($file) !== '.gitkeep' && @unlink($file)) {
                ++$removed;
            }
        }

        \MTL\Services\SettingsService::flush();

        return ['files' => $removed];
    }

    /**
     * @param array<string,mixed> $result
     */
    private function summarise(array $result): string
    {
        $parts = [];

        foreach ($result as $key => $value) {
            $parts[] = $key . ': ' . (is_array($value) ? count($value) : (string) $value);
        }

        return $parts === [] ? 'klaar' : implode(', ', $parts);
    }
}
