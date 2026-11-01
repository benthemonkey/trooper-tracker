<?php

declare(strict_types=1);

namespace Database\Seeders\FloridaGarrison\Support;

/**
 * Garrison-specific facts the TT1.0 import needs: the region legacy squad 0 maps to, the
 * legacy squad ids, and the placeholder trooper. Defaults to Florida so the existing
 * FloridaGarrisonSeeder is unchanged.
 */
final class LegacyGarrison
{
    private static ?array $profile = null;

    /**
     * @param  array{name: string, placeholder_id?: int|null, command_staff_ids?: list<int>|null, test_coordinator_ids?: list<int>, squads: array<string, array<string, mixed>>}  $profile
     */
    public static function use(array $profile): void
    {
        self::$profile = $profile;
    }

    /**
     * Back to the Florida default.
     */
    public static function reset(): void
    {
        self::$profile = null;
    }

    public static function name(): string
    {
        return self::profile()['name'];
    }

    /**
     * The TT1.0 trooper that stood in for guests (cred.php `placeholder`), whose sign-ups
     * carry the guest's name in the note. Null when the garrison had none.
     */
    public static function placeholderId(): ?int
    {
        return self::profile()['placeholder_id'] ?? null;
    }

    /**
     * Whether a TT1.0 moderator (permissions 2) imports as a moderator. TT1.0 had no other
     * way to let someone create events, so a garrison can list its actual command staff
     * (legacy trooper ids) in `command_staff_ids`; its other moderators import as event
     * coordinators. Without a list every moderator stays a moderator.
     */
    public static function isCommandStaff(int $legacy_trooper_id): bool
    {
        $command_staff_ids = self::profile()['command_staff_ids'] ?? null;

        return $command_staff_ids === null || in_array($legacy_trooper_id, $command_staff_ids, true);
    }

    /**
     * Whether a TT1.0 account is a shared account for trying the event coordinator role
     * (`test_coordinator_ids`). It imports as an event coordinator and a member of the first
     * squad, so it has somewhere to host events, whatever its TT1.0 permissions.
     */
    public static function isTestCoordinator(int $legacy_trooper_id): bool
    {
        return in_array($legacy_trooper_id, self::profile()['test_coordinator_ids'] ?? [], true);
    }

    /**
     * Legacy squad metadata keyed by organization name.
     *
     * @return array<string, array{legacy_id: int, logo: string, eventForum: int, userGroup: int}>
     */
    public static function squads(): array
    {
        return self::profile()['squads'];
    }

    private static function profile(): array
    {
        return self::$profile ?? self::florida();
    }

    private static function florida(): array
    {
        return [
            'name' => 'Florida Garrison',
            //  see Issues\Fix208
            'placeholder_id' => 1206,
            'squads' => [
                'Everglades Squad' => [
                    'legacy_id' => 1,
                    'logo' => 'everglades_emblem.png',
                    'eventForum' => 9,
                    'userGroup' => 44,
                ],
                'Makaze Squad' => [
                    'legacy_id' => 2,
                    'logo' => 'makaze_emblem.png',
                    'eventForum' => 8,
                    'userGroup' => 45,
                ],
                'Parjai Squad' => [
                    'legacy_id' => 3,
                    'logo' => 'parjai_emblem.png',
                    'eventForum' => 186,
                    'userGroup' => 250,
                ],
                'Squad 7' => [
                    'legacy_id' => 4,
                    'logo' => 'squad7_emblem.png',
                    'eventForum' => 7,
                    'userGroup' => 683,
                ],
                'Tampa Bay Squad' => [
                    'legacy_id' => 5,
                    'logo' => 'tampabay_emblem.png',
                    'eventForum' => 73,
                    'userGroup' => 43,
                ],
            ],
        ];
    }
}
