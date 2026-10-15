<?php

namespace App\Providers;

use App\Domain\Criteria\CriteriaValidator;
use App\Domain\Text\PhoneNormalizer;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Services\Settings\SettingsRepository;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContentEncrypter::class, function (): ContentEncrypter {
            $key = config('recruitment.storage.file_encryption_key');

            if (! is_string($key) || $key === '') {
                throw new RuntimeException('FILE_ENCRYPTION_KEY is not set. Run: php artisan recruit:key');
            }

            return new ContentEncrypter($key);
        });

        // Scoped: one cache per request / queued job, so changed settings apply immediately.
        $this->app->scoped(SettingsRepository::class);
        $this->app->singleton(CriteriaValidator::class, fn (): CriteriaValidator => CriteriaValidator::fromConfig());
        $this->app->singleton(PhoneNormalizer::class, fn (): PhoneNormalizer => PhoneNormalizer::make());
    }

    public function boot(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
                activity('access')->causedBy($event->user)->performedOn($event->user)->event('user.login')->log('user.login');
            }
        });

        // TXT-P0-04: display in Africa/Cairo (storage stays UTC), dd/mm/yyyy, Western digits.
        FilamentTimezone::set((string) config('recruitment.display_timezone', 'Africa/Cairo'));

        Table::configureUsing(fn (Table $table): Table => $table
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i')
            ->defaultNumberLocale('en'));

        Schema::configureUsing(fn (Schema $schema): Schema => $schema
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i')
            ->defaultNumberLocale('en'));
    }
}
