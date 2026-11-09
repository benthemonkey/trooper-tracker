<?php

declare(strict_types=1);

namespace Database\Seeders\MidwestGarrison;

use App\Enums\EventTrooperStatus;
use App\Models\EventUploadTrooper;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Midwest's event photos are nearly always one group shot of everyone who came, so every
 * trooper who attended an event is tagged in its photos. Runs after the TT1.0 tags are
 * imported and keeps them; administrative uploads (forms, documents) get no tags.
 */
class EventPhotoAttendeeTagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = DB::table('tt_event_uploads as u')
            ->join('tt_event_shifts as s', 's.event_id', '=', 'u.event_id')
            ->join('tt_event_troopers as et', 'et.event_shift_id', '=', 's.id')
            ->where('u.is_administrative', false)
            ->where('et.status', EventTrooperStatus::ATTENDED->value)
            ->whereNull('u.deleted_at')
            ->whereNull('s.deleted_at')
            ->whereNull('et.deleted_at')
            ->whereNotExists(fn (Builder $q) => $q->from('tt_event_upload_troopers as t')
                ->whereColumn('t.event_upload_id', 'u.id')
                ->whereColumn('t.trooper_id', 'et.trooper_id'))
            ->distinct()
            ->orderBy('u.id')
            ->orderBy('et.trooper_id')
            ->get(['u.id as event_upload_id', 'et.trooper_id']);

        $now = now();

        foreach ($tags->chunk(500) as $chunk)
        {
            EventUploadTrooper::insert($chunk->map(fn (object $tag) => [
                EventUploadTrooper::EVENT_UPLOAD_ID => $tag->event_upload_id,
                EventUploadTrooper::TROOPER_ID => $tag->trooper_id,
                EventUploadTrooper::CREATED_AT => $now,
                EventUploadTrooper::UPDATED_AT => $now,
            ])->all());
        }
    }
}
