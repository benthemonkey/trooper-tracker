<?php

declare(strict_types=1);

namespace Database\Seeders\MidwestGarrison;

use App\Enums\OrganizationType;
use App\Models\Observers\OrganizationObserver;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Midwest is one garrison-wide group (legacy squad 14 "Midwest Garrison") plus the 332nd Blurrg
 * Squad (legacy squad 15), assigned to events south of 41.6°N. Forum ids and groups are set by
 * the XenForo plugin's MidwestGarrisonForumSeeder.
 *
 * TODO(mwg): the non-501st region names are placeholders. The import needs one region under
 * every club because TrooperOrganizationSeeder assigns members to the club's first region.
 */
class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $this->loadRegions();
        $this->loadUnits();
        $this->loadEmblem();

        Organization::resequenceAll();

        //  model events are blocked during seeding, so we
        //  need to manually trigger the observer to set any
        //  derived fields
        $observer = new OrganizationObserver;
        $organizations = Organization::all();
        foreach ($organizations as $organization)
        {
            $observer->saved($organization);
        }
    }

    private function loadRegions(): void
    {
        $regions = [
            ['parent' => '501st Legion',      'name' => 'Midwest Garrison'],
            ['parent' => 'Rebel Legion',      'name' => 'Rebel Legion (Midwest)'],
            ['parent' => 'Mandalorian Mercs', 'name' => 'Mandalorian Mercs (Midwest)'],
            ['parent' => 'Dark Empire',       'name' => 'Dark Empire (Midwest)'],
            ['parent' => 'Saber Guild',       'name' => 'Saber Guild (Midwest)'],
            ['parent' => 'Droid Builders',    'name' => 'Droid Builders (Midwest)'],
            ['parent' => 'Galactic Academy',  'name' => 'Galactic Academy (Midwest)'],
        ];

        foreach ($regions as $data)
        {
            $this->saveChild($data['parent'], $data['name'], OrganizationType::REGION);
        }
    }

    private function loadUnits(): void
    {
        $this->saveChild('Midwest Garrison', '332nd Blurrg Squad', OrganizationType::UNIT);
    }

    /**
     * The garrison emblem (public/img/midwest-garrison-emblem.png, already square) as the
     * Midwest Garrison organization's logo, in the two sizes an admin upload makes. Written
     * outside uploads/organizations, which the staging refresh empties after each import.
     */
    private function loadEmblem(): void
    {
        $organization = Organization::where(Organization::NAME, 'Midwest Garrison')->first();
        $source = public_path('img/midwest-garrison-emblem.png');

        if ($organization === null || !is_file($source))
        {
            return;
        }

        $manager = new ImageManager(new Driver);

        foreach ([128, 32] as $size)
        {
            $path = "organizations/midwest-garrison-{$size}x{$size}.png";
            $image = $manager->read($source)->scaleDown($size, $size);

            Storage::disk('public')->put($path, (string) $image->encodeByExtension('png'));

            $organization->{$size === 128 ? Organization::IMAGE_PATH_LG : Organization::IMAGE_PATH_SM} = $path;
        }

        $organization->save();
    }

    private function saveChild(string $parent_name, string $name, OrganizationType $type): void
    {
        $parent = Organization::where('name', $parent_name)->first();

        if ($parent === null)
        {
            return;
        }

        $org = Organization::where('name', $name)
            ->where('parent_id', $parent->id)
            ->first() ?? new Organization;

        $org->name = $name;
        $org->parent_id = $parent->id;
        $org->type = $type;

        $org->save();
    }
}
