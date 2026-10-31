<?php

declare(strict_types=1);

namespace Database\Seeders\FloridaGarrison;

use App\Models\Trooper;
use Database\Seeders\FloridaGarrison\Support\LegacyGarrison;
use Illuminate\Database\Seeder;

/**
 * Soft-deletes the TT1.0 placeholder trooper once EventSeeder has turned its sign-ups into
 * guests, so it no longer shows up as a member (same end state as Issues\Fix208).
 */
class PlaceholderTrooperSeeder extends Seeder
{
    public function run(): void
    {
        $placeholder_id = LegacyGarrison::placeholderId();

        if ($placeholder_id === null)
        {
            return;
        }

        Trooper::find($placeholder_id)?->delete();
    }
}
