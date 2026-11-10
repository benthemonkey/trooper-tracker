<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders\MidwestGarrison;

use App\Models\EventType;
use Database\Seeders\MidwestGarrison\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTypeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_armor_parties_virtual_troops_and_social_events_do_not_count(): void
    {
        $this->seed(EventTypeSeeder::class);

        $not_counted = EventType::where(EventType::IS_COUNTED, false)->orderBy(EventType::SLUG)->pluck(EventType::SLUG);

        $this->assertSame(['armorparty', 'socialevent', 'virtualtroop'], $not_counted->all());
    }

    public function test_social_event_is_offered_in_alphabetical_place(): void
    {
        $this->seed(EventTypeSeeder::class);

        $social = EventType::where(EventType::SLUG, 'socialevent')->firstOrFail();
        $this->assertSame('Social Event', $social->name);
        $this->assertTrue($social->is_active);

        $offered = EventType::query()->active()->ordered()->pluck(EventType::NAME)->all();
        $this->assertSame(
            ['Public Relations', 'Social Event', 'Virtual Troop', 'Wedding'],
            array_slice($offered, -4),
        );
    }

    public function test_running_twice_adds_social_event_once(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(EventTypeSeeder::class);

        $this->assertSame(1, EventType::where(EventType::SLUG, 'socialevent')->count());
        $this->assertSame(
            EventType::count(),
            EventType::distinct()->count(EventType::SORT_ORDER),
        );
    }

    public function test_disney_and_lucasfilm_are_not_offered(): void
    {
        $this->seed(EventTypeSeeder::class);

        $hidden = EventType::where(EventType::IS_ACTIVE, false)->orderBy(EventType::SLUG)->pluck(EventType::SLUG);

        $this->assertSame(['disney', 'lucasfilm'], $hidden->all());
    }

    public function test_convention_is_kept_for_the_major_cons(): void
    {
        $this->seed(EventTypeSeeder::class);

        $this->assertSame(
            'Reserved for major cons like C2E2 / FanFest.',
            EventType::where(EventType::SLUG, 'convention')->value(EventType::DESCRIPTION),
        );
        $this->assertSame(
            'Standard costuming event.',
            EventType::where(EventType::SLUG, 'regular')->value(EventType::DESCRIPTION),
        );
    }
}
