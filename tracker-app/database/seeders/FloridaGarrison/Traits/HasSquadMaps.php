<?php

declare(strict_types=1);

namespace Database\Seeders\FloridaGarrison\Traits;

use App\Models\Organization;
use Database\Seeders\FloridaGarrison\Support\LegacyGarrison;

trait HasSquadMaps
{
    /**
     * Maps legacy squad names to their database ID and metadata.
     *
     * @return array<string, array{id: int, logo: string, eventForum: int, userGroup: int}>
     */
    protected function getSquadMap(): array
    {
        $map = [];

        foreach (LegacyGarrison::squads() as $name => $meta)
        {
            $squad = Organization::firstWhere(Organization::NAME, $name);

            if ($squad)
            {
                $map[$meta['legacy_id']] = [
                    'id' => $squad->id,
                    'logo' => $meta['logo'],
                    'eventForum' => $meta['eventForum'],
                    'userGroup' => $meta['userGroup'],
                ];
            }
            else
            {
                throw new \RuntimeException("Squad not found for name: {$name}");
            }
        }

        return $map;
    }
}
