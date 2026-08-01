<?php

declare(strict_types=1);

namespace MTL\Tests\Integration;

use MTL\Auth\AuthManager;
use MTL\Auth\Password;
use MTL\Core\Database;
use MTL\Models\Trip;
use MTL\Models\User;
use MTL\Services\GeocodeService;
use MTL\Services\StepService;
use MTL\Services\TripService;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * Place names become coordinates.
 *
 * Nobody knows latitudes by heart: "Rotterdam, Schiphol, Edinburgh" is how a
 * journey is typed in. These tests pin down the geocoder — parsing, the disk
 * cache, and the rule that a network failure is never cached — and the save
 * path that fills a stop's coordinates from its place name. The network is
 * never touched: every test hands GeocodeService a canned transport.
 */
final class GeocodeTest extends TestCase
{
    private User $author;

    /** A plausible Nominatim answer for "Rotterdam". */
    private const ROTTERDAM = '[{"place_id":1,"lat":"51.9244201","lon":"4.4777325","name":"Rotterdam",'
        . '"display_name":"Rotterdam, Zuid-Holland, Nederland","type":"city",'
        . '"address":{"country_code":"nl"}}]';

    protected function setUp(): void
    {
        $db = Database::instance();

        foreach (['step_media', 'steps', 'trips', 'audit_log', 'search_index', 'users'] as $table) {
            $db->statement('DELETE FROM ' . Database::quoteIdentifier($table));
        }

        self::clearGeocodeCache();

        $this->author = User::create([
            'name'              => 'Reiziger',
            'email'             => 'reiziger@example.com',
            'password_hash'     => Password::hash('reis-door-de-wereld-2026'),
            'role'              => User::ROLE_AUTHOR,
            'status'            => 'active',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $auth = new AuthManager();
        $auth->setUser($this->author);
        AuthManager::swap($auth);
    }

    protected function tearDown(): void
    {
        GeocodeService::swapTransport(null);
        AuthManager::swap(null);
        self::clearGeocodeCache();
    }

    private static function clearGeocodeCache(): void
    {
        foreach (glob(storage_path('cache/geocode/*.json')) ?: [] as $file) {
            @unlink($file);
        }
    }

    private function makeTrip(): Trip
    {
        return TripService::create([
            'title'      => 'Nederland en Schotland',
            'body_md'    => '',
            'status'     => Trip::STATUS_PUBLISHED,
            'visibility' => Trip::VISIBILITY_PUBLIC,
        ], $this->author);
    }

    // -------------------------------------------------------------------------
    // The service itself
    // -------------------------------------------------------------------------

    public function testSearchParsesANominatimAnswer(): void
    {
        GeocodeService::swapTransport(static fn (): string => self::ROTTERDAM);

        $results = GeocodeService::search('Rotterdam');

        $this->assertCount(1, $results);
        $this->assertSame('Rotterdam', $results[0]['name']);
        $this->assertSame(51.9244201, $results[0]['latitude']);
        $this->assertSame(4.4777325, $results[0]['longitude']);
        $this->assertSame('NL', $results[0]['country']);
        $this->assertSame('Rotterdam, Zuid-Holland, Nederland', $results[0]['display']);
    }

    public function testAnswersAreCachedSoTheServiceIsAskedOnce(): void
    {
        $calls = 0;

        GeocodeService::swapTransport(static function () use (&$calls): string {
            $calls++;

            return self::ROTTERDAM;
        });

        GeocodeService::search('Rotterdam');

        // Second lookup: the transport now fails hard. The cache must answer.
        GeocodeService::swapTransport(static fn (): ?string => null);

        $results = GeocodeService::search('Rotterdam');

        $this->assertSame(1, $calls);
        $this->assertSame('Rotterdam', $results[0]['name'] ?? null);
    }

    public function testAFailedFetchIsNotCached(): void
    {
        GeocodeService::swapTransport(static fn (): ?string => null);

        $this->assertSame([], GeocodeService::search('Rotterdam'));

        // The network comes back: the same query must try again and succeed.
        GeocodeService::swapTransport(static fn (): string => self::ROTTERDAM);

        $this->assertCount(1, GeocodeService::search('Rotterdam'));
    }

    public function testAnEmptyAnswerIsCachedToo(): void
    {
        $calls = 0;

        GeocodeService::swapTransport(static function () use (&$calls): string {
            $calls++;

            return '[]';
        });

        GeocodeService::search('Atlantis');
        GeocodeService::search('Atlantis');

        // "No such place" is an answer; retrying it would hammer the service.
        $this->assertSame(1, $calls);
    }

    // -------------------------------------------------------------------------
    // The save path: a place name is enough
    // -------------------------------------------------------------------------

    public function testSavingAStopWithOnlyAPlaceNameFillsItsCoordinates(): void
    {
        GeocodeService::swapTransport(static fn (): string => self::ROTTERDAM);

        $step = StepService::create($this->makeTrip(), [
            'title'         => 'Aankomst',
            'body_md'       => '',
            'status'        => 'published',
            'location_name' => 'Rotterdam',
        ], $this->author);

        $this->assertSame(51.9244201, $step->latitude());
        $this->assertSame(4.4777325, $step->longitude());
        $this->assertSame('NL', $step->string('country_code'));
    }

    public function testExplicitCoordinatesAreNeverOverwritten(): void
    {
        GeocodeService::swapTransport(static function (): string {
            throw new \RuntimeException('the geocoder must not be asked');
        });

        $step = StepService::create($this->makeTrip(), [
            'title'         => 'Eigen plek',
            'body_md'       => '',
            'status'        => 'published',
            'location_name' => 'Rotterdam',
            'latitude'      => 63.985,
            'longitude'     => -22.6056,
        ], $this->author);

        $this->assertSame(63.985, $step->latitude());
        $this->assertSame(-22.6056, $step->longitude());
    }

    public function testUpdatingAnUnplacedStopWithANewNameGeocodesIt(): void
    {
        // Created while the geocoder was down: the stop has a name, no place.
        GeocodeService::swapTransport(static fn (): ?string => null);

        $step = StepService::create($this->makeTrip(), [
            'title'         => 'Onderweg',
            'body_md'       => '',
            'status'        => 'published',
            'location_name' => 'Edinburgh',
        ], $this->author);

        $this->assertNull($step->latitude());

        // The author corrects the name; the lookup now succeeds.
        GeocodeService::swapTransport(static fn (): string => self::ROTTERDAM);

        $step = StepService::update($step, ['location_name' => 'Rotterdam']);

        $this->assertSame(51.9244201, $step->latitude());
        $this->assertSame(4.4777325, $step->longitude());
    }

    public function testResavingTheSameUnresolvedNameIsNotARetryLoop(): void
    {
        GeocodeService::swapTransport(static fn (): ?string => null);

        $step = StepService::create($this->makeTrip(), [
            'title'         => 'Onvindbaar',
            'body_md'       => '',
            'status'        => 'published',
            'location_name' => 'Ergens',
        ], $this->author);

        // A working geocoder and an empty cache — but the name did not change,
        // so saving unrelated edits must not fire a lookup.
        self::clearGeocodeCache();
        GeocodeService::swapTransport(static fn (): string => self::ROTTERDAM);

        $step = StepService::update($step, [
            'title'         => 'Nog steeds onvindbaar',
            'location_name' => 'Ergens',
        ]);

        $this->assertNull($step->latitude());
        $this->assertNull($step->longitude());
    }

    public function testAnExistingCountryCodeIsKept(): void
    {
        GeocodeService::swapTransport(static fn (): string => self::ROTTERDAM);

        $step = StepService::create($this->makeTrip(), [
            'title'         => 'Grensgeval',
            'body_md'       => '',
            'status'        => 'published',
            'location_name' => 'Rotterdam',
            'country_code'  => 'BE',
        ], $this->author);

        // Coordinates are filled in, the author's own country choice is not
        // second-guessed.
        $this->assertSame(51.9244201, $step->latitude());
        $this->assertSame('BE', $step->string('country_code'));
    }
}
