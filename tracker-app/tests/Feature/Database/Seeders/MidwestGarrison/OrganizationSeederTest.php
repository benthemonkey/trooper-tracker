<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders\MidwestGarrison;

use App\Models\Organization;
use Database\Seeders\MidwestGarrison\OrganizationSeeder as MidwestGarrisonOrganizationSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrganizationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_midwest_garrison_gets_the_garrison_emblem(): void
    {
        Storage::fake('public');

        $this->seed(OrganizationSeeder::class);
        $this->seed(MidwestGarrisonOrganizationSeeder::class);

        $garrison = Organization::where(Organization::NAME, 'Midwest Garrison')->firstOrFail();

        $this->assertSame('organizations/midwest-garrison-128x128.png', $garrison->image_path_lg);
        $this->assertSame('organizations/midwest-garrison-32x32.png', $garrison->image_path_sm);

        $small = getimagesize(Storage::disk('public')->path($garrison->image_path_sm));
        $this->assertSame([32, 32], [$small[0], $small[1]]);

        $large = getimagesize(Storage::disk('public')->path($garrison->image_path_lg));
        $this->assertLessThanOrEqual(128, $large[0]);
        $this->assertSame($large[0], $large[1]);
    }

    public function test_blurrg_squad_gets_its_own_emblem(): void
    {
        Storage::fake('public');

        $this->seed(OrganizationSeeder::class);
        $this->seed(MidwestGarrisonOrganizationSeeder::class);

        $blurrg = Organization::where(Organization::NAME, '332nd Blurrg Squad')->firstOrFail();

        $this->assertSame('organizations/blurrg-squad-128x128.png', $blurrg->image_path_lg);
        $this->assertSame('organizations/blurrg-squad-32x32.png', $blurrg->image_path_sm);
        Storage::disk('public')->assertExists([$blurrg->image_path_lg, $blurrg->image_path_sm]);
    }

    public function test_only_the_garrison_and_blurrg_get_an_image(): void
    {
        Storage::fake('public');

        $this->seed(OrganizationSeeder::class);
        $this->seed(MidwestGarrisonOrganizationSeeder::class);

        $with_image = Organization::whereNotNull(Organization::IMAGE_PATH_SM)
            ->orderBy(Organization::NAME)
            ->pluck(Organization::NAME)
            ->all();

        $this->assertSame(['332nd Blurrg Squad', 'Midwest Garrison'], $with_image);
    }
}
