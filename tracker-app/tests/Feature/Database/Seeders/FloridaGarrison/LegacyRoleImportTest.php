<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders\FloridaGarrison;

use App\Enums\MembershipRole;
use App\Models\Trooper;
use Carbon\Carbon;
use Database\Seeders\FloridaGarrison\Support\LegacyGarrison;
use Database\Seeders\FloridaGarrison\TrooperSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Seeders\Issues\Concerns\SeedsLegacyTables;
use Tests\TestCase;

/**
 * A garrison can import handlers and 501st members of other garrisons with the roles made for
 * them. TT1.0 only told them apart through its club flags and a squad value.
 */
class LegacyRoleImportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLegacyTables;

    private const array MIDWEST_RULES = [
        'name' => 'Florida Garrison',
        'handlers_are_members' => false,
        'visitor_squad' => 0,
        'squads' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacyTrooperTable();

        Schema::table('troopers', function (Blueprint $table): void
        {
            foreach (['legal_name', 'phone', 'email', 'password', 'last_active', 'datecreated'] as $column)
            {
                $table->string($column)->nullable();
            }

            foreach (['efast', 'econfirm', 'ecommandnotify'] as $column)
            {
                $table->integer($column)->default(0);
            }

            $table->integer('approved')->default(1);
        });

        Carbon::setTestNow('2026-11-06 12:00:00');
    }

    protected function tearDown(): void
    {
        LegacyGarrison::reset();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_handler_flag_with_no_other_membership_imports_as_a_handler(): void
    {
        $this->legacy(1, ['squad' => 14, 'p501' => 4]);
        $this->legacy(2, ['squad' => 9, 'pOther' => 4]);
        $this->legacy(3, ['squad' => 14, 'p501' => 4, 'pRebel' => 1]);

        $this->import(self::MIDWEST_RULES);

        $this->assertSame(MembershipRole::HANDLER, $this->role(1));
        $this->assertSame(MembershipRole::HANDLER, $this->role(2));
        //  a real member of another club stays a member
        $this->assertSame(MembershipRole::MEMBER, $this->role(3));
    }

    public function test_a_501st_member_in_the_visitor_squad_imports_as_a_visitor(): void
    {
        $this->legacy(1, ['squad' => 0, 'p501' => 1]);
        $this->legacy(2, ['squad' => 14, 'p501' => 1]);
        //  squad 0 is also the column's default, so it means nothing without the 501st flag
        $this->legacy(3, ['squad' => 0, 'p501' => 0, 'pRebel' => 1]);

        $this->import(self::MIDWEST_RULES);

        $this->assertSame(MembershipRole::VISITOR, $this->role(1));
        $this->assertEquals(Carbon::parse('2027-05-06 12:00:00'), Trooper::findOrFail(1)->visitor_expires_at);

        $this->assertSame(MembershipRole::MEMBER, $this->role(2));
        $this->assertNull(Trooper::findOrFail(2)->visitor_expires_at);

        $this->assertSame(MembershipRole::MEMBER, $this->role(3));
    }

    public function test_an_unapproved_visitor_gets_their_window_on_approval(): void
    {
        $this->legacy(1, ['squad' => 0, 'p501' => 1, 'approved' => 0]);

        $this->import(self::MIDWEST_RULES);

        $this->assertSame(MembershipRole::VISITOR, $this->role(1));
        $this->assertNull(Trooper::findOrFail(1)->visitor_expires_at);
    }

    public function test_staff_keep_their_role(): void
    {
        $this->legacy(1, ['squad' => 0, 'p501' => 1, 'permissions' => 1]);
        $this->legacy(2, ['squad' => 14, 'p501' => 4, 'permissions' => 2]);

        $this->import(self::MIDWEST_RULES);

        $this->assertSame(MembershipRole::SYSTEM_OWNER, $this->role(1));
        $this->assertSame(MembershipRole::MODERATOR, $this->role(2));
    }

    public function test_a_test_coordinator_is_never_a_visitor(): void
    {
        $this->legacy(1, ['squad' => 0, 'p501' => 1]);

        $this->import(self::MIDWEST_RULES + ['test_coordinator_ids' => [1]]);

        $this->assertSame(MembershipRole::EVENT_COORDINATOR, $this->role(1));
        $this->assertNull(Trooper::findOrFail(1)->visitor_expires_at);
    }

    public function test_without_the_settings_everyone_stays_a_member(): void
    {
        $this->legacy(1, ['squad' => 14, 'p501' => 4]);
        $this->legacy(2, ['squad' => 0, 'p501' => 1]);

        $this->import(['name' => 'Florida Garrison', 'squads' => []]);

        $this->assertSame(MembershipRole::MEMBER, $this->role(1));
        $this->assertSame(MembershipRole::MEMBER, $this->role(2));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function legacy(int $id, array $attributes): void
    {
        DB::table('troopers')->insert(array_merge([
            'id' => $id,
            'name' => "Legacy {$id}",
            'forum_id' => '',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function import(array $profile): void
    {
        LegacyGarrison::use($profile);

        //  db:seed unguards models, which is what lets the import keep legacy ids
        Model::unguarded(fn () => (new TrooperSeeder)->run());
    }

    private function role(int $id): MembershipRole
    {
        return Trooper::findOrFail($id)->membership_role;
    }
}
