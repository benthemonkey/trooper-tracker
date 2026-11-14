<x-input-container>
    <x-label for="coordinator_trooper_id">Event Coordinator:</x-label>
    <x-input-select :property="'coordinator_trooper_id'"
                    :options="$coordinator_options"
                    :value="$event->coordinator_trooper_id"
                    :placeholder="($event->created_by?->display_name ?? 'The event\'s creator') . ' (created the event)'" />
    <x-input-help>
        Shown on the event page. It's whoever created the event unless you pick someone else
        who is going or attended. If their status changes to anything else, it goes back to the
        creator. The forum thread stays under the creator's name.
    </x-input-help>
</x-input-container>
