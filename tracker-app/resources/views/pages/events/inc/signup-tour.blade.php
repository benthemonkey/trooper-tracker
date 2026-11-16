{{--
    MIDWEST ONLY, TEMPORARY: a one-time "How sign-up works" tour for troopers moving over from the
    old tracker, where signing up was one form with a Submit button. Not meant for upstream; remove
    it some months after the migration by deleting this file and the two lines that use it in
    pages/events/inc/shifts.blade.php (the @include and the "How sign-up works" button).

    Starts by itself the first time a trooper opens an event with an open shift, on that device
    (remembered in the browser's localStorage, so there's nothing on the server). The button above
    the roster replays it. The example rows below are a picture only: nothing in them works.
--}}
@php
    $tour_auto_start = $event->event_shifts->contains(fn ($event_shift) => $event_shift->is_open);
    $tour_brief_pending = ($mission_brief_required ?? false) && !($has_mission_brief_ack ?? false);
    $tour_status_options = $event->signUpStatusOptions(\App\Enums\EventTrooperStatus::GOING);
    $tour_pending = \App\Enums\EventTrooperStatus::PENDING;
    $tour_going = \App\Enums\EventTrooperStatus::GOING;
@endphp
<div x-data="signUpTour({ autoStart: @js($tour_auto_start), storageKey: @js('tt.signupTourSeen.' . Auth::id()) })"
     x-on:signup-tour:start.window="start()"
     x-on:keydown.escape.window="active && end()"
     data-signup-tour
     data-autostart="{{ $tour_auto_start ? 'true' : 'false' }}">

    {{-- the example rows, shown only while the tour runs --}}
    <div class="card mb-3 signup-tour-example"
         x-show="active"
         x-cloak
         inert>
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
            <b>Example shift</b>
            <span class="badge text-bg-secondary">Example only, not a real sign-up</span>
        </div>
        <div class="card-body">
            <div class="small text-muted mb-2">Right after you press Sign Up:</div>
            <div class="row mb-3"
                 data-tour-example="pending">
                <div class="col-7 col-md-4 order-1">
                    <a href="#">{{ Auth::user()->display_name }}</a>
                </div>
                <div class="col-12 col-md-5 order-3 order-md-2">
                    <select class="form-select form-select-sm mt-2 mt-md-0"
                            data-tour-example="costume">
                        <option>-- Select Costume --</option>
                    </select>
                    <select class="form-select form-select-sm mt-2"
                            data-tour-example="backup">
                        <option>-- Select Backup Costume (optional) --</option>
                    </select>
                </div>
                <div class="col-5 col-md-3 order-2 order-md-3 text-end">
                    <span class="{{ $tour_pending->color() }}">
                        Pending
                        <span class="d-none d-md-inline">{!! $tour_pending->iconTag() !!}</span>
                    </span>
                </div>
            </div>

            <div class="small text-muted mb-2">After you pick a costume:</div>
            <div class="row mb-3"
                 data-tour-example="going">
                <div class="col-7 col-md-4 order-1">
                    <a href="#">{{ Auth::user()->display_name }}</a>
                </div>
                <div class="col-12 col-md-5 order-3 order-md-2">
                    <b>Your costume</b>
                    <br />
                    <i class="small text-muted"
                       data-tour-example="backup-shown">
                        <i class="fa fa-fw fa-box-archive"></i>
                        Your backup costume
                    </i>
                </div>
                <div class="col-5 col-md-3 order-2 order-md-3 text-end">
                    <select class="form-select form-select-sm"
                            data-tour-example="status">
                        @foreach($tour_status_options as $value => $label)
                            <option @selected($value === $tour_going->value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-3"
                 data-tour-example="extras">
                <span class="btn btn-sm btn-outline-info">
                    <i class="fa fa-fw fa-plus-circle me-2"></i>
                    Add a Friend
                </span>
                <span class="btn btn-sm btn-outline-info">
                    <i class="fa fa-fw fa-plus-circle me-2"></i>
                    Add a Guest
                </span>
            </div>

            <div class="small text-muted mb-2">After the troop:</div>
            <div class="row"
                 data-tour-example="after">
                <div class="col-12 col-md-4">
                    <a href="#">{{ Auth::user()->display_name }}</a>
                </div>
                <div class="col-12 col-md-8 mt-2 mt-md-0 text-md-end">
                    <div class="input-group input-group-sm d-inline-flex w-auto">
                        <span class="btn btn-outline-success">Made It</span>
                        <span class="btn btn-outline-danger">Missed It</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- the steps, in order; a step whose target isn't on the page is left out --}}
    <template data-tour-step
              data-title="How signing up works">
        Signing up works a little differently here. If you used the old tracker: there's no sign-up
        form and no Submit button any more. Everything saves the moment you pick it.
        <br /><br />
        This takes about a minute, and nothing in it signs you up.
    </template>
    @if($tour_brief_pending)
        <template data-tour-step
                  data-target=".mission-brief-container"
                  data-title="Read the mission brief first">
            This event asks you to read the mission brief and confirm you've read it. The Sign Up
            buttons appear after you do.
        </template>
    @endif
    <template data-tour-step
              data-target=".shift-signup-primary .btn"
              data-title="Sign up for each shift">
        Every shift has its own <b>Sign Up</b> button. Pressing it adds you to that shift straight
        away. Working more than one shift? Sign up for each one.
    </template>
    {{-- when the shifts are collapsed, or none can be signed up for right now --}}
    <template data-tour-step
              data-target="[id^='shift-container-']"
              data-unless=".shift-signup-primary .btn"
              data-title="Sign up for each shift">
        Every shift has its own <b>Sign Up</b> button. Open a shift with its <b>+</b> to see it.
        Pressing it adds you to that shift straight away. Working more than one shift? Sign up for
        each one.
    </template>
    <template data-tour-step
              data-target="[data-tour-example='pending']"
              data-title="Then pick your costume">
        You're added as <b>Pending</b>. To confirm your spot, pick your costume on your own row of
        the roster. It saves as soon as you choose. Not wearing one? Pick
        <i>Attending without a costume</i>.
    </template>
    <template data-tour-step
              data-target="[data-tour-example='backup']"
              data-title="A backup costume, if you like">
        Optional. Once it's picked, it shows under your costume with a
        <i class="fa fa-fw fa-box-archive"></i> box icon.
    </template>
    <template data-tour-step
              data-target="[data-tour-example='status']"
              data-title="Change your plans here">
        After your costume is picked, this menu on your row sets whether you're going. This is also
        where you cancel.
    </template>
    <template data-tour-step
              data-target="[data-tour-example='extras']"
              data-title="Bringing someone?">
        Once you're going, and if the event allows it, these buttons add a friend who has a tracker
        account, or a guest who doesn't.
    </template>
    <template data-tour-step
              data-target="[data-tour-example='after']"
              data-title="After the troop">
        Come back and press <b>Made It</b> or <b>Missed It</b> on your row.
    </template>
    <template data-tour-step
              data-target="[data-tour-replay]"
              data-title="That's it">
        You can watch this again any time with this button.
    </template>

    {{-- the dimmed page with a hole around the step's target, and the step's card. The hole is
         placed in page coordinates under <body>, so it scrolls with the page instead of chasing it. --}}
    <template x-teleport="body">
        <div class="signup-tour-spotlight"
             x-show="active"
             x-cloak
             x-bind:class="spot ? '' : 'signup-tour-spotlight--none'"
             x-bind:style="spotStyle"></div>
    </template>
    <div class="card shadow-lg signup-tour-card"
         x-show="active"
         x-cloak
         role="dialog"
         aria-modal="false"
         aria-labelledby="signup-tour-title">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <h6 id="signup-tour-title"
                    class="mb-0 fw-bold"
                    x-text="step?.title"></h6>
                <button type="button"
                        class="btn-close"
                        aria-label="Close the tour"
                        x-on:click="end()"></button>
            </div>
            <div class="mb-3"
                 x-html="step?.body"></div>
            <div class="d-flex justify-content-between align-items-center gap-2">
                <span class="small text-muted"
                      x-text="(index + 1) + ' of ' + steps.length"></span>
                <div class="d-flex gap-2">
                    <button type="button"
                            class="btn btn-sm btn-link text-muted"
                            x-show="!isLast"
                            x-on:click="end()">Skip tour</button>
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary"
                            x-show="index > 0"
                            x-on:click="back()">Back</button>
                    <button type="button"
                            class="btn btn-sm btn-primary"
                            x-ref="next"
                            x-on:click="next()"
                            x-text="isLast ? 'Done' : 'Next'"></button>
                </div>
            </div>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <style>
            .signup-tour-example { border-style: dashed; }
            /* absolute in page coordinates: the browser scrolls it with the content. The shadow
               dims everything around the hole, however far the page scrolls. */
            .signup-tour-spotlight {
                position: absolute;
                z-index: 1075;
                pointer-events: none;
                border-radius: .5rem;
                box-shadow: 0 0 0 3px var(--bs-warning), 0 0 0 100000px rgba(0, 0, 0, .6);
                transition: top .35s ease, left .35s ease, width .35s ease, height .35s ease;
            }
            .signup-tour-spotlight--none { box-shadow: 0 0 0 100000px rgba(0, 0, 0, .6); }
            .signup-tour-card {
                position: fixed;
                z-index: 1080;
                left: 16px;
                right: 16px;
                bottom: 16px;
                max-height: 45vh;
                overflow-y: auto;
            }
            @media (min-width: 768px) {
                .signup-tour-card { left: auto; right: 24px; bottom: 24px; width: 440px; }
            }
            @media (prefers-reduced-motion: reduce) {
                .signup-tour-spotlight { transition: none; }
            }
        </style>
        <script>
            window.signUpTour = function ({ autoStart, storageKey }) {
                const visible = (el) => el !== null && el.getClientRects().length > 0;
                const find = (selector) => selector
                    ? [...document.querySelectorAll(selector)].find(visible) ?? null
                    : null;

                return {
                    active: false,
                    steps: [],
                    index: 0,
                    spot: null,
                    layout: null,

                    init() {
                        let seen = false;
                        try { seen = localStorage.getItem(storageKey) !== null; } catch (e) {}
                        if (autoStart && !seen) {
                            setTimeout(() => this.start(), 600);
                        }
                    },

                    get step() { return this.steps[this.index] ?? null; },
                    get isLast() { return this.index === this.steps.length - 1; },

                    //  page coordinates; with no target, a zero-size hole mid-screen just dims the page
                    get spotStyle() {
                        const s = this.spot ?? { top: scrollY + innerHeight / 2, left: scrollX + innerWidth / 2, width: 0, height: 0 };
                        return `top:${s.top}px;left:${s.left}px;width:${s.width}px;height:${s.height}px`;
                    },

                    async start() {
                        if (this.active) return;
                        this.active = true;
                        //  let the example rows show before looking for the steps' targets
                        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                        this.steps = [...this.$el.querySelectorAll('template[data-tour-step]')]
                            .map((t) => ({
                                title: t.dataset.title,
                                body: t.innerHTML,
                                target: t.dataset.target ?? null,
                                unless: t.dataset.unless ?? null,
                            }))
                            .filter((s) => s.target === null || find(s.target))
                            .filter((s) => s.unless === null || !find(s.unless));
                        this.show(0);
                        this.$nextTick(() => this.$refs.next?.focus());
                        //  content loading above the target moves it down the page
                        this.layout = new ResizeObserver(() => this.reposition());
                        this.layout.observe(document.body);
                    },

                    targetEl() {
                        const s = this.step;
                        return s && s.target ? find(s.target) : null;
                    },

                    show(i) {
                        this.index = i;
                        const el = this.targetEl();
                        if (el) {
                            const top = scrollY + el.getBoundingClientRect().top - Math.max(80, innerHeight * 0.2);
                            const smooth = !matchMedia('(prefers-reduced-motion: reduce)').matches;
                            scrollTo({ top: Math.max(0, top), behavior: smooth ? 'smooth' : 'auto' });
                        }
                        this.reposition();
                    },

                    //  in page coordinates, so scrolling never moves it; only a new step or a resize does
                    reposition() {
                        const el = this.targetEl();
                        if (!el) { this.spot = null; return; }
                        const r = el.getBoundingClientRect();
                        const pad = 6;
                        this.spot = {
                            top: r.top + scrollY - pad,
                            left: r.left + scrollX - pad,
                            width: r.width + pad * 2,
                            height: r.height + pad * 2,
                        };
                    },

                    next() { this.isLast ? this.end() : this.show(this.index + 1); },
                    back() { if (this.index > 0) this.show(this.index - 1); },

                    end() {
                        this.active = false;
                        this.spot = null;
                        this.layout?.disconnect();
                        try { localStorage.setItem(storageKey, new Date().toISOString()); } catch (e) {}
                    },
                };
            };
        </script>
    @endpush
@endonce
