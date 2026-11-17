<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Events;

use App\Enums\FlashType;
use App\Http\Controllers\PageController;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Trooper;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Displays the event update form.
 *
 * Provides administrators and moderators with a form to update existing event details,
 * including venue information, contact details, and organization associations.
 * Shows a draft warning if the event is not yet published.
 */
class UpdateController extends PageController
{
    protected function initialized(): void
    {
        $this->crumbs->addRoute('Command Staff', 'admin.display');
        $this->crumbs->addRoute('Events', 'admin.events.list');
    }

    /**
     * Displays the event update form
     *
     * Authorizes that the user can update the event via policy check.
     * Loads all organizations for the organization selection interface.
     * Sets up breadcrumbs and renders the update form view.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Event  $event  The event to be updated (route model binding)
     * @return View The event update form view
     */
    public function __invoke(Request $request, Event $event): View
    {
        $this->authorize('update', $event);

        if ($event->is_draft)
        {
            $msg = 'This event is currently in Draft status and not visible to troopers.';

            FlashType::warning($msg);
        }

        $organizations = Organization::ofTypeOrganizations()->orderBy(Organization::NAME)->get();

        foreach ($organizations as $organization)
        {
            $event_organization = $event->organizations
                ->filter(fn ($eo) => $eo->id == $organization->id)
                ->filter(fn ($eo) => $eo->pivot->can_attend)
                ->first();

            $organization->pivot = $event_organization->pivot ?? null;
        }

        //  the creator is the empty choice, not an option of their own
        $candidates = $event->coordinatorCandidates()
            ->reject(fn (Trooper $trooper): bool => $trooper->id === $event->created_id);

        $coordinator_options = $candidates->pluck(Trooper::DISPLAY_NAME, Trooper::ID)->all();

        //  picking one of these lets someone below event coordinator edit the event: the page
        //  warns and asks to confirm
        $coordinator_below_role = $candidates
            ->reject(fn (Trooper $trooper): bool => $trooper->membership_role->managesEvents())
            ->map(fn (Trooper $trooper): string => (string) $trooper->id)
            ->values()
            ->all();

        //  a coordinator who can't host for the event's organization can't move it either
        $can_change_host = Organization::eventHostableBy($request->user())
            ->where(Organization::ID, $event->organization_id)
            ->exists();

        $data = compact('event', 'organizations', 'coordinator_options', 'coordinator_below_role', 'can_change_host');

        return view('pages.admin.events.update', $data);
    }
}
