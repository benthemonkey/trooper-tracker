<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders\FloridaGarrison;

use App\Enums\AchievementType;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventShift;
use App\Models\EventTrooper;
use App\Models\Organization;
use App\Models\Trooper;
use App\Models\TrooperAchievement;
use Database\Seeders\FloridaGarrison\TrooperAchievementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrooperAchievementSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        //  the seeder's date ranges are relative to today
        Carbon::setTestNow('2026-03-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_run_uses_shift_level_charity_metrics_after_event_charity_columns_are_removed(): void
    {
        $this->assertFalse(Schema::hasColumn('tt_events', 'charity_direct_funds'));

        $trooper = Trooper::factory()->create();

        $this->createAttendedTroop($trooper, Carbon::parse('2026-01-10 10:00:00'), [
            EventShift::CHARITY_DIRECT_FUNDS => 125,
            EventShift::CHARITY_INDIRECT_FUNDS => 75,
            EventShift::CHARITY_HOURS => 5,
        ]);

        (new TrooperAchievementSeeder())->run();

        $this->assertAchievementValue($trooper, AchievementType::DIRECT_FUNDS, 125);
        $this->assertAchievementValue($trooper, AchievementType::INDIRECT_FUNDS, 75);
        $this->assertAchievementValue($trooper, AchievementType::VOLUNTEER_HOURS, 5);
    }

    public function test_run_ranks_by_troop_count_with_ties_by_id(): void
    {
        $most = Trooper::factory()->create();
        $tied_first = Trooper::factory()->create();
        $tied_second = Trooper::factory()->create();

        $this->createAttendedTroop($most, Carbon::parse('2026-01-10 10:00:00'));
        $this->createAttendedTroop($most, Carbon::parse('2026-01-24 10:00:00'));

        (new TrooperAchievementSeeder())->run();

        $this->assertAchievementValue($most, AchievementType::TROOPER_RANK, 1);
        $this->assertAchievementValue($most, AchievementType::TROOPER_SHIFTS, 2);
        $this->assertAchievementValue($tied_first, AchievementType::TROOPER_RANK, 2);
        $this->assertAchievementValue($tied_second, AchievementType::TROOPER_RANK, 3);
        $this->assertAchievementValue($tied_second, AchievementType::TROOPER_SHIFTS, 0);
    }

    public function test_run_dates_milestones_to_the_range_they_were_reached_in_and_marks_them_notified(): void
    {
        $trooper = Trooper::factory()->create();

        //  counts once the event has ended (12:00 on Saturday the 10th), so in the week
        //  ending on the 17th; range ends are midnight
        $this->createAttendedTroop($trooper, Carbon::parse('2026-01-10 10:00:00'));

        (new TrooperAchievementSeeder())->run();

        $milestone = TrooperAchievement::where(TrooperAchievement::TROOPER_ID, $trooper->id)
            ->where(TrooperAchievement::TYPE, AchievementType::FIRST_TROOP)
            ->sole();

        $this->assertSame('2026-01-17', $milestone->achievement_date->toDateString());
        $this->assertNotNull($milestone->notification_sent_at);
        $this->assertDatabaseMissing('tt_trooper_achievements', [
            TrooperAchievement::TROOPER_ID => $trooper->id,
            TrooperAchievement::TYPE => AchievementType::TROOPED_10->value,
        ]);
    }

    public function test_run_keeps_an_existing_milestone_date(): void
    {
        $trooper = Trooper::factory()->create();
        $this->createAttendedTroop($trooper, Carbon::parse('2026-01-10 10:00:00'));

        TrooperAchievement::create([
            TrooperAchievement::TROOPER_ID => $trooper->id,
            TrooperAchievement::TYPE => AchievementType::FIRST_TROOP,
            TrooperAchievement::VALUE => true,
            TrooperAchievement::ACHIEVEMENT_DATE => '2020-05-05',
        ]);

        (new TrooperAchievementSeeder())->run();

        $milestone = TrooperAchievement::where(TrooperAchievement::TROOPER_ID, $trooper->id)
            ->where(TrooperAchievement::TYPE, AchievementType::FIRST_TROOP)
            ->sole();

        $this->assertSame('2020-05-05', $milestone->achievement_date->toDateString());
    }

    /**
     * @param  array<string, mixed>  $shift_attributes
     */
    private function createAttendedTroop(Trooper $trooper, Carbon $starts_at, array $shift_attributes = []): void
    {
        $organization = Organization::factory()->create();

        $event = Event::factory()
            ->withOrganization($organization)
            ->create([
                Event::STATUS => EventStatus::CLOSED,
                Event::EVENT_START => $starts_at,
                Event::EVENT_END => $starts_at->copy()->addHours(2),
            ]);

        $shift = EventShift::factory()
            ->forEvent($event)
            ->create(array_merge([
                EventShift::STATUS => EventStatus::CLOSED,
                EventShift::SHIFT_STARTS_AT => $starts_at,
                EventShift::SHIFT_ENDS_AT => $starts_at->copy()->addHours(2),
            ], $shift_attributes));

        EventTrooper::factory()
            ->forTrooper($trooper)
            ->forEventShift($shift)
            ->asAttended()
            ->create();
    }

    private function assertAchievementValue(Trooper $trooper, AchievementType $type, mixed $value): void
    {
        $this->assertDatabaseHas('tt_trooper_achievements', [
            TrooperAchievement::TROOPER_ID => $trooper->id,
            TrooperAchievement::TYPE => $type->value,
            TrooperAchievement::VALUE => $value,
        ]);
    }
}
