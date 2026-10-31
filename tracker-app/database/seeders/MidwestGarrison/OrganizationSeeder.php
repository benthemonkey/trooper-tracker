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
 * XenForo ids come from the legacy squad/club arrays and are unverified against the forum;
 * confirm them before anything syncs user groups (staging never does).
 */
class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
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

    private function loadRegions(): void
    {
        $regions = [
            ['parent' => '501st Legion',      'name' => 'Midwest Garrison', 'related_forum' => 404, 'related_forum_archive' => 326, 'xenforo_group_active_id' => 44],
            ['parent' => 'Rebel Legion',      'name' => 'Rebel Legion (Midwest)', 'xenforo_group_active_id' => 1486],
            ['parent' => 'Mandalorian Mercs', 'name' => 'Mandalorian Mercs (Midwest)', 'xenforo_group_active_id' => 1488],
            ['parent' => 'Dark Empire',       'name' => 'Dark Empire (Midwest)', 'xenforo_group_active_id' => 1511],
            ['parent' => 'Saber Guild',       'name' => 'Saber Guild (Midwest)', 'xenforo_group_active_id' => 1491],
            ['parent' => 'Droid Builders',    'name' => 'Droid Builders (Midwest)', 'xenforo_group_active_id' => 1487],
            ['parent' => 'Galactic Academy',  'name' => 'Galactic Academy (Midwest)'],
        ];

        foreach ($regions as $data)
        {
            $this->saveChild($data['parent'], $data, OrganizationType::REGION);
        }
    }

    private function loadUnits(): void
    {
        $units = [
            ['parent' => 'Midwest Garrison', 'name' => '332nd Blurrg Squad', 'related_forum' => 404, 'related_forum_archive' => 326, 'xenforo_group_active_id' => 44],
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
