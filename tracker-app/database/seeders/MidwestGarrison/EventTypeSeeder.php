<?php

declare(strict_types=1);

namespace Database\Seeders\MidwestGarrison;

use App\Models\EventType;
use Illuminate\Database\Seeder;

/**
 * Midwest's event types: armor parties, virtual troops and social events don't count toward
 * troop totals, Disney and Lucasfilm aren't offered (as on the TT1.0 tracker), and Convention
 * is kept for the major cons. Runs before the events and milestones are imported, so troop
 * totals and milestones use these settings. Types are found by the names the migration seeds.
 */
class EventTypeSeeder extends Seeder
{
    private const NOT_COUNTED = ['Armor Party', 'Virtual Troop', 'Social Event'];

    private const HIDDEN = ['Disney', 'Lucas Film Limited'];

    private const DESCRIPTIONS = [
        'Convention' => 'Reserved for major cons like C2E2 / FanFest.',
    ];

    public function run(): void
    {
        $this->addSocialEvent();

        EventType::whereIn(EventType::NAME, self::NOT_COUNTED)->update([EventType::IS_COUNTED => false]);
        EventType::whereIn(EventType::NAME, self::HIDDEN)->update([EventType::IS_ACTIVE => false]);

        foreach (self::DESCRIPTIONS as $name => $description)
        {
            EventType::where(EventType::NAME, $name)->update([EventType::DESCRIPTION => $description]);
        }
    }

    /**
     * Listed in alphabetical place like the defaults, right after Public Relations.
     */
    private function addSocialEvent(): void
    {
        if (EventType::where(EventType::NAME, 'Social Event')->exists())
        {
            return;
        }

        $after = EventType::where(EventType::NAME, 'Public Relations')->value(EventType::SORT_ORDER)
            ?? EventType::max(EventType::SORT_ORDER)
            ?? 0;

        EventType::where(EventType::SORT_ORDER, '>', $after)->increment(EventType::SORT_ORDER);

        EventType::create([
            EventType::NAME => 'Social Event',
            EventType::DESCRIPTION => 'Garrison get-together, such as a meetup or holiday party.',
            EventType::SORT_ORDER => $after + 1,
        ]);
    }
}
