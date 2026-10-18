@if (config('app.demo_banner'))
    <div class="recruit-demo-upload-warning" data-demo-upload-warning role="note">
        {{ __('tour.demo_warning') }}
    </div>
@endif
