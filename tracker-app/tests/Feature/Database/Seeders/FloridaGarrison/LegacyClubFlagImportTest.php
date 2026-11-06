<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders\FloridaGarrison;

use App\Models\Organization;
use App\Models\Trooper;
use App\Models\TrooperAssignment;
use App\Models\TrooperOrganization;
use Database\Seeders\FloridaGarrison\Support\LegacyGarrison;
use Database\Seeders\FloridaGarrison\TrooperOrganizationSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Seeders\Issues\Concerns\SeedsLegacyTables;
use Tests\TestCase;

/**
 * A garrison can adjust who imports as a club member: a TT1.0 flag can prove membership without
 * a club id, a handler flag can give no membership, and members with no squad can join the club
 * itself. Without those settings a membership needs a flag and an id, as in Florida.
 */
class LegacyClubFlagImportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLegacyTables;

    /** @var array<string, Organization> */
    private array $regions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLegacyWorld();

        foreach (self::CLUB_NAMES as $name)
        {
            $this->regions[$name] = $this->regionOf($name);
        }

        Schema::table('troopers', function (Blueprint $table): void
        {
            $table->integer('approved')->default(1);
        });
    }

    protected function tearDown(): void
    {
        LegacyGarrison::reset();

        parent::tearDown();
    }

    public function test_every_flagged_member_without_an_id_gets_a_blank_membership(): void
    {
        $first = $this->flagged(['pMando' => 1]);
        $second = $this->flagged(['pMando' => 1]);
        $reserve = $this->flagged(['pMando' => 2]);

        $this->import(['pMando']);

        foreach ([$first, $second, $reserve] as $trooper)
        {
            $membership = $this->membership($trooper, 'Mandalorian Mercs');

            $this->assertNotNull($membership);
            $this->assertNull($membership->identifier);
        }
    }

    public function test_a_flagged_member_with_an_id_keeps_it(): void
    {
        $trooper = $this->flagged(['pMando' => 1, 'mandoid' => '1234']);

        $this->import(['pMando']);

        $this->assertSame('1234', $this->membership($trooper, 'Mandalorian Mercs')->identifier);
    }

    public function test_only_approved_regular_and_reserve_members_count(): void
    {
        $retired = $this->flagged(['pMando' => 3]);
        $handler = $this->flagged(['pMando' => 4]);
        $unapproved = $this->flagged(['pMando' => 1, 'approved' => 0]);

        $this->import(['pMando']);

        foreach ([$retired, $handler, $unapproved] as $trooper)
        {
            $this->assertNull($this->membership($trooper, 'Mandalorian Mercs'));
        }
    }

    public function test_the_placeholder_trooper_is_never_a_member(): void
    {
        $placeholder = $this->flagged(['pRebel' => 1]);

        LegacyGarrison::use([
            'name' => 'Florida Garrison',
            'placeholder_id' => $placeholder->id,
            'clubs_accepting_flag_alone' => ['pRebel'],
            'squads' => [],
        ]);

        (new TrooperOrganizationSeeder)->run();

        $this->assertNull($this->membership($placeholder, 'Rebel Legion'));
    }

    public function test_a_club_off_the_list_still_needs_an_id(): void
    {
        $trooper = $this->flagged(['p501' => 1, 'pMando' => 1]);

        $this->import(['pMando']);

        $this->assertNull($this->membership($trooper, '501st Legion'));
        $this->assertNotNull($this->membership($trooper, 'Mandalorian Mercs'));
    }

    public function test_without_a_list_a_flag_alone_is_not_a_membership(): void
    {
        $trooper = $this->flagged(['pMando' => 1]);

        LegacyGarrison::use(['name' => 'Florida Garrison', 'squads' => []]);

        (new TrooperOrganizationSeeder)->run();

        $this->assertNull($this->membership($trooper, 'Mandalorian Mercs'));
    }

    public function test_a_handler_flag_gives_no_membership_when_the_garrison_says_so(): void
    {
        $squad = Organization::factory()
            ->asUnit()
            ->withParent($this->regions['501st Legion'])
            ->withName('Test Squad')
            ->create();

        $handler = $this->flagged(['squad' => 1, 'p501' => 4, 'tkid' => '111']);
        $droid_handler = $this->flagged(['pDroid' => 4, 'forum_id' => 'forum-name']);
        $member = $this->flagged(['squad' => 1, 'p501' => 1, 'tkid' => '222']);

        LegacyGarrison::use([
            'name' => 'Florida Garrison',
            'handlers_are_members' => false,
            'squads' => ['Test Squad' => ['legacy_id' => 1, 'logo' => '', 'eventForum' => 0, 'userGroup' => 0]],
        ]);

        (new TrooperOrganizationSeeder)->run();

        $this->assertNull($this->membership($handler, '501st Legion'));
        $this->assertNull($this->membership($droid_handler, 'Droid Builders'));
        $this->assertFalse($this->isMemberOf($handler, $squad));

        $this->assertNotNull($this->membership($member, '501st Legion'));
        $this->assertTrue($this->isMemberOf($member, $squad));
    }

    public function test_a_handler_flag_still_counts_without_the_setting(): void
    {
        $droid_handler = $this->flagged(['pDroid' => 4, 'forum_id' => 'forum-name']);

        LegacyGarrison::use(['name' => 'Florida Garrison', 'squads' => []]);

        (new TrooperOrganizationSeeder)->run();

        $this->assertNotNull($this->membership($droid_handler, 'Droid Builders'));
    }

    public function test_members_with_no_squad_become_members_of_the_club_itself(): void
    {
        $squad = Organization::factory()
            ->asUnit()
            ->withParent($this->regions['501st Legion'])
            ->withName('Test Squad')
            ->create();

        //  esquad0 and esquad6 subscribe to the 501st and Rebel Legion
        $in_squad_and_club = $this->flagged(['squad' => 1, 'p501' => 1, 'tkid' => '111', 'pRebel' => 1, 'rebelforum' => 'one', 'esquad6' => 1]);
        $visitor = $this->flagged(['squad' => 0, 'p501' => 1, 'tkid' => '222', 'esquad0' => 1]);

        LegacyGarrison::use([
            'name' => 'Florida Garrison',
            'place_remaining_members_in_club' => true,
            'squads' => ['Test Squad' => ['legacy_id' => 1, 'logo' => '', 'eventForum' => 0, 'userGroup' => 0]],
        ]);

        (new TrooperOrganizationSeeder)->run();

        //  already placed in a squad, so not added to the club above it
        $this->assertTrue($this->isMemberOf($in_squad_and_club, $squad));
        $this->assertFalse($this->isMemberOf($in_squad_and_club, $this->club('501st Legion')));

        $this->assertTrue($this->isMemberOf($in_squad_and_club, $this->club('Rebel Legion')));
        $this->assertTrue($this->isMemberOf($visitor, $this->club('501st Legion')));

        //  and notification settings survive
        $assignment = TrooperAssignment::query()
            ->where(TrooperAssignment::TROOPER_ID, $visitor->id)
            ->where(TrooperAssignment::ORGANIZATION_ID, $this->clubId('501st Legion'))
            ->first();

        $this->assertTrue($assignment->should_notify);
    }

    public function test_members_stay_unplaced_without_the_setting(): void
    {
        $trooper = $this->flagged(['pRebel' => 1, 'rebelforum' => 'one', 'esquad6' => 1]);

        LegacyGarrison::use(['name' => 'Florida Garrison', 'squads' => []]);

        (new TrooperOrganizationSeeder)->run();

        $this->assertNotNull($this->membership($trooper, 'Rebel Legion'));
        $this->assertFalse($this->isMemberOf($trooper, $this->club('Rebel Legion')));
    }

    private function isMemberOf(Trooper $trooper, Organization $organization): bool
    {
        return TrooperAssignment::query()
            ->where(TrooperAssignment::TROOPER_ID, $trooper->id)
            ->where(TrooperAssignment::ORGANIZATION_ID, $organization->id)
            ->where(TrooperAssignment::IS_MEMBER, true)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function flagged(array $attributes): Trooper
    {
        $trooper = Trooper::factory()->asActive()->create();

        $this->legacyTrooper($trooper, $attributes);

        return $trooper;
    }

    /**
     * @param  list<string>  $clubs_accepting_flag_alone
     */
    private function import(array $clubs_accepting_flag_alone): void
    {
        LegacyGarrison::use([
            'name' => 'Florida Garrison',
            'clubs_accepting_flag_alone' => $clubs_accepting_flag_alone,
            'squads' => [],
        ]);

        (new TrooperOrganizationSeeder)->run();
    }

    private function membership(Trooper $trooper, string $club): ?TrooperOrganization
    {
        return TrooperOrganization::query()
            ->where(TrooperOrganization::TROOPER_ID, $trooper->id)
            ->where(TrooperOrganization::ORGANIZATION_ID, $this->clubId($club))
            ->first();
    }
}
