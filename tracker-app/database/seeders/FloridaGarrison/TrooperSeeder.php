<?php

declare(strict_types=1);

namespace Database\Seeders\FloridaGarrison;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\NotificationFrequency;
use App\Models\Trooper;
use Database\Seeders\FloridaGarrison\Support\LegacyGarrison;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TrooperSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $legacy_troopers = DB::table('troopers')->get();

        foreach ($legacy_troopers as $trooper)
        {
            $t = Trooper::find($trooper->id) ?? new Trooper(['id' => $trooper->id]);

            $t->legal_name = trim($trooper->legal_name ?? $trooper->name);
            $t->display_name = $this->getDisplayName($trooper);
            $t->phone = $trooper->phone;
            $t->email = $trooper->email ?? '^' . uniqid();
            $t->password = $trooper->password ?? '^' . uniqid();

            $t->last_active_at = $trooper->last_active;
            $t->created_at = $trooper->datecreated;

            // $t->instant_notification = $trooper->efast;
            // $t->attendance_notification = $trooper->econfirm;
            // $t->command_staff_notification = $trooper->ecommandnotify;

            if ($trooper->efast)
            {
                $t->notification_frequency = NotificationFrequency::INSTANT;
            }
            else
            {
                $t->notification_frequency = NotificationFrequency::DAILY;
            }

            if ($trooper->approved)
            {
                $t->membership_status = match ((int) $trooper->permissions)
                {
                    0 => MembershipStatus::ACTIVE,
                    // 1 => MembershipStatus::Admin,
                    // 2 => MembershipStatus::Moderator,
                    3 => MembershipStatus::RETIRED,
                    default => MembershipStatus::ACTIVE,
                };
            }
            else
            {
                $t->membership_status = MembershipStatus::PENDING;
            }

            $t->membership_role = match ((int) $trooper->permissions)
            {
                0 => MembershipRole::MEMBER,
                1 => MembershipRole::ADMINISTRATOR,
                2 => LegacyGarrison::isCommandStaff((int) $trooper->id)
                    ? MembershipRole::MODERATOR
                    : MembershipRole::EVENT_COORDINATOR,
                //3 => MembershipRole::Retired,
                4 => MembershipRole::HANDLER,
                default => MembershipRole::MEMBER,
            };

            if ($t->membership_role === MembershipRole::MEMBER && $this->isOnlyAHandler($trooper))
            {
                $t->membership_role = MembershipRole::HANDLER;
            }

            if ($t->membership_role === MembershipRole::MEMBER && $this->isVisitingMember($trooper))
            {
                $t->membership_role = MembershipRole::VISITOR;

                if ($trooper->approved)
                {
                    //  the window a visitor gets on approval; an unapproved one gets it then
                    $t->visitor_expires_at = now()->addMonths(6);
                }
            }

            if (LegacyGarrison::isTestCoordinator((int) $trooper->id))
            {
                $t->membership_role = MembershipRole::EVENT_COORDINATOR;
                $t->visitor_expires_at = null;
            }

            $t->save();
        }
    }

    private function isOnlyAHandler($trooper): bool
    {
        if (LegacyGarrison::handlersAreMembers())
        {
            return false;
        }

        $flags = [];

        foreach (['p501', 'pRebel', 'pDroid', 'pMando', 'pOther', 'pSG', 'pDE'] as $column)
        {
            $flags[] = (int) $trooper->{$column};
        }

        //  1 = regular, 2 = reserve, 4 = handler
        return in_array(4, $flags, true) && array_intersect([1, 2], $flags) === [];
    }

    private function isVisitingMember($trooper): bool
    {
        return LegacyGarrison::visitorSquad() === (int) $trooper->squad
            && in_array((int) $trooper->p501, [1, 2], true);
    }

    private function getDisplayName($trooper): string
    {
        $display_name = trim($trooper->forum_id);

        if (!empty($display_name))
        {
            return $display_name;
        }

        return $trooper->name;
    }
}