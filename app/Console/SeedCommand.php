<?php

declare(strict_types=1);

namespace MTL\Console;

use MTL\Core\Database;
use MTL\Models\Album;
use MTL\Models\Trip;
use MTL\Models\User;
use MTL\Services\AlbumService;
use MTL\Services\SearchService;
use MTL\Services\StepService;
use MTL\Services\TripService;

defined('MTL_APP') || exit;

/**
 * Demo content.
 *
 * Two real journeys with plausible coordinates and dates, which is what makes
 * the globe, the timeline and the data layers demonstrable on a fresh
 * installation. No media: those would have to be invented bytes, and an empty
 * gallery is more honest than a placeholder one.
 */
final class SeedCommand
{
    public function __construct(private readonly Output $out)
    {
    }

    /**
     * @param array<string,string|true> $options
     */
    public function demo(array $options): int
    {
        $author = User::fromRows(User::query()->where('role', '=', User::ROLE_ADMIN)->limit(1)->get())[0] ?? null;

        if ($author === null) {
            $this->out->error('Create an account first: php bin/console.php user:create');

            return 1;
        }

        if (Trip::query()->where('slug', '=', 'ijsland-in-de-winter')->exists() && !isset($options['force'])) {
            $this->out->info('The demo content is already there. Re-run with --force to add it again.');

            return 0;
        }

        Database::instance()->transaction(function () use ($author): void {
            $this->createIceland($author);
            $this->createPortugal($author);
        });

        SearchService::reindex();

        $this->out->success('Demo content created. Open the globe to see it.');

        return 0;
    }

    private function createIceland(User $author): void
    {
        $trip = TripService::create([
            'title'      => 'IJsland in de winter',
            'summary'    => 'Tien dagen langs de zuidkust, met noorderlicht boven Vík en een storm bij Jökulsárlón.',
            'body_md'    => <<<'MD'
                We vertrokken in februari, wat volgens iedereen het verkeerde moment is.
                Dat klopt ook: de wegen zijn onvoorspelbaar en het licht duurt maar vijf uur.

                Maar juist daardoor is het er stil.

                ## Wat we meenamen

                - Spikes onder de schoenen
                - Twee paar handschoenen, want één paar wordt altijd nat
                - Een thermoskan die de hele dag warm blijft
                MD,
            'start_date' => '2026-02-08',
            'end_date'   => '2026-02-18',
            'status'     => Trip::STATUS_PUBLISHED,
            'visibility' => Trip::VISIBILITY_PUBLIC,
            'color'      => '#4aa3df',
        ], $author);

        $steps = [
            ['Aankomst in Keflavík', 63.985, -22.6056, '2026-02-08 16:20:00', 'Keflavík', 'IS', 3, 4, -2.0,
                "De landing was de onrustigste die ik ooit heb meegemaakt. Buiten waaide het zo hard dat\nde deuren van de aankomsthal handmatig openhielden.\n\nEerste indruk: **donker**, en veel groter dan verwacht."],
            ['Þingvellir', 64.2559, -21.1300, '2026-02-09 11:00:00', 'Þingvellir', 'IS', 5, 5, -6.5,
                "Hier liep het Alþing vanaf het jaar 930. Je staat letterlijk tussen twee continenten:\nde Noord-Amerikaanse plaat aan de ene kant, de Euraziatische aan de andere.\n\n> Het is niet spectaculair op de manier van een waterval. Het is spectaculair\n> op de manier van een archief."],
            ['Gullfoss', 64.3271, -20.1199, '2026-02-09 14:30:00', 'Gullfoss', 'IS', 32, 5, -7.0,
                "Half bevroren. Het water valt tweemaal, in een hoek, in een kloof die je pas ziet\nals je er bijna in staat."],
            ['Seljalandsfoss', 63.6156, -19.9886, '2026-02-11 10:15:00', 'Seljalandsfoss', 'IS', 18, 4, -3.0,
                "Je kunt er in de zomer achterlangs lopen. In februari is het pad een ijsbaan en\nstaat er een hek."],
            ['Vík í Mýrdal', 63.4187, -19.0060, '2026-02-12 21:40:00', 'Vík', 'IS', 12, 5, -4.5,
                "Om tien voor tien stond het er ineens: groen, laag, en veel sneller bewegend dan\nop foto's. Twintig minuten, en toen trok de bewolking dicht.\n\nNiemand zei iets."],
            ['Jökulsárlón', 64.0784, -16.2306, '2026-02-14 13:00:00', 'Jökulsárlón', 'IS', 8, 5, -9.0,
                "De gletsjerlagune. Blokken ijs die traag naar zee drijven en dan op het zwarte\nstrand terugspoelen.\n\nDe storm kwam 's middags. We hebben twee uur in de auto gewacht."],
            ['Terug naar Reykjavík', 64.1466, -21.9426, '2026-02-17 17:00:00', 'Reykjavík', 'IS', 40, 4, -1.0,
                "Laatste avond. Zwembad, want dat is wat je hier doet: buiten in het warme water\nzitten terwijl het sneeuwt."],
        ];

        $this->createSteps($trip, $steps, $author);
    }

    private function createPortugal(User $author): void
    {
        $trip = TripService::create([
            'title'      => 'Langs de Rota Vicentina',
            'summary'    => 'Te voet van Porto Covo naar Odeceixe, over de kustpaden van de Alentejo.',
            'body_md'    => <<<'MD'
                Honderdveertig kilometer langs de kust, in acht dagen. De Fisherman's Trail
                loopt letterlijk over de klifrand, grotendeels door zand.

                Dat zand is het hele verhaal. Iedereen onderschat het.
                MD,
            'start_date' => '2025-09-14',
            'end_date'   => '2025-09-22',
            'status'     => Trip::STATUS_PUBLISHED,
            'visibility' => Trip::VISIBILITY_PUBLIC,
            'color'      => '#e8a33d',
        ], $author);

        $steps = [
            ['Porto Covo', 37.8517, -8.7906, '2025-09-14 09:00:00', 'Porto Covo', 'PT', 15, 4, 22.0,
                "Startpunt. Wit dorp, blauwe randen, en een bakker die om zeven uur opengaat."],
            ['Vila Nova de Milfontes', 37.7250, -8.7830, '2025-09-15 16:30:00', 'Vila Nova de Milfontes', 'PT', 10, 5, 24.0,
                "Twintig kilometer, waarvan zeker vijftien door los zand. De rivier oversteken\nmoet met een bootje; de veerman vaart als er genoeg mensen staan."],
            ['Almograve', 37.6522, -8.8018, '2025-09-16 15:00:00', 'Almograve', 'PT', 20, 4, 23.5,
                "Kortere dag. De kliffen worden hier hoger en de paden smaller."],
            ['Zambujeira do Mar', 37.5250, -8.7889, '2025-09-18 14:20:00', 'Zambujeira do Mar', 'PT', 45, 5, 25.0,
                "De mooiste etappe tot nu toe. Ooievaars die op de kliffen broeden — de enige\nplek ter wereld waar ze dat boven zee doen."],
            ['Odeceixe', 37.4386, -8.7739, '2025-09-20 12:00:00', 'Odeceixe', 'PT', 30, 5, 24.0,
                "Eindpunt. Het strand ligt in een riviermonding, met zoet water aan de ene kant\nen de Atlantische Oceaan aan de andere."],
        ];

        $this->createSteps($trip, $steps, $author);
    }

    /**
     * @param list<array{0:string,1:float,2:float,3:string,4:string,5:string,6:int,7:int,8:float,9:string}> $steps
     */
    private function createSteps(Trip $trip, array $steps, User $author): void
    {
        foreach ($steps as [$title, $latitude, $longitude, $occurred, $place, $country, $altitude, $rating, $temperature, $body]) {
            StepService::create($trip, [
                'title'         => $title,
                'body_md'       => $body,
                'latitude'      => $latitude,
                'longitude'     => $longitude,
                'altitude_m'    => $altitude,
                'location_name' => $place,
                'country_code'  => $country,
                'occurred_at'   => $occurred,
                'rating'        => $rating,
                'temperature_c' => $temperature,
                'status'        => 'published',
                'visibility'    => 'inherit',
            ], $author);
        }

        $this->out->success('Created "' . $trip->string('title') . '" with ' . count($steps) . ' stops.');

        AlbumService::create([
            'title'          => $trip->string('title') . ' — foto\'s',
            'trip_id'        => $trip->id(),
            'description_md' => 'Nog leeg: upload foto\'s vanuit de mediabibliotheek.',
            'status'         => 'published',
            'visibility'     => 'inherit',
            'layout'         => Album::LAYOUT_MASONRY,
        ], $author);
    }
}
