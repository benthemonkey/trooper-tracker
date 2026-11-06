<?php

declare(strict_types=1);

namespace Database\Seeders\FloridaGarrison;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationType;
use App\Models\Base\Organization;
use App\Models\TrooperAssignment;
use App\Models\TrooperOrganization;
use Database\Seeders\FloridaGarrison\Support\LegacyGarrison;
use Database\Seeders\FloridaGarrison\Traits\HasClubMaps;
use Database\Seeders\FloridaGarrison\Traits\HasEnumMaps;
use Database\Seeders\FloridaGarrison\Traits\HasSquadMaps;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TrooperOrganizationSeeder extends Seeder
{
    use HasEnumMaps;
    use HasClubMaps;
    use HasSquadMaps;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $legacy_troopers = DB::table('troopers')->get();

        foreach ($legacy_troopers as $trooper)
        {
            $this->assignOrganizationAndRegion($trooper);
            $this->assignUnit($trooper);
        }

        if (LegacyGarrison::placesRemainingMembersInClub())
        {
            $this->placeRemainingMembersInClub();
        }
    }

    /**
     * Keeps the assignment's notification and moderator settings: unlike Issues\Fix242, which
     * makes the same repair, this doesn't subscribe anyone to anything.
     */
    private function placeRemainingMembersInClub()
    {
        $member_clubs = [];

        $member_assignments = TrooperAssignment::query()
            ->with('organization')
            ->where(TrooperAssignment::IS_MEMBER, true)
            ->get();

        foreach ($member_assignments as $assignment)
        {
            $club_id = (int) strtok($assignment->organization->node_path, ':');

            $member_clubs[$assignment->trooper_id][$club_id] = true;
        }

        foreach (TrooperOrganization::query()->get() as $membership)
        {
            if (isset($member_clubs[$membership->trooper_id][$membership->organization_id]))
            {
                continue;
            }

            $assignment = TrooperAssignment::query()
                ->where(TrooperAssignment::TROOPER_ID, $membership->trooper_id)
                ->where(TrooperAssignment::ORGANIZATION_ID, $membership->organization_id)
                ->first() ?? new TrooperAssignment();

            $assignment->trooper_id = $membership->trooper_id;
            $assignment->organization_id = $membership->organization_id;
            $assignment->is_member = true;

            $assignment->save();
        }
    }

    private function assignOrganizationAndRegion($trooper)
    {
        $club_map = $this->getOrganizationClubMap();

        foreach ($club_map as $column => $club)
        {
            $legacy_id = $club['legacy_id'];

            if (!isset($club['id']))
            {
                //  can't map to the new club
                continue;
            }

            if ($trooper->{$club['permission_column']} < 1)
            {
                //  permission flag says not a member — the old signup form required an
                //  identifier from everyone regardless of club, so a stray one doesn't count
                continue;
            }

            $organization = $this->getOrganization($club['id']);

            $identifier = '';

            if ($club['identity'] != '')
            {
                //  get the club identifier
                $identifier = $trooper->{$club['identity']};
            }

            $has_identifier = !$this->isHandlerWithoutMembership($trooper, $column)
                && $identifier != null && $identifier != '' && $identifier != '0';
            $flag_is_enough = !$has_identifier && $this->flagAloneMakesMember($trooper, $column);

            if ($has_identifier || $flag_is_enough)
            {
                $this->loadTrooperOrganization($trooper, $organization, $has_identifier ? $identifier : null);
            }

            //* 0 = Regular Member, 1 = Super Admin, 2 = Moderator, 3 = RIP Member, 4 = Handler
            $moderator = $trooper->permissions == 2
                && LegacyGarrison::isCommandStaff((int) $trooper->id)
                && ($trooper->{$column} == 1 || $trooper->{$column} == 2);
            $member = $has_identifier || $flag_is_enough;
            $notify = $trooper->{'esquad' . $legacy_id} == 1;

            if (!$notify && !$moderator && !$member)
            {
                //  not getting notified, skip
                continue;
            }

            $region = $organization->organizations->first();

            $this->loadTrooperAssignment($trooper->id, $organization->id, $notify, false, false);
            $this->loadTrooperAssignment($trooper->id, $region->id, $notify, false, $moderator);
        }
    }

    /**
     * Only an approved regular (1) or reserve (2) member counts: retired members and handlers
     * aren't current members, and an unapproved account's flag is unverified.
     */
    private function flagAloneMakesMember($trooper, string $column): bool
    {
        return LegacyGarrison::acceptsFlagAlone($column)
            && $trooper->approved == 1
            && (int) $trooper->id !== LegacyGarrison::placeholderId()
            && in_array((int) $trooper->{$column}, [1, 2], true);
    }

    private function isHandlerWithoutMembership($trooper, string $column): bool
    {
        return $trooper->{$column} == 4 && !LegacyGarrison::handlersAreMembers();
    }

    private function assignUnit($trooper)
    {
        $squad_map = $this->getSquadMap();

        foreach ($squad_map as $legacy_id => $squad)
        {
            $notify = $trooper->{'esquad' . $legacy_id} == 1;

            $member = false;

            if ($trooper->squad == $legacy_id && $trooper->p501 >= 1 && !$this->isHandlerWithoutMembership($trooper, 'p501'))
            {
                //  all HasSquadMaps squads are 501st units — same reasoning as the permission
                //  check above, the squad field alone isn't membership
                $member = true;
            }

            if ($legacy_id === array_key_first($squad_map) && LegacyGarrison::isTestCoordinator((int) $trooper->id))
            {
                //  a test coordinator needs a membership to host events
                $member = true;
            }

            if (!$member && !$notify)
            {
                //  not a member and not notified
                continue;
            }

            $unit = $this->getOrganization($squad['id']);

            //  a legacy squad can map to the region itself (Midwest's main squad is the garrison)
            $is_region = $unit->type === OrganizationType::REGION->value;
            $region_id = $is_region ? $unit->id : $unit->parent_id;

            $region_assignment = TrooperAssignment::query()
                ->where(TrooperAssignment::ORGANIZATION_ID, $region_id)
                ->where(TrooperAssignment::TROOPER_ID, $trooper->id)
                ->first();

            $moderator = $region_assignment->is_moderator ?? false;

            $this->loadTrooperAssignment($trooper->id, $unit->id, $notify || $member, $member, $moderator);
        }
    }

    private function loadTrooperOrganization($trooper, $organization, $identifier)
    {
        //  blank identifiers aren't unique: where() would turn null into IS NULL and match
        //  the club's first member without one
        $exists = $identifier !== null && TrooperOrganization::query()
            ->where(TrooperOrganization::ORGANIZATION_ID, $organization->id)
            ->where(TrooperOrganization::IDENTIFIER, $identifier)
            ->exists();

        if ($exists)
        {
            //  already exists, skip
            return;
        }

        $trooper_org = TrooperOrganization::query()
            ->where(TrooperOrganization::TROOPER_ID, $trooper->id)
            ->where(TrooperOrganization::ORGANIZATION_ID, $organization->id)
            ->first();

        if ($trooper_org === null)
        {
            $trooper_org = new TrooperOrganization();

            $trooper_org->trooper_id = $trooper->id;
            $trooper_org->organization_id = $organization->id;
        }

        $trooper_org->membership_status = MembershipStatus::ACTIVE;
        $trooper_org->identifier = $identifier;

        $trooper_org->save();
    }

    private function loadTrooperAssignment($trooper_id, $organization_id, $notify, $member, $moderator)
    {
        $trooper_assignment = TrooperAssignment::query()
            ->where(TrooperAssignment::TROOPER_ID, $trooper_id)
            ->where(TrooperAssignment::ORGANIZATION_ID, $organization_id)
            ->first();

        if ($trooper_assignment === null)
        {
            $trooper_assignment = new TrooperAssignment();

            $trooper_assignment->trooper_id = $trooper_id;
            $trooper_assignment->organization_id = $organization_id;
        }

        $trooper_assignment->should_notify = $notify;
        $trooper_assignment->is_member = $member;
        $trooper_assignment->is_moderator = $moderator;

        $trooper_assignment->save();
    }

    private function getOrganization($id)
    {
        $organizations = once(fn() => Organization::all()->keyBy('id'));

        return $organizations[$id];
    }
}