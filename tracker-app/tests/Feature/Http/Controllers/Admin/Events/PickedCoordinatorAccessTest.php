<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Admin\Events;

use App\Enums\EventStatus;
use App\Enums\EventTrooperStatus;
use App\Enums\MembershipStatus;
use App\Models\Event;
use App\Models\EventShift;
use App\Models\EventTrooper;
use App\Models\EventType;
use App\Models\Organization;
use App\Models\Trooper;
use App\Models\TrooperAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A trooper picked as an event's coordinator can run that event whatever their role, and
 * nothing more: no other events, no events list, no creating or copying events.
 */
class PickedCoordinatorAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['update', 'shifts', 'charity', 'troopers', 'uploads', 'mission-review'];

    private Organization $region;

    private Organization $other_region;

    private Trooper $member;

    private Event $event;

    private EventShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $club = Organization::factory()->asOrganization()->withNodePath('1:')->create(['name' => 'Club']);
        $this->region = Organization::factory()->asRegion()->withParent($club)->withNodePath('1:2:')->create(['name' => 'Home Region']);
        $this->other_region = Organization::factory()->asRegion()->withParent($club)->withNodePath('1:4:')->create(['name' => 'Other Region']);

        $this->member = Trooper::factory()->asMember()->asActive()->withVerifiedEmail()->create();
        TrooperAssignment::factory()->forTrooper($this->member)->forOrganization($this->region)->asMember()->create();

        $this->event = Event::factory()->withOrganization($this->region)->create(['name' => 'Coordinated Troop']);
        $this->shift = EventShift::factory()->forEvent($this->event)->create();
        EventTrooper::factory()->forEventShift($this->shift)->forTrooper($this->member)->create([
            EventTrooper::STATUS => EventTrooperStatus::GOING,
        ]);
    }

    public function test_a_member_cannot_open_an_events_pages_before_being_picked(): void
    {
        foreach (self::PAGES as $page)
        {
            $this->actingAs($this->member)
                ->get(route('admin.events.'.$page, ['event' => $this->event]))
                ->assertForbidden();
        }

        $this->actingAs($this->member)
            ->get(route('events.display', ['event' => $this->event]))
            ->assertOk()
            ->assertDontSee(route('admin.events.update', ['event' => $this->event]), false);
    }

    public function test_a_member_picked_as_coordinator_can_open_every_page_of_that_event(): void
    {
        $this->pick($this->member);

        foreach (self::PAGES as $page)
        {
            $this->actingAs($this->member)
                ->get(route('admin.events.'.$page, ['event' => $this->event]))
                ->assertOk();
        }

        //  the event page links to Edit, not Copy, and the admin pages don't link to lists
        //  they can't open
        $this->actingAs($this->member)
            ->get(route('events.display', ['event' => $this->event]))
            ->assertOk()
            ->assertSee(route('admin.events.update', ['event' => $this->event]), false)
            ->assertDontSee(route('admin.events.copy', ['event' => $this->event]), false);

        $this->actingAs($this->member)
            ->get(route('admin.events.update', ['event' => $this->event]))
            ->assertOk()
            ->assertDontSee('href="'.route('admin.events.list').'"', false)
            ->assertDontSee('href="'.route('admin.display').'"', false);
    }

    public function test_a_member_picked_as_coordinator_can_run_the_roster(): void
    {
        $this->pick($this->member);
        $other = EventTrooper::factory()->forEventShift($this->shift)->create([
            EventTrooper::STATUS => EventTrooperStatus::GOING,
        ]);

        $this->actingAs($this->member)
            ->post(route('admin.events.troopers.remove', ['event' => $this->event, 'event_trooper' => $other]))
            ->assertSuccessful();

        $this->assertSoftDeleted($other);
    }

    public function test_a_member_picked_as_coordinator_gets_nothing_else(): void
    {
        $this->pick($this->member);
        $another = Event::factory()->withOrganization($this->region)->create();

        $this->actingAs($this->member)->get(route('admin.events.update', ['event' => $another]))->assertForbidden();
        $this->actingAs($this->member)->get(route('admin.events.list'))->assertForbidden();
        $this->actingAs($this->member)->get(route('admin.events.create'))->assertForbidden();
        $this->actingAs($this->member)->get(route('admin.events.copy', ['event' => $this->event]))->assertForbidden();
        $this->actingAs($this->member)->post(route('admin.events.copy', ['event' => $this->event]))->assertForbidden();
        $this->actingAs($this->member)->get(route('admin.display'))->assertForbidden();
    }

    public function test_a_member_picked_as_coordinator_saves_the_event_but_cannot_move_it(): void
    {
        $this->pick($this->member);

        $this->actingAs($this->member)
            ->get(route('admin.events.update', ['event' => $this->event]))
            ->assertOk()
            ->assertSee('Home Region')
            ->assertDontSee(route('pickers.organization'), false);

        $this->actingAs($this->member)
            ->post(route('admin.events.update', ['event' => $this->event]), $this->payload($this->region))
            ->assertRedirect(route('admin.events.update', ['event' => $this->event]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Troop', $this->event->fresh()->name);

        $this->actingAs($this->member)
            ->post(route('admin.events.update', ['event' => $this->event]), $this->payload($this->other_region))
            ->assertSessionHasErrors(Event::ORGANIZATION_ID);

        $this->assertSame($this->region->id, $this->event->fresh()->organization_id);
    }

    public function test_access_ends_when_the_coordinator_stops_going(): void
    {
        $this->pick($this->member);

        EventTrooper::where(EventTrooper::TROOPER_ID, $this->member->id)->sole()
            ->update([EventTrooper::STATUS => EventTrooperStatus::CANCELLED]);

        $this->assertNull($this->event->fresh()->coordinator_trooper_id);
        $this->actingAs($this->member)
            ->get(route('admin.events.update', ['event' => $this->event]))
            ->assertForbidden();
    }

    public function test_a_retired_trooper_picked_as_coordinator_has_no_access(): void
    {
        $this->pick($this->member);
        $this->member->update([Trooper::MEMBERSHIP_STATUS => MembershipStatus::RETIRED]);

        $this->actingAs($this->member->fresh())
            ->get(route('admin.events.update', ['event' => $this->event]))
            ->assertForbidden();
    }

    public function test_the_overview_flags_candidates_below_event_coordinator(): void
    {
        $event_coordinator = Trooper::factory()->asEventCoordinator()->create();
        EventTrooper::factory()->forEventShift($this->shift)->forTrooper($event_coordinator)->create([
            EventTrooper::STATUS => EventTrooperStatus::GOING,
        ]);
        $moderator = Trooper::factory()->asModerator()->create();
        TrooperAssignment::factory()->forTrooper($moderator)->forOrganization($this->region)->asModerator()->create();

        $this->actingAs($moderator)
            ->get(route('admin.events.update', ['event' => $this->event]))
            ->assertOk()
            ->assertViewHas('coordinator_below_role', [(string) $this->member->id])
            ->assertSee("isn't an event coordinator", false);
    }

    private function pick(Trooper $trooper): void
    {
        $this->event->update([Event::COORDINATOR_TROOPER_ID => $trooper->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Organization $organization): array
    {
        return [
            'organization_id' => $organization->id,
            'name' => 'Renamed Troop',
            Event::EVENT_TYPE_ID => EventType::idNamed('Regular'),
            'status' => EventStatus::OPEN->value,
            'event_start' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'event_end' => now()->addDays(7)->addHours(4)->format('Y-m-d H:i:s'),
            'tentative_signups_allowed' => false,
            'secure_staging_area' => false,
            'allow_blasters' => false,
            'allow_props' => false,
            'parking_available' => false,
            'accessible' => false,
            Event::COORDINATOR_TROOPER_ID => $this->member->id,
        ];
    }
}
