<?php

namespace App\Providers;

use App\Domain\Criteria\CriteriaValidator;
use App\Domain\Intake\AttachmentClassifier;
use App\Domain\Intake\Contracts\MessageParser;
use App\Domain\Intake\SenderHmac;
use App\Domain\Text\PhoneNormalizer;
use App\Infrastructure\Intake\WebklexMessageParser;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Services\Malware\ClamAvScanner;
use App\Services\Malware\MalwareScanner;
use App\Services\Malware\NullMalwareScanner;
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

        // Pseudonymous sender identity (D-115).
        $this->app->singleton(SenderHmac::class, function (): SenderHmac {
            $key = config('recruitment.hash.dedup_key');

            if (! is_string($key) || $key === '') {
                throw new RuntimeException('DEDUP_HMAC_KEY is not set. Run: php artisan recruit:key');
            }

            return new SenderHmac($key);
        });

        // Malware scanning: clamd when configured, otherwise "skipped" locally (D-107).
        $this->app->singleton(MalwareScanner::class, static fn (): MalwareScanner => match (config('recruitment.malware.driver')) {
            'clamav' => new ClamAvScanner,
            null, 'null' => app()->environment('local', 'testing') ? new NullMalwareScanner : throw new RuntimeException('malware_scanner_required'),
            default => throw new RuntimeException('unknown_malware_scanner'),
        });

        // One MIME parser for every driver (D-100).
        $this->app->singleton(
            MessageParser::class,
            WebklexMessageParser::class,
        );

        $this->app->singleton(AttachmentClassifier::class, static fn (): AttachmentClassifier => new AttachmentClassifier(
            cvExtensions: (array) config('recruitment.extraction.allowed_extensions'),
            imageExtensions: (array) config('recruitment.intake.image_extensions'),
            cvFilenameTerms: [...config('recruitment.intake.cv_filename_terms'), ...__('app.intake.cv_filename_terms', [], 'ar')],
        ));
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
