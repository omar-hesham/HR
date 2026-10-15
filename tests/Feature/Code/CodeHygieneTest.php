<?php

use App\Domain\Access\Role;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Criteria\CriteriaVersionStatus;
use App\Domain\Criteria\CriterionField;
use App\Domain\Criteria\CriterionOperator;
use App\Domain\Criteria\CriterionType;
use App\Domain\Documents\Disposition;
use App\Domain\Documents\DocumentKind;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\ReviewReason;
use App\Domain\Documents\SourceChannel;
use App\Domain\Privacy\RetentionTrack;

/**
 * TXT-P0-02: no hard-coded Arabic UI text in PHP/Blade (translations live in lang/);
 * every key used exists in both languages; no third-party font CDN.
 */
function phpAndBladeFiles(array $dirs): array
{
    $files = [];

    foreach ($dirs as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (preg_match('/\.(php|blade\.php)$/', $file->getFilename()) === 1) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('has no Arabic text hard-coded in application code, routes or views', function () {
    $offenders = [];

    foreach (phpAndBladeFiles([app_path(), base_path('routes'), resource_path('views')]) as $file) {
        if (preg_match('/\p{Arabic}/u', (string) file_get_contents($file)) === 1) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([]);
});

it('has every app.* and enums.* key in both Arabic and English', function () {
    $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($array as $key => $value) {
            $keys = is_array($value) ? [...$keys, ...$flatten($value, $prefix.$key.'.')] : [...$keys, $prefix.$key];
        }

        return $keys;
    };

    foreach (['app', 'enums'] as $file) {
        $ar = $flatten(require lang_path("ar/{$file}.php"));
        $en = $flatten(require lang_path("en/{$file}.php"));

        expect(array_values(array_diff($ar, $en)))->toBe([], "keys only in ar/{$file}")
            ->and(array_values(array_diff($en, $ar)))->toBe([], "keys only in en/{$file}");
    }
});

it('translates every literal app.* key used in code', function () {
    $missing = [];

    foreach (phpAndBladeFiles([app_path(), database_path('seeders')]) as $file) {
        preg_match_all("/__\\('((?:app|enums)\\.[a-z_]+(?:\\.[a-z0-9_]+)+)'/", (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $key) {
            foreach (['ar', 'en'] as $locale) {
                if (trans($key, [], $locale) === $key) {
                    $missing[] = "{$locale}: {$key}";
                }
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

it('has a label for every enum case in both languages', function () {
    $enums = [
        SourceChannel::class, DocumentKind::class,
        DocumentStatus::class, Disposition::class,
        ReviewReason::class, ApplicationStatus::class,
        RetentionTrack::class, Role::class,
        CriterionType::class, CriterionOperator::class,
        CriteriaVersionStatus::class, CriterionField::class,
    ];

    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                expect($case->label())->not->toStartWith('enums.', "{$locale} {$enum}::{$case->name}");
            }
        }

        foreach (ReviewReason::cases() as $reason) {
            expect(__('app.review.hint.'.$reason->value))->not->toStartWith('app.');
        }
    }
});

it('does not load fonts or scripts from third-party CDNs', function () {
    $offenders = [];

    foreach (phpAndBladeFiles([app_path(), resource_path('views'), config_path()]) as $file) {
        if (preg_match('/fonts\.googleapis|fonts\.bunny|cdn\.jsdelivr|unpkg\.com/', (string) file_get_contents($file)) === 1) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([]);
});
