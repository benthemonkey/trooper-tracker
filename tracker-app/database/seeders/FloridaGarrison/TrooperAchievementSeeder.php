<?php

declare(strict_types=1);

namespace Database\Seeders\FloridaGarrison;

use App\Enums\AchievementType;
use App\Enums\EventStatus;
use App\Enums\EventTrooperStatus;
use App\Models\Event;
use App\Models\EventShift;
use App\Models\EventTrooper;
use App\Models\Trooper;
use App\Models\TrooperAchievement;
use Illuminate\Support\Carbon;
use Illuminate\Database\Seeder;

/**
 * Rank, shift count, hours and funds as of today, plus troop-count milestones dated to the end
 * of the date range (see getDateRanges) in which each one was reached.
 *
 * Computed in memory from one read of the attended sign-ups. It used to replay every date
 * range against the database (about 25 queries per trooper per range), which made it the
 * slowest step of the import. Results are the same.
 */
class TrooperAchievementSeeder extends Seeder
{
    private const TROOP_THRESHOLDS = [
        AchievementType::FIRST_TROOP->value => 1,
        AchievementType::TROOPED_10->value => 10,
        AchievementType::TROOPED_25->value => 25,
        AchievementType::TROOPED_50->value => 50,
        AchievementType::TROOPED_75->value => 75,
        AchievementType::TROOPED_100->value => 100,
        AchievementType::TROOPED_150->value => 150,
        AchievementType::TROOPED_200->value => 200,
        AchievementType::TROOPED_250->value => 250,
        AchievementType::TROOPED_300->value => 300,
        AchievementType::TROOPED_400->value => 400,
        AchievementType::TROOPED_500->value => 500,
        AchievementType::TROOPED_501->value => 501,
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $range_ends = array_map(
            fn (array $range) => Carbon::parse($range[1]),
            $this->getDateRanges('2000-01-01')
        );

        $final_end = end($range_ends);

        $troopers = $this->collectTroopers($final_end);

        //  most troops first; ties by id so the rank is repeatable
        uasort($troopers, fn (array $a, array $b) => [count($b['counted_at']), $a['id']] <=> [count($a['counted_at']), $b['id']]);

        $today = now()->toDateString();
        $metrics = [];
        $milestones = [];
        $rank = 1;

        foreach ($troopers as $trooper)
        {
            $event_count = count($trooper['counted_at']);

            $values = [
                AchievementType::TROOPER_RANK->value => $rank++,
                AchievementType::TROOPER_SHIFTS->value => $event_count,
                AchievementType::VOLUNTEER_HOURS->value => $trooper['total_hours'],
                AchievementType::DIRECT_FUNDS->value => $trooper['total_direct'],
                AchievementType::INDIRECT_FUNDS->value => $trooper['total_indirect'],
            ];

            foreach ($values as $type => $value)
            {
                $metrics[] = [
                    TrooperAchievement::TROOPER_ID => $trooper['id'],
                    TrooperAchievement::TYPE => $type,
                    TrooperAchievement::VALUE => $value,
                    TrooperAchievement::ACHIEVEMENT_DATE => $today,
                ];
            }

            foreach (self::TROOP_THRESHOLDS as $type => $threshold)
            {
                if ($event_count < $threshold)
                {
                    break;
                }

                $milestones[] = [
                    TrooperAchievement::TROOPER_ID => $trooper['id'],
                    TrooperAchievement::TYPE => $type,
                    TrooperAchievement::VALUE => true,
                    TrooperAchievement::ACHIEVEMENT_DATE => $this->firstRangeEnd($range_ends, $trooper['counted_at'][$threshold - 1]),
                    //  reached in TT1.0, which already celebrated them; without this the daily
                    //  milestone roundup would notify everyone of their whole history
                    TrooperAchievement::NOTIFICATION_SENT_AT => now(),
                    TrooperAchievement::CREATED_AT => now(),
                    TrooperAchievement::UPDATED_AT => now(),
                ];
            }
        }

        foreach (array_chunk($metrics, 500) as $chunk)
        {
            TrooperAchievement::upsert(
                $chunk,
                [TrooperAchievement::TROOPER_ID, TrooperAchievement::TYPE, TrooperAchievement::ORGANIZATION_COALESCE_ID],
                [TrooperAchievement::VALUE, TrooperAchievement::ACHIEVEMENT_DATE]
            );
        }

        //  like firstOrCreate: a milestone already on record keeps its date
        foreach (array_chunk($milestones, 500) as $chunk)
        {
            TrooperAchievement::insertOrIgnore($chunk);
        }

        $this->command?->info('   Achievements for '.count($troopers).' troopers, '.count($milestones).' milestones.');
    }

    /**
     * Per trooper: the moment each attended troop starts counting toward their troop count
     * (sorted), and hours and funds as of $final_end.
     *
     * A troop counts at a range end once both its shift has started and its event has ended
     * by then; hours and funds only need the shift to have started.
     *
     * @return array<int, array{id: int, counted_at: list<Carbon>, total_hours: int, total_direct: int, total_indirect: int}>
     */
    private function collectTroopers(Carbon $final_end): array
    {
        $troopers = [];

        foreach (Trooper::query()->pluck(Trooper::ID) as $id)
        {
            $troopers[$id] = ['id' => $id, 'counted_at' => [], 'total_hours' => 0, 'total_direct' => 0, 'total_indirect' => 0];
        }

        $event_troopers = EventTrooper::query()
            ->select([EventTrooper::ID, EventTrooper::TROOPER_ID, EventTrooper::EVENT_SHIFT_ID])
            ->with('event_shift.event.event_type')
            ->where(EventTrooper::STATUS, EventTrooperStatus::ATTENDED)
            ->whereHas('event_shift', fn ($q) => $q->where(EventShift::STATUS, EventStatus::CLOSED))
            ->whereHas('event_shift.event', fn ($q) => $q->where(Event::STATUS, EventStatus::CLOSED))
            ->get();

        foreach ($event_troopers as $event_trooper)
        {
            if (!isset($troopers[$event_trooper->trooper_id]))
            {
                continue;
            }

            $trooper = &$troopers[$event_trooper->trooper_id];
            $shift = $event_trooper->event_shift;
            $event_end = $shift->event->event_end;

            if ($shift->shift_starts_at->lte($final_end))
            {
                $trooper['total_direct'] += $shift->charity_direct_funds;
                $trooper['total_indirect'] += $shift->charity_indirect_funds;
                $trooper['total_hours'] += $shift->effective_charity_hours;
            }

            //  same rule as RecalculateTrooperRank: events of a type that doesn't count are
            //  left out of troop counts and milestones, but their charity still counts
            if ($event_end !== null && $shift->event->isCounted())
            {
                $counted_at = $shift->shift_starts_at->max($event_end);

                if ($counted_at->lte($final_end))
                {
                    $trooper['counted_at'][] = $counted_at;
                }
            }

            unset($trooper);
        }

        foreach ($troopers as &$trooper)
        {
            usort($trooper['counted_at'], fn (Carbon $a, Carbon $b) => $a <=> $b);
        }

        unset($trooper);

        return $troopers;
    }

    /**
     * @param  list<Carbon>  $range_ends
     */
    private function firstRangeEnd(array $range_ends, Carbon $moment): string
    {
        foreach ($range_ends as $range_end)
        {
            if ($moment->lte($range_end))
            {
                return $range_end->toDateString();
            }
        }

        return end($range_ends)->toDateString();
    }

    private function getDateRanges(string $start_date_string): array
    {
        $start = Carbon::parse($start_date_string)->startOfDay();
        $today = Carbon::today();
        $start_of_current_year = $today->copy()->startOfYear();
        $ranges = [];

        // Phase 1: Five-year chunks up through 2020.
        $end_of_2020 = Carbon::create(2020, 12, 31)->endOfDay()->min($today);

        if ($start->lte($end_of_2020))
        {
            $chunk_cursor = $start->copy()->startOfYear();

            while ($chunk_cursor->lte($end_of_2020))
            {
                $chunk_start = $chunk_cursor->copy()->startOfYear()->max($start);
                $chunk_end = $chunk_cursor->copy()->addYears(4)->endOfYear()->min($end_of_2020);

                if ($chunk_start->lte($chunk_end))
                {
                    $ranges[] = [$chunk_start->format('Y-m-d'), $chunk_end->format('Y-m-d')];
                }

                $chunk_cursor->addYears(5);
            }
        }

        // Phase 2: Year-by-year from 2021 through 2024.
        $start_of_2021 = Carbon::create(2021, 1, 1)->startOfDay();
        $end_of_2024 = Carbon::create(2024, 12, 31)->endOfDay()->min($today);
        $yearly_phase_start = $start->gt($start_of_2021) ? $start : $start_of_2021;

        if ($yearly_phase_start->lte($end_of_2024))
        {
            $year_cursor = $yearly_phase_start->copy()->startOfYear();

            while ($year_cursor->lte($end_of_2024))
            {
                $year_start = $year_cursor->copy()->startOfYear()->max($yearly_phase_start);
                $year_end = $year_cursor->copy()->endOfYear()->min($end_of_2024);

                if ($year_start->lte($year_end))
                {
                    $ranges[] = [$year_start->format('Y-m-d'), $year_end->format('Y-m-d')];
                }

                $year_cursor->addYear();
            }
        }

        // Phase 3: Quarter-by-quarter for 2025.
        $start_of_2025 = Carbon::create(2025, 1, 1)->startOfDay();
        $end_of_2025 = Carbon::create(2025, 12, 31)->endOfDay()->min($today);
        $quarterly_phase_start = $start->gt($start_of_2025) ? $start : $start_of_2025;

        if ($quarterly_phase_start->lte($end_of_2025))
        {
            $quarter_cursor = $quarterly_phase_start->copy()->startOfQuarter();

            while ($quarter_cursor->lte($end_of_2025))
            {
                $quarter_start = $quarter_cursor->copy()->startOfQuarter()->max($quarterly_phase_start);
                $quarter_end = $quarter_cursor->copy()->endOfQuarter()->min($end_of_2025);

                if ($quarter_start->lte($quarter_end))
                {
                    $ranges[] = [$quarter_start->format('Y-m-d'), $quarter_end->format('Y-m-d')];
                }

                $quarter_cursor->addQuarter();
            }
        }

        // Phase 4: Week-by-week for the current year.
        $current_year_phase_start = $start->gt($start_of_current_year)
            ? $start
            : $start_of_current_year;

        if ($current_year_phase_start->lte($today))
        {
            $week_cursor = $current_year_phase_start->copy()->startOfWeek(Carbon::SUNDAY);

            while ($week_cursor->lte($today))
            {
                $week_start = $week_cursor->copy()->startOfWeek(Carbon::SUNDAY)
                    ->max($current_year_phase_start);
                $week_end = $week_cursor->copy()->endOfWeek(Carbon::SATURDAY)->min($today);

                if ($week_start->lte($week_end))
                {
                    $ranges[] = [$week_start->format('Y-m-d'), $week_end->format('Y-m-d')];
                }

                if ($week_end->equalTo($today))
                {
                    break;
                }

                $week_cursor->addWeek();
            }
        }

        return $ranges;
    }
}
