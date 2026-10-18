@php($tourUser = auth()->user())
@if ($tourUser instanceof \App\Models\User && $tourUser->isUsable() && filled($tourUser->getAppAuthenticationSecret()))
    @livewire(\App\Livewire\GuidedTour::class)
@endif
