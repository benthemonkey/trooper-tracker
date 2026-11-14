<?php

declare(strict_types=1);

namespace Database\Seeders\MidwestGarrison;

use App\Models\EventOrganization;
use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TT1.0 had no Galactic Academy limit column, so the shared EventSeeder gives Galactic
 * Academy no row on any event, and its members couldn't sign up for any of them. Midwest
 * opens every imported event to Galactic Academy, with no limit. Events that already have a
 * Galactic Academy row keep it.
 */
class GalacticAcademyEventSeeder extends Seeder
{
    public function run(): void
    {
        $academy_id = Organization::ofTypeOrganizations()
            ->where(Organization::NAME, 'Galactic Academy')
            ->value(Organization::ID);

        if ($academy_id === null)
        {
            return;
        }

        $event_ids = DB::table('tt_events as e')
            ->whereNotExists(fn (Builder $q) => $q->from('tt_event_organizations as eo')
                ->whereColumn('eo.event_id', 'e.id')
                ->where('eo.organization_id', $academy_id))
            ->orderBy('e.id')
            ->pluck('e.id');

        $now = now();

        foreach ($event_ids->chunk(500) as $chunk)
        {
            EventOrganization::insert($chunk->map(fn (int $event_id) => [
                EventOrganization::EVENT_ID => $event_id,
                EventOrganization::ORGANIZATION_ID => $academy_id,
                EventOrganization::CAN_ATTEND => true,
                EventOrganization::TROOPERS_ALLOWED => null,
                EventOrganization::HANDLERS_ALLOWED => null,
                EventOrganization::CREATED_AT => $now,
                EventOrganization::UPDATED_AT => $now,
            ])->values()->all());
        }
    }
}
