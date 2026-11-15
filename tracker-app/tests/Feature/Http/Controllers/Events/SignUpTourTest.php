<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Events;

use App\Models\Event;
use App\Models\EventShift;
use App\Models\Trooper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Midwest only, temporary: the "How sign-up works" tour (pages/events/inc/signup-tour.blade.php).
 * Delete with it.
 */
class SignUpTourTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tour_starts_by_itself_on_an_event_with_an_open_shift(): void
    {
        $trooper = Trooper::factory()->asActive()->withVerifiedEmail()->create();
        $event = Event::factory()->create();
        EventShift::factory()->forEvent($event)->create();

        $response = $this->actingAs($trooper)->get(route('events.display', ['event' => $event->id]));

        $response->assertOk();
        $response->assertSee('data-autostart="true"', false);
        $response->assertSee('tt.signupTourSeen.'.$trooper->id);
        $response->assertSee('How sign-up works');
        $response->assertSeeInOrder(['Example shift', 'How signing up works', 'Sign up for each shift', 'Then pick your costume', 'After the troop']);
        $response->assertDontSee('Read the mission brief first');
    }

    public function test_the_tour_waits_for_the_button_when_no_shift_is_open(): void
    {
        $trooper = Trooper::factory()->asActive()->withVerifiedEmail()->create();
        $event = Event::factory()->create();
        EventShift::factory()->forEvent($event)->asClosed()->create();

        $response = $this->actingAs($trooper)->get(route('events.display', ['event' => $event->id]));

        $response->assertOk();
        $response->assertSee('data-autostart="false"', false);
        $response->assertSee('How sign-up works');
    }

    public function test_the_tour_starts_with_the_mission_brief_when_it_must_be_acknowledged(): void
    {
        $trooper = Trooper::factory()->asActive()->withVerifiedEmail()->create();
        $event = Event::factory()->create([
            Event::COMMENTS => 'Read me first.',
            Event::REQUIRE_MISSION_BRIEF_ACK => true,
        ]);
        EventShift::factory()->forEvent($event)->create();

        $response = $this->actingAs($trooper)->get(route('events.display', ['event' => $event->id]));

        $response->assertOk();
        $response->assertSeeInOrder(['How signing up works', 'Read the mission brief first', 'Sign up for each shift']);
    }
}
