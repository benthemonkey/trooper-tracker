<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders\MidwestGarrison;

use App\Models\Event;
use App\Models\EventOrganization;
use App\Models\Organization;
use Database\Seeders\MidwestGarrison\GalacticAcademyEventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalacticAcademyEventSeederTest extends TestCase
{
    use RefreshDatabase;

    private Organization $academy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academy = Organization::factory()->asOrganization()->create([Organization::NAME => 'Galactic Academy']);
    }

    public function test_every_event_opens_to_galactic_academy_without_a_limit(): void
    {
        $events = Event::factory()->count(2)->create();

        $this->seed(GalacticAcademyEventSeeder::class);

        foreach ($events as $event)
        {
            $this->assertDatabaseHas('tt_event_organizations', [
                EventOrganization::EVENT_ID => $event->id,
                EventOrganization::ORGANIZATION_ID => $this->academy->id,
                EventOrganization::CAN_ATTEND => true,
                EventOrganization::TROOPERS_ALLOWED => null,
                EventOrganization::HANDLERS_ALLOWED => null,
            ]);
        }
    }

    public function test_an_event_that_already_has_a_galactic_academy_row_keeps_it(): void
    {
        $event = Event::factory()->create();
        EventOrganization::factory()->forEvent($event)->forOrganization($this->academy)->create([
            EventOrganization::CAN_ATTEND => false,
        ]);

        $this->seed(GalacticAcademyEventSeeder::class);
        $this->seed(GalacticAcademyEventSeeder::class);

        $rows = EventOrganization::query()
            ->where(EventOrganization::EVENT_ID, $event->id)
            ->where(EventOrganization::ORGANIZATION_ID, $this->academy->id)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertFalse((bool) $rows->first()->can_attend);
    }
}
