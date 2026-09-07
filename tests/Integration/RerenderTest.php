<?php

declare(strict_types=1);

namespace MTL\Tests\Integration;

use MTL\Auth\Password;
use MTL\Console\ContentCommand;
use MTL\Console\Output;
use MTL\Core\Database;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Models\User;
use MTL\Services\StepService;
use MTL\Services\TripService;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * content:rerender brings stored HTML up to date with the current renderer.
 *
 * It touches two tables with different shapes — a step carries a derived
 * excerpt, a trip carries an author-written summary and no excerpt column —
 * and the first production run died on exactly that difference. Pinned here
 * against the real schema.
 */
final class RerenderTest extends TestCase
{
    private User $author;

    protected function setUp(): void
    {
        $db = Database::instance();

        foreach (['trip_collaborators', 'step_media', 'steps', 'trips', 'audit_log', 'search_index', 'users'] as $table) {
            $db->statement('DELETE FROM ' . Database::quoteIdentifier($table));
        }

        $this->author = User::create([
            'name'          => 'Schrijver',
            'email'         => 'schrijver@example.com',
            'password_hash' => Password::hash('reis-om-de-wereld-in-80-dagen'),
            'role'          => User::ROLE_AUTHOR,
            'status'        => 'active',
        ]);
    }

    private function runRerender(array $options = []): string
    {
        $stream = fopen('php://memory', 'w+');

        (new ContentCommand(new Output($stream)))->rerender($options);

        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function testStaleHtmlOnTripsAndStepsIsReRendered(): void
    {
        $trip = TripService::create([
            'title'   => 'Regel voor regel',
            'body_md' => "Eerste regel\nTweede regel",
        ], $this->author);

        $step = StepService::create($trip, [
            'title'   => 'Aankomst',
            'body_md' => "Regel een\nRegel twee",
        ], $this->author);

        // Simulate HTML stored by an older renderer, which folded a newline
        // into a space.
        Database::instance()->table('trips')->where('id', '=', $trip->id())
            ->update(['body_html' => '<p>Eerste regel Tweede regel</p>']);
        Database::instance()->table('steps')->where('id', '=', $step->id())
            ->update(['body_html' => '<p>Regel een Regel twee</p>', 'excerpt' => 'oud']);

        $output = $this->runRerender();

        $this->assertTrue(str_contains($output, 'Re-rendered 2 of 2'));

        $freshTrip = Trip::find($trip->id());
        $freshStep = Step::find($step->id());

        $this->assertTrue(str_contains($freshTrip->string('body_html'), '<br>'));
        $this->assertTrue(str_contains($freshStep->string('body_html'), '<br>'));

        // The step's excerpt is re-derived; the trip's summary is the
        // author's and stays exactly as written.
        $this->assertSame('Regel een Regel twee', $freshStep->string('excerpt'));
        $this->assertSame($trip->string('summary'), $freshTrip->string('summary'));
    }

    public function testDryRunWritesNothing(): void
    {
        $trip = TripService::create([
            'title'   => 'Droog',
            'body_md' => "Een\nTwee",
        ], $this->author);

        Database::instance()->table('trips')->where('id', '=', $trip->id())
            ->update(['body_html' => '<p>oud</p>']);

        $output = $this->runRerender(['dry-run' => true]);

        $this->assertTrue(str_contains($output, 'Would re-render 1 of 1'));
        $this->assertSame('<p>oud</p>', Trip::find($trip->id())->string('body_html'));
    }
}
