<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonInterval;
use Database\Seeders\FloridaGarrison\AwardSeeder;
use Database\Seeders\FloridaGarrison\CostumeSeeder;
use Database\Seeders\FloridaGarrison\EventSeeder;
use Database\Seeders\FloridaGarrison\EventUploadSeeder;
use Database\Seeders\FloridaGarrison\EventUploadTrooperSeeder;
use Database\Seeders\FloridaGarrison\OrganizationCostumeSeeder;
use Database\Seeders\FloridaGarrison\PlaceholderTrooperSeeder;
use Database\Seeders\FloridaGarrison\Support\LegacyGarrison;
use Database\Seeders\FloridaGarrison\TrooperAchievementSeeder;
use Database\Seeders\FloridaGarrison\TrooperCostumeSeeder;
use Database\Seeders\FloridaGarrison\TrooperDonationSeeder;
use Database\Seeders\FloridaGarrison\TrooperFriendSeeder;
use Database\Seeders\FloridaGarrison\TrooperOrganizationSeeder;
use Database\Seeders\FloridaGarrison\TrooperSeeder;
use Database\Seeders\MidwestGarrison\OrganizationSeeder as MidwestGarrisonOrganizationSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\Artisan;

/**
 * Imports a Midwest Garrison TT1.0 database, reusing the Florida import seeders with the
 * Midwest garrison profile. Same table expectations as FloridaGarrisonSeeder.
 */
class MidwestGarrisonSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        LegacyGarrison::use([
            'name' => 'Midwest Garrison',
            'placeholder_id' => 240,
            'squads' => [
                'Midwest Garrison' => [
                    'legacy_id' => 14,
                    'logo' => 'garrison_emblem.png',
                    'eventForum' => 404,
                    'userGroup' => 44,
                ],
                '332nd Blurrg Squad' => [
                    'legacy_id' => 15,
                    'logo' => '',
                    'eventForum' => 404,
                    'userGroup' => 44,
                ],
            ],
        ]);

        $ms = Benchmark::measure(function ()
        {
            $this->call(CostumeSeeder::class);
            $this->call(OrganizationSeeder::class);
            $this->call(MidwestGarrisonOrganizationSeeder::class);
            $this->call(OrganizationCostumeSeeder::class);

            $this->call(TrooperSeeder::class);
            $this->call(TrooperDonationSeeder::class);
            $this->call(TrooperOrganizationSeeder::class);
            $this->call(TrooperCostumeSeeder::class);

            $this->call(AwardSeeder::class);

            $this->call(FaqSeeder::class);

            $this->call(EventSeeder::class);
            $this->call(EventUploadSeeder::class);
            $this->call(EventUploadTrooperSeeder::class);
            $this->call(TrooperFriendSeeder::class);

            $this->call(TrooperAchievementSeeder::class);

            $this->call(PlaceholderTrooperSeeder::class);
        });

        $readable = CarbonInterval::millisecond($ms)->cascade()->forHumans();

        $this->command->info("Midwest Garrison seeding completed in {$readable}.");
    }
}
