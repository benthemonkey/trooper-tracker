{{-- Picking someone below event coordinator lets them edit this event: warn, then confirm on save --}}
<div class="mb-4"
     x-data="{
       picked: @js((string) old('coordinator_trooper_id', $event->coordinator_trooper_id ?? '')),
       saved: @js((string) ($event->coordinator_trooper_id ?? '')),
       below: @js($coordinator_below_role),
       get warn() { return Object.hasOwn(this.below, this.picked); },
       get name() { return this.below[this.picked] ?? ''; },
     }"
     {{-- capture runs before the form's own submit handler, which would otherwise lock the
          Save button even after Cancel --}}
     x-init="$el.closest('form').addEventListener('submit', (e) => {
       if (warn && picked !== saved && !confirm(name + ' isn\'t an event coordinator. As this event\'s coordinator they will be able to edit it. Continue?')) {
           e.preventDefault();
           e.stopImmediatePropagation();
       }
     }, true)">
    <x-label for="coordinator_trooper_id">Event Coordinator:</x-label>
    <x-input-select :property="'coordinator_trooper_id'"
                    :options="$coordinator_options"
                    :value="$event->coordinator_trooper_id"
                    :placeholder="$coordinator_creator . ' (created the event)'"
                    x-model="picked" />
    <div class="alert alert-warning mt-2 mb-0"
         role="alert"
         x-show="warn"
         x-cloak>
        <i class="fa fa-fw fa-triangle-exclamation"></i>
        <span x-text="name"></span> isn't an event coordinator. Picking them lets them edit this
        event: its details, shifts, charity, troopers and photos. Nothing else changes for them.
    </div>
    <x-input-help>
        Shown on the event page, and they can edit this event. It's whoever created the event
        unless you pick someone else who is going or attended. If their status changes to
        anything else, it goes back to the creator. The forum thread stays under the creator's
        name.
    </x-input-help>
</div>
