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
use Database\Seeders\MidwestGarrison\EventPhotoAttendeeTagSeeder;
use Database\Seeders\MidwestGarrison\OrganizationSeeder as MidwestGarrisonOrganizationSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
            //  TT1.0 moderators who are actual command staff; the rest of the TT1.0 moderators
            //  run events and import as event coordinators
            'command_staff_ids' => [
                2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 19, 40, 71, 86, 88, 117, 118, 124, 125, 184,
                185, 191, 194, 223, 227,
            ],
            //  shared test account, so command staff can try the event coordinator role
            'test_coordinator_ids' => [319],
            //  club rosters were verified when these flags were set, and some list no club id
            'clubs_accepting_flag_alone' => ['pRebel', 'pMando', 'pSG', 'pDE'],
            'handlers_are_members' => false,
            //  501st members of other garrisons, who only need access to events when they visit
            'visitor_squad' => 0,
            'place_remaining_members_in_club' => true,
            'squads' => [
                'Midwest Garrison' => [
                    'legacy_id' => 14,
                    //  unused by the import; MidwestGarrison\OrganizationSeeder sets the emblem
                    'logo' => '',
                    'eventForum' => 222,
                    'userGroup' => 1504,
                ],
                '332nd Blurrg Squad' => [
                    'legacy_id' => 15,
                    'logo' => '',
                    'eventForum' => 222,
                    'userGroup' => 1503,
                ],
            ],
        ]);

        //  one transaction: tens of thousands of single-row writes otherwise each pay for a
        //  commit, and a failed import leaves nothing half-written
        $ms = Benchmark::measure(fn () => DB::transaction(function ()
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
            $this->call(EventPhotoAttendeeTagSeeder::class);
            $this->call(TrooperFriendSeeder::class);

            $this->call(TrooperAchievementSeeder::class);

            $this->call(PlaceholderTrooperSeeder::class);
        }));

        $readable = CarbonInterval::millisecond($ms)->cascade()->forHumans();

        $this->command->info("Midwest Garrison seeding completed in {$readable}.");

        //  the club-scoped and donation milestones the import doesn't compute. Created as already
        //  notified; the daily 02:00 run would otherwise queue a notification for each one.
        Artisan::call('tracker:calculate-trooper-achievements', ['--without-notifications' => true]);
    }
}
