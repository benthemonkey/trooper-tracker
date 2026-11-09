<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders\MidwestGarrison;

use App\Models\Event;
use App\Models\EventShift;
use App\Models\EventTrooper;
use App\Models\EventUpload;
use App\Models\EventUploadTrooper;
use App\Models\Trooper;
use Database\Seeders\MidwestGarrison\EventPhotoAttendeeTagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventPhotoAttendeeTagSeederTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private EventShift $first_shift;

    private EventShift $second_shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = Event::factory()->create();
        $this->first_shift = EventShift::factory()->create([EventShift::EVENT_ID => $this->event->id]);
        $this->second_shift = EventShift::factory()->create([EventShift::EVENT_ID => $this->event->id]);
    }

    public function test_everyone_who_attended_any_shift_is_tagged_in_the_events_photos(): void
    {
        $first = $this->attended($this->first_shift);
        $second = $this->attended($this->second_shift);
        $photo = $this->photo();
        $other_photo = $this->photo();

        $this->seed(EventPhotoAttendeeTagSeeder::class);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->taggedIn($photo));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->taggedIn($other_photo));
    }

    public function test_troopers_who_did_not_attend_are_not_tagged(): void
    {
        $attended = $this->attended($this->first_shift);
        $going = Trooper::factory()->create();
        EventTrooper::factory()->forEventShift($this->first_shift)->forTrooper($going)->asGoing()->create();
        $photo = $this->photo();

        $this->seed(EventPhotoAttendeeTagSeeder::class);

        $this->assertSame([$attended->id], $this->taggedIn($photo));
    }

    public function test_administrative_uploads_get_no_tags(): void
    {
        $this->attended($this->first_shift);
        $form = $this->photo(is_administrative: true);

        $this->seed(EventPhotoAttendeeTagSeeder::class);

        $this->assertSame([], $this->taggedIn($form));
    }

    public function test_photos_are_only_tagged_with_their_own_events_attendees(): void
    {
        $here = $this->attended($this->first_shift);
        $other_event = Event::factory()->create();
        $other_shift = EventShift::factory()->create([EventShift::EVENT_ID => $other_event->id]);
        $this->attended($other_shift);
        $photo = $this->photo();

        $this->seed(EventPhotoAttendeeTagSeeder::class);

        $this->assertSame([$here->id], $this->taggedIn($photo));
    }

    public function test_existing_tags_are_kept_and_not_duplicated(): void
    {
        $attended = $this->attended($this->first_shift);
        $tagged_by_hand = Trooper::factory()->create();
        $photo = $this->photo();
        foreach ([$attended, $tagged_by_hand] as $trooper)
        {
            EventUploadTrooper::create([
                EventUploadTrooper::EVENT_UPLOAD_ID => $photo->id,
                EventUploadTrooper::TROOPER_ID => $trooper->id,
            ]);
        }

        $this->seed(EventPhotoAttendeeTagSeeder::class);

        $this->assertEqualsCanonicalizing([$attended->id, $tagged_by_hand->id], $this->taggedIn($photo));
        $this->assertSame(2, EventUploadTrooper::where(EventUploadTrooper::EVENT_UPLOAD_ID, $photo->id)->count());
    }

    private function attended(EventShift $shift): Trooper
    {
        $trooper = Trooper::factory()->create();
        EventTrooper::factory()->forEventShift($shift)->forTrooper($trooper)->asAttended()->create();

        return $trooper;
    }

    private function photo(bool $is_administrative = false): EventUpload
    {
        return EventUpload::factory()->create([
            EventUpload::EVENT_ID => $this->event->id,
            EventUpload::TROOPER_ID => Trooper::factory()->create()->id,
            EventUpload::IS_ADMINISTRATIVE => $is_administrative,
        ]);
    }

    /**
     * @return list<int>
     */
    private function taggedIn(EventUpload $upload): array
    {
        return EventUploadTrooper::where(EventUploadTrooper::EVENT_UPLOAD_ID, $upload->id)
            ->orderBy(EventUploadTrooper::TROOPER_ID)
            ->pluck(EventUploadTrooper::TROOPER_ID)
            ->all();
    }
}
