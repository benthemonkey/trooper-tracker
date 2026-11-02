<?php

declare(strict_types=1);

namespace Database\Seeders\FloridaGarrison;

use App\Enums\EventGuestStatus;
use App\Enums\EventStatus;
use App\Enums\EventTrooperStatus;
use App\Enums\MembershipRole;
use App\Models\Event;
use App\Models\EventGuest;
use App\Models\EventOrganization;
use App\Models\EventShift;
use App\Models\EventTrooper;
use App\Models\Organization;
use App\Models\Trooper;
use Carbon\Carbon;
use Database\Seeders\FloridaGarrison\Support\LegacyBBCode;
use Database\Seeders\FloridaGarrison\Support\LegacyGarrison;
use Database\Seeders\FloridaGarrison\Support\LegacySignupCreditResolver;
use Database\Seeders\FloridaGarrison\Traits\HasCostumeMaps;
use Database\Seeders\FloridaGarrison\Traits\HasEnumMaps;
use Database\Seeders\FloridaGarrison\Traits\HasSquadMaps;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class EventSeeder extends Seeder
{
    use HasCostumeMaps;
    use HasEnumMaps;
    use HasSquadMaps;

    /** Every column overlayTroopers() may set, in insert order */
    private const EVENT_TROOPER_COLUMNS = [
        EventTrooper::EVENT_SHIFT_ID,
        EventTrooper::TROOPER_ID,
        EventTrooper::STATUS,
        EventTrooper::SIGNED_UP_AT,
        EventTrooper::IS_HANDLER,
        EventTrooper::COSTUME_ORGANIZATION_IDS,
        EventTrooper::COSTUME_ID,
        EventTrooper::BACKUP_COSTUME_ID,
        EventTrooper::BACKUP_COSTUME_ORGANIZATION_IDS,
        EventTrooper::ADDED_BY_TROOPER_ID,
        EventTrooper::CREATED_AT,
        EventTrooper::UPDATED_AT,
    ];

    private $costume_maps;

    private LegacySignupCreditResolver $legacy_credit;

    private $squad_maps;

    private $trooper_status_map;

    // private $sign_ups;
    private $trooper_ids;

    private $handler_ids;

    private $organizations;

    private ?int $placeholder_id;

    private ?int $guest_added_by_id;

    /** @var array<int, array<string, true>> guest names used so far, per shift */
    private array $guest_names = [];

    //  Rows already in the database, so a fresh import skips a lookup per record
    /** @var array<int, int> */
    private array $existing_event_ids;

    /** @var array<int, int> */
    private array $existing_shift_ids;

    /** @var array<string, true> "shift_id:trooper_id" of sign-ups saved or queued */
    private array $sign_up_keys = [];

    /** @var array<int, list<object>> legacy sign-ups by legacy shift id */
    private array $legacy_sign_ups;

    /** @var list<array<string, mixed>> */
    private array $pending_event_organizations = [];

    /** @var list<array<string, mixed>> */
    private array $pending_event_troopers = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->initData();

        $legacy_events = $this->getLegacyEvents();

        foreach ($legacy_events as $legacy)
        {
            $event = isset($this->existing_event_ids[$legacy->id])
                ? Event::find($legacy->id)
                : new Event(['id' => $legacy->id]);

            $this->overlayEvent($legacy, $event);

            $this->overlayOrganization($legacy, $event);

            $event->save();

            $this->overlayOrganizations($legacy, $event);

            $this->overlayShifts($legacy, $event);
        }

        $this->flushPending();
    }

    private function flushPending(): void
    {
        foreach (array_chunk($this->pending_event_organizations, 500) as $chunk)
        {
            EventOrganization::upsert(
                $chunk,
                [EventOrganization::EVENT_ID, EventOrganization::ORGANIZATION_ID],
                [EventOrganization::CAN_ATTEND, EventOrganization::TROOPERS_ALLOWED]
            );
        }

        foreach (array_chunk($this->pending_event_troopers, 500) as $chunk)
        {
            EventTrooper::insert($chunk);
        }

        $this->pending_event_organizations = [];
        $this->pending_event_troopers = [];
    }

    private function initData()
    {
        $this->trooper_ids = Trooper::all()->pluck(Trooper::ID, Trooper::ID)->toArray();
        $this->handler_ids = Trooper::where(Trooper::MEMBERSHIP_ROLE, MembershipRole::HANDLER)->pluck(Trooper::ID, Trooper::ID)->toArray();
        $this->squad_maps = $this->getSquadMap();
        $this->organizations = Organization::ofTypeOrganizations()->get();
        $this->trooper_status_map = array_values(array_filter(
            EventTrooperStatus::cases(),
            fn ($case) => $case !== EventTrooperStatus::NONE
        ));

        $this->legacy_credit = LegacySignupCreditResolver::load();

        $this->existing_event_ids = Event::pluck(Event::ID, Event::ID)->all();
        $this->existing_shift_ids = EventShift::pluck(EventShift::ID, EventShift::ID)->all();

        foreach (EventTrooper::query()->get([EventTrooper::EVENT_SHIFT_ID, EventTrooper::TROOPER_ID]) as $existing)
        {
            $this->sign_up_keys["{$existing->event_shift_id}:{$existing->trooper_id}"] = true;
        }

        //  in id order, which is the order "first record wins" goes by
        $this->legacy_sign_ups = DB::table('event_sign_up')
            ->join('tt_troopers', 'event_sign_up.trooperid', '=', 'tt_troopers.id')
            ->select('event_sign_up.*')
            ->orderBy('event_sign_up.id')
            ->get()
            ->groupBy('troopid')
            ->map(fn ($group) => $group->all())
            ->all();

        $this->placeholder_id = LegacyGarrison::placeholderId();
        $this->guest_added_by_id = Trooper::where(Trooper::MEMBERSHIP_ROLE, MembershipRole::ADMINISTRATOR)
            ->orderBy(Trooper::ID)
            ->value(Trooper::ID) ?? $this->placeholder_id;

        $legacy_costumes = $this->getMappedLegacyCostumes();

        $this->costume_maps = [];

        foreach ($legacy_costumes as $costume_name => $legacy_costume)
        {
            foreach ($legacy_costume['ids'] as $legacy_id)
            {
                $this->costume_maps[$legacy_id] = $legacy_costume;
            }
        }
    }

    private function overlayOrganization($legacy, $event)
    {
        $fl_garrison = $this->getOrganization(-1);

        if ($legacy->squad <= 0)
        {
            $event->organization_id = $fl_garrison->id;
            $event->primary_organization_id = $fl_garrison->getPrimaryClub()->id;
        }
        else
        {
            $unit = $this->getOrganization($this->squad_maps[$legacy->squad]['id']);
            $event->organization_id = $unit->id;
            $event->primary_organization_id = $unit->getPrimaryClub()->id;
        }
    }

    private function overlayOrganizations($legacy, $event)
    {
        // $fl_garrison = $this->getOrganization(-1);

        $columns = [
            'limit501st' => '501st Legion',
            'limitRebels' => 'Rebel Legion',
            'limitMando' => 'Mandalorian Mercs',
            'limitDroid' => 'Droid Builders',
            'limitSG' => 'Saber Guild',
            'limitDE' => 'Dark Empire',
            //  ignoring Galactic Academy on seeding
        ];

        foreach ($columns as $column => $name)
        {
            $organization = $this->organizations->firstWhere('name', $name);

            //  upserted in bulk by flushPending()
            $this->pending_event_organizations[] = [
                EventOrganization::EVENT_ID => $event->id,
                EventOrganization::ORGANIZATION_ID => $organization->id,
                EventOrganization::CAN_ATTEND => $legacy->$column > 0,
                EventOrganization::TROOPERS_ALLOWED => $legacy->$column >= 500 ? null : $legacy->$column,
            ];
        }
    }

    private function overlayShifts($legacy, $event)
    {
        $shifts = collect([]);

        foreach ($legacy->shifts as $legacy_shift)
        {
            // Parse the incoming values
            $start = Carbon::parse($legacy_shift->dateStart);
            $end = Carbon::parse($legacy_shift->dateEnd);
            $key = $start->format('Y-m-d H:i');

            $filtered = $shifts->filter(fn ($s) => $s->key === $key);

            if ($filtered->count() > 0)
            {
                //  move the duped troop onto the non-first record
                $shift = $filtered->first();

                $this->overlayTroopers($legacy_shift->id, $shift->id);
            }
            else
            {
                $shift = isset($this->existing_shift_ids[$legacy_shift->id])
                    ? EventShift::find($legacy_shift->id)
                    : new EventShift(['id' => $legacy_shift->id]);

                $shift->event_id = $event->id;
                $shift->status = $event->status;

                // Assign normalized values
                $shift->shift_starts_at = $start;
                $shift->shift_ends_at = $end;

                $this->overlayShiftCharity($legacy_shift, $shift);

                $shift->save();

                $shift->key = $key;

                $this->overlayTroopers($legacy_shift->id, $shift->id);

                $shifts->add($shift);
            }
        }
    }

    private function overlayShiftCharity($legacy_shift, EventShift $shift): void
    {
        $shift->charity_direct_funds = $legacy_shift->charityDirectFunds ?? 0;
        $shift->charity_indirect_funds = $legacy_shift->charityIndirectFunds ?? 0;
        $shift->charity_name = $legacy_shift->charityName;
        $offset = (int) ($legacy_shift->charityAddHours ?? 0);
        $duration = (int) $shift->shift_starts_at->diffInHours($shift->shift_ends_at);
        $shift->charity_hours = $offset === 0 ? null : $duration + $offset;
        $shift->charity_notes = $legacy_shift->charityNote;
    }

    private function overlayTroopers($legacy_id, $shift_id)
    {
        foreach ($this->legacy_sign_ups[$legacy_id] ?? [] as $legacy_sign_up)
        {
            if ($this->placeholder_id !== null && (int) $legacy_sign_up->trooperid === $this->placeholder_id)
            {
                $this->overlayGuest($legacy_sign_up, $shift_id);

                continue;
            }

            $sign_up_key = "{$shift_id}:{$legacy_sign_up->trooperid}";

            if (!isset($this->sign_up_keys[$sign_up_key]))
            {
                //  first record wins unforunately
                $this->sign_up_keys[$sign_up_key] = true;

                $event_trooper = new EventTrooper;

                $event_trooper->event_shift_id = $shift_id;
                $event_trooper->trooper_id = $legacy_sign_up->trooperid;
                $event_trooper->status = $this->trooper_status_map[$legacy_sign_up->status];
                $event_trooper->signed_up_at = Carbon::parse($legacy_sign_up->signuptime);

                $event_trooper->is_handler = isset($this->handler_ids[$legacy_sign_up->trooperid]);

                //  credit comes from the legacy signup's costume club for everyone, handlers
                //  included — TT1.0 had per-club Handler/N/A/Command Staff costumes
                $credit = $this->legacy_credit->resolve($shift_id, (int) $legacy_sign_up->trooperid);
                $event_trooper->costume_organization_ids = $credit->isResolved() ? $credit->org_ids : null;

                if (!$event_trooper->is_handler)
                {
                    $event_trooper->costume_id = $this->getMappedCostumeId($legacy_sign_up->costume);

                    $event_trooper->backup_costume_id = $this->getMappedCostumeId($legacy_sign_up->costume_backup);

                    $event_trooper->backup_costume_organization_ids =
                        $this->getMappedCostumeOrganizationIds((int) $legacy_sign_up->costume_backup);
                }

                if ($legacy_sign_up->addedby > 0)
                {
                    if (isset($this->trooper_ids[$legacy_sign_up->addedby]))
                    {
                        $event_trooper->added_by_trooper_id = $legacy_sign_up->addedby;
                    }
                }

                $this->queueEventTrooper($event_trooper);
            }
        }
    }

    /**
     * Queue the sign-up for flushPending()'s bulk insert. The model's attributes are already in
     * storage form (casts apply on set); a bulk insert needs the same columns on every row.
     */
    private function queueEventTrooper(EventTrooper $event_trooper): void
    {
        $event_trooper->updateTimestamps();

        $attributes = $event_trooper->getAttributes();
        $unlisted = array_diff_key($attributes, array_flip(self::EVENT_TROOPER_COLUMNS));

        if ($unlisted !== [])
        {
            throw new LogicException('Add to EVENT_TROOPER_COLUMNS: '.implode(', ', array_keys($unlisted)));
        }

        $row = [];

        foreach (self::EVENT_TROOPER_COLUMNS as $column)
        {
            $row[$column] = $attributes[$column] ?? null;
        }

        $this->pending_event_troopers[] = $row;
    }

    /**
     * TT1.0 signed guests up as the placeholder trooper with the guest's name in the note,
     * several per shift. Import each one as a guest so none collapse into a single sign-up.
     */
    private function overlayGuest($legacy_sign_up, int $shift_id): void
    {
        $name = $this->uniqueGuestName($shift_id, trim(html_entity_decode((string) $legacy_sign_up->note)));

        $guest = EventGuest::withTrashed()
            ->where(EventGuest::EVENT_SHIFT_ID, $shift_id)
            ->where(EventGuest::NAME, $name)
            ->first() ?? new EventGuest;

        $guest->event_shift_id = $shift_id;
        $guest->name = $name;
        $guest->status = $this->mapGuestStatus($this->trooper_status_map[$legacy_sign_up->status]);
        $guest->signed_up_at = Carbon::parse($legacy_sign_up->signuptime);

        $added_by = (int) $legacy_sign_up->addedby;

        $guest->added_by_trooper_id = isset($this->trooper_ids[$added_by]) && $added_by !== $this->placeholder_id
            ? $added_by
            : $this->guest_added_by_id;

        $guest->save();
    }

    private function uniqueGuestName(int $shift_id, string $name): string
    {
        $base = mb_substr($name === '' ? 'Placeholder' : $name, 0, 120);
        $candidate = $base;
        $n = 1;

        while (isset($this->guest_names[$shift_id][$candidate]))
        {
            $n++;
            $candidate = "{$base} {$n}";
        }

        $this->guest_names[$shift_id][$candidate] = true;

        return $candidate;
    }

    private function mapGuestStatus(EventTrooperStatus $status): EventGuestStatus
    {
        //  guests have no attended state; as Issues\Fix208 does, anyone who didn't drop out is going
        return match ($status)
        {
            EventTrooperStatus::TENTATIVE => EventGuestStatus::TENTATIVE,
            EventTrooperStatus::STAND_BY => EventGuestStatus::STAND_BY,
            EventTrooperStatus::CANCELLED,
            EventTrooperStatus::NO_SHOW,
            EventTrooperStatus::UNABLE_TO_ATTEND,
            EventTrooperStatus::NOT_PICKED => EventGuestStatus::CANCELLED,
            default => EventGuestStatus::GOING,
        };
    }

    private function getMappedCostumeId($legacy_id): ?int
    {
        $legacy_costume = $this->costume_maps[$legacy_id] ?? null;

        if ($legacy_costume === null)
        {
            return null;
        }

        return $legacy_costume['id'];
    }

    /** @return array<int, int>|null */
    private function getMappedCostumeOrganizationIds(int $legacy_id): ?array
    {
        $credit = $this->legacy_credit->creditForCostume($legacy_id);

        return $credit->isResolved() ? $credit->org_ids : null;
    }

    private function overlayEvent($legacy, $event)
    {
        $legacy->name = trim($legacy->name);

        $event->name = $legacy->name;

        $label = is_string($legacy->label) ? (int) $legacy->label : ($legacy->label ?? 0);

        $event->type = $this->eventLabelFromLegacyId($label);

        $event->venue = $legacy->venue;
        $event->venue_address = $legacy->location;
        $event->latitude = $legacy->latitude;
        $event->longitude = $legacy->longitude;
        $event->event_start = $legacy->dateStart;
        $event->event_end = $legacy->dateEnd;
        $event->event_website = $legacy->website;
        $event->expected_attendees = $legacy->numberOfAttend;
        $event->requested_number_characters = $legacy->requestedNumber;
        $event->requested_character_types = $legacy->requestedCharacter;
        $event->secure_staging_area = $legacy->secureChanging ?? false;
        $event->allow_blasters = $legacy->blasters ?? false;
        $event->allow_props = $legacy->lightsabers ?? false;
        $event->parking_available = $legacy->parking ?? false;
        $event->accessible = $legacy->mobility ?? false;
        $event->amenities = $legacy->amenities;
        $event->comments = LegacyBBCode::toMarkdown($legacy->comments);
        $event->referred_by = $legacy->referred;

        if ($legacy->limitedEvent)
        {
            $event->troopers_allowed = $legacy->limitTotalTroopers;
            $event->handlers_allowed = $legacy->limitHandlers;
        }
        else
        {
            $event->troopers_allowed = null;
        }

        $event->friends_allowed = $legacy->limitFriends ?? null;
        $event->tentative_signups_allowed = (bool) $legacy->allowTentative;

        $event->status = $legacy->closed ? EventStatus::CLOSED : EventStatus::OPEN;

        //  the event coordinator, so they can keep managing it (Midwest's TT1.0 has createdBy)
        $created_by = (int) ($legacy->createdBy ?? 0);
        $event->created_id = isset($this->trooper_ids[$created_by]) && $created_by !== $this->placeholder_id
            ? $created_by
            : null;

        // Carry legacy forum references when present; otherwise clear to null.
        $event->thread_id = $this->toNullablePositiveInt($legacy->thread_id ?? null);
        $event->post_id = $this->toNullablePositiveInt($legacy->post_id ?? null);
    }

    private function toNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value))
        {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function getOrganization($id)
    {
        $organizations = once(fn () => Organization::all()->keyBy('id'));

        if ($id <= 0)
        {
            return $organizations->filter(fn ($org) => $org->name === LegacyGarrison::name())->first();
        }

        return $organizations[$id];
    }

    private function getLegacyEvents()
    {
        $all = DB::table('events')
            // ->where(function ($query)
            // {
            //     $query->where('id', 10025)
            //         ->orWhere('link', 10025);
            // })
            // ->where('id', 11296)
            ->orderBy('id')
            ->orderBy('link')
            ->get();

        $events = [];

        foreach ($all->filter(fn ($x) => $x->link === 0) as $event)
        {
            $event->shifts = collect([$event]);

            $events[$event->id] = $event;
        }

        foreach ($all->filter(fn ($x) => $x->link > 0) as $event)
        {
            $main_event = $events[$event->link];

            $main_event->shifts->add($event);
        }

        return collect($events);
    }
}
