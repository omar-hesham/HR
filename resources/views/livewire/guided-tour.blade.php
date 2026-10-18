<div x-data x-on:recruit-tour-replay.window="$wire.replay()">
    @if ($open)
        <div
            data-guided-tour
            x-data="recruitGuidedTour($wire, @js($steps), @js(__('tour.counter')))"
            x-cloak
            x-show="active"
            x-trap.inert.noscroll.noreturn="active"
            x-on:keydown.window.capture="onKey($event)"
            x-on:livewire:navigating.window="active = false"
            class="recruit-tour"
            wire:ignore
        >
            <div class="recruit-tour-shade" x-show="! spotlight" aria-hidden="true"></div>
            <div class="recruit-tour-spotlight" x-ref="spotlight" x-show="spotlight" aria-hidden="true"></div>
            <section class="recruit-tour-card" x-ref="card" role="dialog" aria-modal="true" aria-labelledby="recruit-tour-title" aria-describedby="recruit-tour-body" :aria-busy="busy">
                <p class="recruit-tour-counter" x-text="counter" aria-live="polite" aria-atomic="true"></p>
                <h2 id="recruit-tour-title" tabindex="-1" x-ref="heading" x-text="step.title"></h2>
                <p id="recruit-tour-body" x-text="step.body"></p>
                <p class="recruit-tour-error" x-show="failed" role="alert">{{ __('tour.save_failed') }}</p>
                <div class="recruit-tour-buttons">
                    <button type="button" x-on:click="previous()" :disabled="busy || index === 0">{{ __('tour.previous') }}</button>
                    <button type="button" class="recruit-tour-primary" x-ref="next" x-on:click="next()" :disabled="busy" x-text="index === steps.length - 1 ? @js(__('tour.finish')) : @js(__('tour.next'))"></button>
                    <button type="button" class="recruit-tour-skip" x-on:click="finish('skip')" :disabled="busy">{{ __('tour.skip') }}</button>
                </div>
            </section>
        </div>
    @endif
</div>
