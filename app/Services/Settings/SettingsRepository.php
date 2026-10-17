<?php

namespace App\Services\Settings;

use App\Domain\Text\QualityThresholds;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiSettingsValidator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Factory business settings (M11-P0-03): defaults from config/recruitment.php,
 * overrides in the settings table, every change versioned and written to the
 * activity log with old/new values. Secrets never go through here.
 */
final class SettingsRepository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = config('recruitment.settings_defaults', []);

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $values = $this->defaults();

        foreach (Setting::query()->whereIn('key', array_keys($values))->get() as $setting) {
            $values[$setting->key] = $setting->value_json;
        }

        return $this->cache = $values;
    }

    public function get(string $key): mixed
    {
        $all = $this->all();

        if (! array_key_exists($key, $all)) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }

        return $this->cast($key, $all[$key]);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values, ?User $actor): void
    {
        foreach ($values as $key => $value) {
            if (str_starts_with($key, 'ai.') || str_starts_with($key, 'scoring.')) {
                abort_unless($actor !== null && $actor->can('ai.pipeline.manage'), 403);
            }
        }
        app(AiSettingsValidator::class)->validate($values, $this->all());
        $defaults = $this->defaults();

        DB::transaction(function () use ($values, $actor, $defaults): void {
            foreach ($values as $key => $value) {
                if (! array_key_exists($key, $defaults)) {
                    throw new InvalidArgumentException("Unknown setting [{$key}].");
                }

                $value = $this->cast($key, $value);
                $old = $this->get($key);

                if ($old === $value) {
                    continue;
                }

                $setting = Setting::query()->firstOrNew(['key' => $key]);
                $setting->value_json = $value;
                $setting->version = $setting->exists ? $setting->version + 1 : 1;
                $setting->updated_by = $actor?->id;
                $setting->save();

                activity('configuration')
                    ->causedBy($actor)
                    ->performedOn($setting)
                    ->event('settings.updated')
                    ->withProperties(['key' => $key, 'old' => $old, 'new' => $value, 'version' => $setting->version])
                    ->log('settings.updated');
            }
        });

        $this->cache = null;
    }

    public function qualityThresholds(): QualityThresholds
    {
        return new QualityThresholds(
            minLetterRatio: $this->float('quality.min_letter_ratio'),
            minWordlistRatio: $this->float('quality.min_wordlist_ratio'),
            maxReversedRatio: $this->float('quality.max_reversed_ratio'),
            minLetters: $this->int('quality.min_letters'),
        );
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    private function cast(string $key, mixed $value): mixed
    {
        $default = $this->defaults()[$key] ?? null;

        return match (true) {
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            is_int($default) => (int) $value,
            is_float($default) => (float) $value,
            default => is_scalar($value) ? (string) $value : $value,
        };
    }
}
