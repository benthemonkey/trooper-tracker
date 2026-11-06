<?php

declare(strict_types=1);

namespace Database\Seeders\MidwestGarrison;

use App\Enums\OrganizationType;
use App\Models\Observers\OrganizationObserver;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Midwest is one garrison-wide group (legacy squad 14 "Midwest Garrison") plus the 332nd Blurrg
 * Squad (legacy squad 15), assigned to events south of 41.6°N.
 *
 * TODO(mwg): the non-501st region names are placeholders. The import needs one region under
 * every club because TrooperOrganizationSeeder assigns members to the club's first region.
 */
class OrganizationSeeder extends Seeder
{
    //  "Event Planning" on the forum
    private const EVENT_FORUM = 222;

    //  Midwest archives by year: 332 is "2026 Completed Events", and 353 takes over on 2027-01-01.
    //  This value is rebuilt nightly on staging; a live site changes it on the organization's admin page.
    private const EVENT_ARCHIVE_FORUM = 332;

    //  Forum group for each club's members
    private const CLUB_GROUPS = [
        'Rebel Legion' => 836,
        'Mandalorian Mercs' => 1489,
        'Dark Empire' => 1499,
        'Saber Guild' => 1507,
        'Droid Builders' => 1490,
        'Galactic Academy' => 1500,
    ];

    public function run(): void
    {
        $this->loadClubs();
        $this->loadRegions();
        $this->loadUnits();

        Organization::resequenceAll();

        //  model events are blocked during seeding, so we
        //  need to manually trigger the observer to set any
        //  derived fields
        $observer = new OrganizationObserver();
        $organizations = Organization::all();
        foreach ($organizations as $organization)
        {
            $observer->saved($organization);
        }
    }

    /**
     * Replaces the group ids the shared OrganizationSeeder puts on the clubs, which don't exist on
     * Midwest's forum. The import attaches club members to the club itself, and the user sync only
     * looks at a member's organization and its ancestors, so a group set only on the region is
     * treated as one the member shouldn't have, and the sync removes it.
     */
    private function loadClubs(): void
    {
        foreach (self::CLUB_GROUPS as $name => $group_id)
        {
            Organization::where('name', $name)
                ->whereNull('parent_id')
                ->update(['xenforo_group_active_id' => $group_id]);
        }
    }

    private function loadRegions(): void
    {
        $regions = [
            ['parent' => '501st Legion',      'name' => 'Midwest Garrison', 'related_forum' => self::EVENT_FORUM, 'related_forum_archive' => self::EVENT_ARCHIVE_FORUM, 'xenforo_group_active_id' => 1504],
            ['parent' => 'Rebel Legion',      'name' => 'Rebel Legion (Midwest)', 'xenforo_group_active_id' => self::CLUB_GROUPS['Rebel Legion']],
            ['parent' => 'Mandalorian Mercs', 'name' => 'Mandalorian Mercs (Midwest)', 'xenforo_group_active_id' => self::CLUB_GROUPS['Mandalorian Mercs']],
            ['parent' => 'Dark Empire',       'name' => 'Dark Empire (Midwest)', 'xenforo_group_active_id' => self::CLUB_GROUPS['Dark Empire']],
            ['parent' => 'Saber Guild',       'name' => 'Saber Guild (Midwest)', 'xenforo_group_active_id' => self::CLUB_GROUPS['Saber Guild']],
            ['parent' => 'Droid Builders',    'name' => 'Droid Builders (Midwest)', 'xenforo_group_active_id' => self::CLUB_GROUPS['Droid Builders']],
            ['parent' => 'Galactic Academy',  'name' => 'Galactic Academy (Midwest)', 'xenforo_group_active_id' => self::CLUB_GROUPS['Galactic Academy']],
        ];

        foreach ($regions as $data)
        {
            $this->saveChild($data['parent'], $data, OrganizationType::REGION);
        }
    }

    private function loadUnits(): void
    {
        $units = [
            ['parent' => 'Midwest Garrison', 'name' => '332nd Blurrg Squad', 'related_forum' => self::EVENT_FORUM, 'related_forum_archive' => self::EVENT_ARCHIVE_FORUM, 'xenforo_group_active_id' => 1503],
        ];

        foreach ($units as $data)
        {
            $this->saveChild($data['parent'], $data, OrganizationType::UNIT);
        }
    }

    private function saveChild(string $parent_name, array $data, OrganizationType $type): void
    {
        $parent = Organization::where('name', $parent_name)->first();

        if ($parent === null)
        {
            return;
        }

        $org = Organization::where('name', $data['name'])
            ->where('parent_id', $parent->id)
            ->first() ?? new Organization();

        $org->name = $data['name'];
        $org->parent_id = $parent->id;
        $org->type = $type;

        foreach (['related_forum', 'related_forum_archive', 'xenforo_group_active_id'] as $column)
        {
            if (isset($data[$column]))
            {
                $org->{$column} = $data[$column];
            }
        }

        $org->save();
    }
}
