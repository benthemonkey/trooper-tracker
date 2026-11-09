<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders\FloridaGarrison;

use App\Enums\MembershipRole;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Trooper;
use App\Models\TrooperAssignment;
use Carbon\Carbon;
use Database\Seeders\FloridaGarrison\EventSeeder;
use Database\Seeders\FloridaGarrison\Support\LegacyGarrison;
use Database\Seeders\FloridaGarrison\TrooperOrganizationSeeder;
use Database\Seeders\FloridaGarrison\TrooperSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Seeders\Issues\Concerns\SeedsLegacyTables;
use Tests\TestCase;

/**
 * TT1.0 had no role between member and moderator, so a garrison can import its moderators as
 * event coordinators, except for a command staff list, and keep who created each event.
 */
class LegacyEventCoordinatorImportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLegacyTables;

    private Organization $garrison;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLegacyWorld();
        $this->garrison = $this->regionOf('501st Legion', 'Florida Garrison');

        foreach (array_keys(LegacyGarrison::squads()) as $squad)
        {
            Organization::factory()->asUnit()->withParent($this->garrison)->withName($squad)->create();
        }
    }

    protected function tearDown(): void
    {
        LegacyGarrison::reset();

        parent::tearDown();
    }

    public function test_without_a_command_staff_list_every_moderator_is_command_staff(): void
    {
        $this->assertTrue(LegacyGarrison::isCommandStaff(42));

        $this->useProfileWithCommandStaff([7]);

        $this->assertTrue(LegacyGarrison::isCommandStaff(7));
        $this->assertFalse(LegacyGarrison::isCommandStaff(42));
    }

    public function test_only_listed_command_staff_moderate_the_garrison(): void
    {
        $staff = Trooper::factory()->asActive()->create();
        $coordinator = Trooper::factory()->asActive()->create();

        foreach ([$staff, $coordinator] as $trooper)
        {
            $this->legacyTrooper($trooper, ['permissions' => 2, 'p501' => 1, 'tkid' => 'TK'.$trooper->id]);
        }

        $this->useProfileWithCommandStaff([$staff->id]);

        (new TrooperOrganizationSeeder)->run();

        $this->assertTrue($this->moderatesGarrison($staff));
        $this->assertFalse($this->moderatesGarrison($coordinator));
    }

    public function test_trooper_seeder_imports_moderators_off_the_list_as_event_coordinators(): void
    {
        $this->addTrooperSeederColumns();

        foreach ([101 => 1, 102 => 2, 103 => 2, 104 => 0] as $id => $permissions)
        {
            DB::table('troopers')->insert([
                'id' => $id,
                'name' => "Legacy {$id}",
                'permissions' => $permissions,
                'approved' => 1,
                'forum_id' => '',
            ]);
        }

        $this->useProfileWithCommandStaff([102]);

        //  db:seed unguards models, which is what lets the import keep legacy ids
        Model::unguarded(fn () => (new TrooperSeeder)->run());

        $this->assertSame(MembershipRole::SYSTEM_OWNER, Trooper::findOrFail(101)->membership_role);
        $this->assertSame(MembershipRole::MODERATOR, Trooper::findOrFail(102)->membership_role);
        $this->assertSame(MembershipRole::EVENT_COORDINATOR, Trooper::findOrFail(103)->membership_role);
        $this->assertSame(MembershipRole::MEMBER, Trooper::findOrFail(104)->membership_role);
    }

    public function test_test_coordinators_import_as_event_coordinators_whatever_their_permissions(): void
    {
        $this->addTrooperSeederColumns();

        foreach ([201 => 0, 202 => 0] as $id => $permissions)
        {
            DB::table('troopers')->insert([
                'id' => $id,
                'name' => "Legacy {$id}",
                'permissions' => $permissions,
                'approved' => 1,
                'forum_id' => '',
            ]);
        }

        LegacyGarrison::use([
            'name' => 'Florida Garrison',
            'test_coordinator_ids' => [201],
            'squads' => [],
        ]);

        Model::unguarded(fn () => (new TrooperSeeder)->run());

        $this->assertSame(MembershipRole::EVENT_COORDINATOR, Trooper::findOrFail(201)->membership_role);
        $this->assertSame(MembershipRole::MEMBER, Trooper::findOrFail(202)->membership_role);
    }

    public function test_test_coordinators_become_members_of_the_first_squad(): void
    {
        $squads = LegacyGarrison::squads();
        $first_squad = Organization::firstWhere(Organization::NAME, array_key_first($squads));

        $tester = Trooper::factory()->asActive()->create();
        $member = Trooper::factory()->asActive()->create();

        //  no 501st flag or squad: TT1.0 alone would give neither a membership
        $this->legacyTrooper($tester);
        $this->legacyTrooper($member);

        LegacyGarrison::use([
            'name' => 'Florida Garrison',
            'test_coordinator_ids' => [$tester->id],
            'squads' => $squads,
        ]);

        (new TrooperOrganizationSeeder)->run();

        $this->assertTrue($this->isMemberOf($tester, $first_squad));
        $this->assertFalse($this->isMemberOf($member, $first_squad));
    }

    public function test_event_seeder_keeps_the_legacy_event_creator(): void
    {
        Schema::table('events', function (Blueprint $table): void
        {
            $table->unsignedBigInteger('createdBy')->nullable();
        });

        $coordinator = Trooper::factory()->asActive()->create();
        $this->legacyTrooper($coordinator);

        $this->legacyEvent(6001, Carbon::parse('2026-05-02 10:00:00'));
        $this->legacyEvent(6002, Carbon::parse('2026-05-09 10:00:00'));
        DB::table('events')->where('id', 6001)->update(['createdBy' => $coordinator->id]);
        DB::table('events')->where('id', 6002)->update(['createdBy' => 999999]);

        $subject = new EventSeeder;
        Model::unguarded(fn () => $subject->run());

        $this->assertSame($coordinator->id, Event::findOrFail(6001)->created_id);
        $this->assertNull(Event::findOrFail(6002)->created_id);
    }

    /**
     * @param  list<int>  $command_staff_ids
     */
    private function useProfileWithCommandStaff(array $command_staff_ids): void
    {
        LegacyGarrison::use([
            'name' => 'Florida Garrison',
            'command_staff_ids' => $command_staff_ids,
            'squads' => [],
        ]);
    }

    private function addTrooperSeederColumns(): void
    {
        Schema::table('troopers', function (Blueprint $table): void
        {
            foreach (['legal_name', 'phone', 'email', 'password', 'last_active', 'datecreated'] as $column)
            {
                $table->string($column)->nullable();
            }

            foreach (['efast', 'econfirm', 'ecommandnotify', 'approved'] as $column)
            {
                $table->integer($column)->default(0);
            }
        });
    }

    private function isMemberOf(Trooper $trooper, Organization $organization): bool
    {
        return TrooperAssignment::query()
            ->where(TrooperAssignment::TROOPER_ID, $trooper->id)
            ->where(TrooperAssignment::ORGANIZATION_ID, $organization->id)
            ->where(TrooperAssignment::IS_MEMBER, true)
            ->exists();
    }

    private function moderatesGarrison(Trooper $trooper): bool
    {
        return TrooperAssignment::query()
            ->where(TrooperAssignment::TROOPER_ID, $trooper->id)
            ->where(TrooperAssignment::ORGANIZATION_ID, $this->garrison->id)
            ->where(TrooperAssignment::IS_MODERATOR, true)
            ->exists();
    }
}
