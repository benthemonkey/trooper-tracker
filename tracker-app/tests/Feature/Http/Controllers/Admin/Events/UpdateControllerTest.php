<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Admin\Events;

use App\Enums\EventTrooperStatus;
use App\Models\Event;
use App\Models\EventShift;
use App\Models\EventTrooper;
use App\Models\Trooper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoke_displays_update_event_page_for_admin(): void
    {
        $trooper = Trooper::factory()->asSystemOwner()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($trooper)->get(route('admin.events.update', ['event' => $event->id]));

        $response->assertOk();
        $response->assertViewIs('pages.admin.events.update');
    }

    public function test_invoke_offers_attendees_going_or_attended_as_coordinator(): void
    {
        $admin = Trooper::factory()->asSystemOwner()->create();
        $creator = Trooper::factory()->withDisplayName('TK-421 Creator')->create();
        $event = Event::factory()->withCreatedByTrooper($creator)->create();
        $shift = EventShift::factory()->forEvent($event)->create();
        foreach (['TK-422 Going' => EventTrooperStatus::GOING, 'TK-423 Tentative' => EventTrooperStatus::TENTATIVE] as $name => $status)
        {
            EventTrooper::factory()->forEventShift($shift)
                ->forTrooper(Trooper::factory()->withDisplayName($name)->create())
                ->create([EventTrooper::STATUS => $status]);
        }

        $response = $this->actingAs($admin)->get(route('admin.events.update', ['event' => $event->id]));

        $response->assertOk();
        $response->assertSeeInOrder(['Event Coordinator', 'TK-421 Creator (created the event)', 'TK-422 Going']);
        $response->assertDontSee('TK-423 Tentative');
    }

    public function test_invoke_requires_authentication(): void
    {
        $event = Event::factory()->create();

        $response = $this->get(route('admin.events.update', ['event' => $event->id]));

        $response->assertRedirect(route('auth.login'));
    }
}
