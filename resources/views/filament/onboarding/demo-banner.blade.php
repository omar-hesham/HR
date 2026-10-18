@if (config('app.demo_banner'))
    <div class="recruit-demo-banner" data-demo-banner x-data="recruitDemoBanner" role="note">
        {{ __('tour.demo_warning') }}
    </div>
@endif
