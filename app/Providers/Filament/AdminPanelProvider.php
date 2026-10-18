<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Home;
use App\Http\Middleware\ApplyUserLocale;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(isSimple: false)
            ->brandName(fn (): string => __('app.brand'))
            ->colors([
                'primary' => Color::Teal,
            ])
            // TXT-P0-04: self-hosted Arabic font, no third-party CDN.
            ->font('Noto Sans Arabic', url: asset('fonts/noto-sans-arabic/font.css'), provider: LocalFontProvider::class)
            // M9-P0-02: TOTP for every user; users without it are sent to set-up before anything else.
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable()
                    ->brandName((string) config('app.name')),
            ], isRequired: true)
            ->userMenuItems([
                'locale-ar' => fn (): Action => Action::make('locale-ar')
                    ->label(__('app.locale.switch_to_ar'))
                    ->icon(Heroicon::OutlinedLanguage)
                    ->url(route('locale.switch', ['locale' => 'ar']))
                    ->visible(fn (): bool => $this->currentLocale() !== 'ar'),
                'locale-en' => fn (): Action => Action::make('locale-en')
                    ->label(__('app.locale.switch_to_en'))
                    ->icon(Heroicon::OutlinedLanguage)
                    ->url(route('locale.switch', ['locale' => 'en']))
                    ->visible(fn (): bool => $this->currentLocale() !== 'en'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // Screening first. Labels are closures so the order holds in both UI languages.
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('app.nav.screening')),
                NavigationGroup::make()->label(fn (): string => __('app.nav.configuration')),
                NavigationGroup::make()->label(fn (): string => __('app.nav.administration')),
            ])
            // The root only redirects to the applications review list (the product's home screen).
            ->pages([
                Home::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Only the locale middleware is persistent (re-applied to Livewire requests).
            // Marking the cookie/session middleware persistent would run them twice on
            // Livewire updates and silently start a new session (logs the user out).
            ->middleware([
                ApplyUserLocale::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    private function currentLocale(): string
    {
        $user = auth()->user();

        return $user instanceof User ? $user->locale : app()->getLocale();
    }
}
