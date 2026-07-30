<?php

declare(strict_types=1);

namespace MTL\Http\Controllers;

use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Services\SettingsService;
use MTL\Services\TripService;

defined('MTL_APP') || exit;

/**
 * Feeds, crawler files and the web app manifest.
 *
 * Everything here is public by definition, so only published, publicly visible
 * content is ever included — the visitor's own drafts must not leak into a
 * cached feed.
 */
final class FeedController extends Controller
{
    private const FEED_LIMIT = 40;

    public function rss(Request $request): Response
    {
        $entries = $this->recentEntries();

        $title = SettingsService::string('site.title', 'MTL');
        $tagline = SettingsService::string('site.tagline');

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');

        $xml->startElement('channel');
        $xml->writeElement('title', $title);
        $xml->writeElement('link', url('/'));
        $xml->writeElement('description', $tagline);
        $xml->writeElement('language', SettingsService::string('site.language', 'nl'));
        $xml->writeElement('lastBuildDate', gmdate('D, d M Y H:i:s') . ' GMT');

        $xml->startElement('atom:link');
        $xml->writeAttribute('href', url('/feed.xml'));
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('type', 'application/rss+xml');
        $xml->endElement();

        foreach ($entries as $entry) {
            $xml->startElement('item');
            $xml->writeElement('title', $entry['title']);
            $xml->writeElement('link', $entry['url']);
            $xml->writeElement('description', $entry['summary']);
            $xml->writeElement('pubDate', gmdate('D, d M Y H:i:s', $entry['timestamp']) . ' GMT');

            $xml->startElement('guid');
            $xml->writeAttribute('isPermaLink', 'true');
            $xml->text($entry['url']);
            $xml->endElement();

            $xml->endElement();
        }

        $xml->endElement(); // channel
        $xml->endElement(); // rss
        $xml->endDocument();

        return Response::xml($xml->outputMemory())
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=1800');
    }

    /**
     * JSON Feed 1.1, which is easier to consume than RSS and costs a few lines.
     */
    public function json(Request $request): Response
    {
        $entries = $this->recentEntries();

        return Response::json([
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => SettingsService::string('site.title', 'MTL'),
            'description'   => SettingsService::string('site.tagline'),
            'home_page_url' => url('/'),
            'feed_url'      => url('/feed.json'),
            'language'      => SettingsService::string('site.language', 'nl'),
            'items'         => array_map(static fn (array $entry): array => [
                'id'             => $entry['url'],
                'url'            => $entry['url'],
                'title'          => $entry['title'],
                'summary'        => $entry['summary'],
                'content_text'   => $entry['summary'],
                'date_published' => gmdate('c', $entry['timestamp']),
                'image'          => $entry['image'] ?: null,
            ], $entries),
        ])->header('Cache-Control', 'public, max-age=1800');
    }

    /**
     * Everything published, for a crawler.
     */
    public function sitemap(Request $request): Response
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $write = static function (string $location, ?string $modified, string $frequency, string $priority) use ($xml): void {
            $xml->startElement('url');
            $xml->writeElement('loc', $location);

            if ($modified !== null) {
                $xml->writeElement('lastmod', $modified);
            }

            $xml->writeElement('changefreq', $frequency);
            $xml->writeElement('priority', $priority);
            $xml->endElement();
        };

        $write(url('/'), null, 'daily', '1.0');
        $write(url('/trips'), null, 'weekly', '0.8');
        $write(url('/albums'), null, 'weekly', '0.6');

        // Only public, published trips: an unlisted one is reachable by link
        // and must stay out of an index.
        $trips = Trip::active()
            ->where('status', '=', Trip::STATUS_PUBLISHED)
            ->where('visibility', '=', Trip::VISIBILITY_PUBLIC)
            ->orderBy('updated_at', 'DESC')
            ->limit(2000)
            ->get();

        foreach ($trips as $row) {
            $trip = Trip::fromRow($row);

            $write(url($trip->url()), $trip->date('updated_at')?->format('Y-m-d'), 'monthly', '0.9');

            $steps = Step::active()
                ->where('trip_id', '=', $trip->id())
                ->where('status', '=', Step::STATUS_PUBLISHED)
                ->where('visibility', '!=', 'private')
                ->orderBy('position')
                ->get();

            foreach ($steps as $stepRow) {
                $step = Step::fromRow($stepRow);

                $write(url($step->url($trip)), $step->date('updated_at')?->format('Y-m-d'), 'monthly', '0.7');
            }
        }

        $xml->endElement();
        $xml->endDocument();

        return Response::xml($xml->outputMemory())->header('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $request): Response
    {
        $lines = ['User-agent: *'];

        if (!SettingsService::bool('site.public', true)) {
            // The whole site is private; ask crawlers to stay out entirely.
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Disallow: /admin';
            $lines[] = 'Disallow: /login';
            $lines[] = 'Disallow: /search';
            $lines[] = 'Disallow: /s/';
            $lines[] = 'Allow: /';
            $lines[] = '';
            $lines[] = 'Sitemap: ' . url('/sitemap.xml');
        }

        return Response::text(implode("\n", $lines) . "\n")
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * The web app manifest, which is what makes the site installable and what
     * the Android wrapper reads.
     */
    public function manifest(Request $request): Response
    {
        $name = SettingsService::string('site.title', 'MTL');
        $accent = SettingsService::string('site.accent', '#0f7d5c');

        return Response::json([
            'name'             => $name,
            'short_name'       => mb_substr($name, 0, 12, 'UTF-8'),
            'description'      => SettingsService::string('site.tagline'),
            'start_url'        => path('/'),
            'scope'            => path('/'),
            'display'          => 'standalone',
            'display_override' => ['window-controls-overlay', 'standalone'],
            'orientation'      => 'any',
            'background_color' => '#060a14',
            'theme_color'      => $accent,
            'lang'             => \MTL\Core\Translator::locale(),
            'dir'              => 'ltr',
            'categories'       => ['travel', 'photo', 'lifestyle'],
            'icons'            => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => asset('icons/favicon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml'],
            ],
            'shortcuts' => [
                [
                    'name' => __('nav.trips'),
                    'url'  => path('/trips'),
                    'icons' => [['src' => asset('icons/icon-192.png'), 'sizes' => '192x192']],
                ],
                [
                    'name' => __('nav.albums'),
                    'url'  => path('/albums'),
                    'icons' => [['src' => asset('icons/icon-192.png'), 'sizes' => '192x192']],
                ],
            ],
        ])->header('Content-Type', 'application/manifest+json; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Digital asset links, which is how Android verifies that the app and this
     * site belong to the same owner.
     *
     * Without a valid file here, the wrapper still runs but falls back to a
     * Custom Tab with a visible address bar rather than a full-screen app.
     *
     * The values are settings rather than a static file so the signing
     * fingerprint can be pasted in from the management environment after the
     * APK is built, without another upload.
     */
    public function assetLinks(Request $request): Response
    {
        $package = SettingsService::string('android.package_name');
        $fingerprints = array_values(array_filter(array_map(
            'trim',
            explode(',', SettingsService::string('android.sha256_fingerprints'))
        )));

        if ($package === '' || $fingerprints === []) {
            // An empty list is valid JSON and the correct answer: no app is
            // currently associated with this domain.
            return Response::json([])
                ->header('Content-Type', 'application/json')
                ->header('Cache-Control', 'public, max-age=300');
        }

        return Response::json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target'   => [
                'namespace'                => 'android_app',
                'package_name'             => $package,
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]])->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    // -------------------------------------------------------------------------

    /**
     * The most recent published steps, newest first.
     *
     * Steps rather than trips: a trip is published once and then grows, so a
     * feed of trips would go quiet exactly when there is most to read.
     *
     * @return list<array{title:string,url:string,summary:string,timestamp:int,image:string}>
     */
    private function recentEntries(): array
    {
        $tripIds = TripService::visibleQuery(null)
            ->where('status', '=', Trip::STATUS_PUBLISHED)
            ->where('visibility', '=', Trip::VISIBILITY_PUBLIC)
            ->pluck('id');

        if ($tripIds === []) {
            return [];
        }

        $rows = Step::active()
            ->whereIn('trip_id', array_map('intval', $tripIds))
            ->where('status', '=', Step::STATUS_PUBLISHED)
            ->where('visibility', '!=', 'private')
            ->orderByRaw('COALESCE(`published_at`, `occurred_at`, `created_at`) DESC')
            ->limit(self::FEED_LIMIT)
            ->get();

        $trips = [];
        $entries = [];

        foreach ($rows as $row) {
            $step = Step::fromRow($row);

            $tripId = $step->int('trip_id');
            $trips[$tripId] ??= Trip::find($tripId);

            $trip = $trips[$tripId];

            if ($trip === null) {
                continue;
            }

            $cover = $step->coverMedia();

            $timestamp = $step->date('published_at')?->getTimestamp()
                ?? $step->date('occurred_at')?->getTimestamp()
                ?? $step->date('created_at')?->getTimestamp()
                ?? time();

            $entries[] = [
                'title'     => $step->string('title'),
                'url'       => url($step->url($trip)),
                'summary'   => $step->excerpt(400),
                'timestamp' => $timestamp,
                'image'     => $cover === null ? '' : url($cover->url('medium')),
            ];
        }

        return $entries;
    }
}
