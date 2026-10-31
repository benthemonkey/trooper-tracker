<?php

declare(strict_types=1);

namespace Database\Seeders\FloridaGarrison\Support;

/**
 * Garrison-specific facts the TT1.0 import needs: the region legacy squad 0 maps to, and the
 * legacy squad ids. Defaults to Florida so the existing FloridaGarrisonSeeder is unchanged.
 */
final class LegacyGarrison
{
    private static ?array $profile = null;

    /**
     * @param  array{name: string, squads: array<string, array<string, mixed>>}  $profile
     */
    public static function use(array $profile): void
    {
        self::$profile = $profile;
    }

    public static function name(): string
    {
        return self::profile()['name'];
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
