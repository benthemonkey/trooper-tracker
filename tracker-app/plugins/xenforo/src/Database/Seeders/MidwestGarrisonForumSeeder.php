<?php

declare(strict_types=1);

namespace TroopTracker\Plugins\Xenforo\Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TroopTracker\Plugins\Xenforo\Models\OrganizationForum;

/**
 * The Midwest Garrison forum's event forums and user groups for each organization, and the link
 * from each imported event to its TT1.0 forum thread.
 *
 * Run after MidwestGarrisonSeeder, with the XenForo plugin enabled and the TT1.0 tables present:
 *   php artisan db:seed \
 *     --class="TroopTracker\Plugins\Xenforo\Database\Seeders\MidwestGarrisonForumSeeder"
 */
class MidwestGarrisonForumSeeder extends Seeder
{
    //  "Event Planning" on the forum
    private const EVENT_FORUM = 222;

    //  Midwest archives by year: 332 is "2026 Completed Events", and 353 takes over on 2027-01-01.
    //  This value is rebuilt nightly on staging; a live site changes it on the organization's admin page.
    private const ARCHIVE_FORUM = 332;

    /**
     * Keyed by [parent name, organization name]; a null parent means a club. A club's group sits
     * on the club, since the import makes its members members of the club itself, and on the
     * region so a member placed there later keeps it.
     */
    private const FORUMS = [
        //  "501st": visitors from other garrisons are members of the 501st itself, and the sync
        //  gives each garrison member their parent organization's group too. Retired members get
        //  "Inactive Members" instead, which can't see the private forums or start threads.
        [null, '501st Legion', ['active_group_id' => 1510, 'retired_group_id' => 1012]],
        [null, 'Rebel Legion', ['active_group_id' => 836]],
        [null, 'Mandalorian Mercs', ['active_group_id' => 1489]],
        [null, 'Dark Empire', ['active_group_id' => 1499]],
        [null, 'Saber Guild', ['active_group_id' => 1507]],
        [null, 'Droid Builders', ['active_group_id' => 1490]],
        [null, 'Galactic Academy', ['active_group_id' => 1500]],
        ['Rebel Legion', 'Rebel Legion (Midwest)', ['active_group_id' => 836]],
        ['Mandalorian Mercs', 'Mandalorian Mercs (Midwest)', ['active_group_id' => 1489]],
        ['Dark Empire', 'Dark Empire (Midwest)', ['active_group_id' => 1499]],
        ['Saber Guild', 'Saber Guild (Midwest)', ['active_group_id' => 1507]],
        ['Droid Builders', 'Droid Builders (Midwest)', ['active_group_id' => 1490]],
        ['Galactic Academy', 'Galactic Academy (Midwest)', ['active_group_id' => 1500]],
        ['501st Legion', 'Midwest Garrison', [
            'event_forum_id' => self::EVENT_FORUM,
            'archive_forum_id' => self::ARCHIVE_FORUM,
            'active_group_id' => 1504,
        ]],
        ['Midwest Garrison', '332nd Blurrg Squad', [
            'event_forum_id' => self::EVENT_FORUM,
            'archive_forum_id' => self::ARCHIVE_FORUM,
            'active_group_id' => 1503,
        ]],
    ];

    public function run(): void
    {
        foreach (self::FORUMS as [$parent_name, $name, $settings])
        {
            $organization = $this->find($parent_name, $name);

            if ($organization !== null)
            {
                OrganizationForum::store($organization->id, $settings);
            }
        }

        $this->linkEventThreads();
    }

    /**
     * Imported events keep their TT1.0 ids. Shifts of a linked event share its thread, so only
     * events that were imported get a row.
     */
    private function linkEventThreads(): void
    {
        if (!Schema::hasTable('events'))
        {
            return;
        }

        DB::table('events')
            ->join('tt_events', 'tt_events.id', '=', 'events.id')
            ->where('events.thread_id', '>', 0)
            ->select(['events.id', 'events.thread_id', 'events.post_id'])
            ->orderBy('events.id')
            ->chunk(500, function (Collection $events): void
            {
                $now = now();

                DB::table('tt_xenforo_event_threads')->upsert(
                    $events->map(fn (object $event): array => [
                        'event_id' => $event->id,
                        'should_create_thread' => true,
                        'thread_id' => $event->thread_id,
                        'post_id' => $event->post_id ?: null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                    ['event_id'],
                    ['thread_id', 'post_id', 'updated_at'],
                );
            });
    }

    private function find(?string $parent_name, string $name): ?Organization
    {
        $query = Organization::query()->where(Organization::NAME, $name);

        if ($parent_name === null)
        {
            return $query->whereNull(Organization::PARENT_ID)->first();
        }

        $parent = Organization::query()->where(Organization::NAME, $parent_name)->first();

        return $parent === null
            ? null
            : $query->where(Organization::PARENT_ID, $parent->id)->first();
    }
}
