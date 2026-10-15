<?php

namespace App\Models;

use Database\Factories\RecruitmentJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $title_ar
 * @property string $title_en
 * @property list<string>|null $aliases
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property bool $active
 * @property bool $name_blind_review
 * @property int|null $current_criteria_version_id
 */
class RecruitmentJob extends Model
{
    /** @use HasFactory<RecruitmentJobFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = [
        'title_ar',
        'title_en',
        'aliases',
        'description_ar',
        'description_en',
        'active',
        'name_blind_review',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'active' => 'boolean',
            'name_blind_review' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('configuration')
            ->logOnly(['title_ar', 'title_en', 'aliases', 'active', 'name_blind_review', 'current_criteria_version_id'])
            ->logOnlyDirty();
    }

    /**
     * Title in the current UI language.
     */
    public function title(): string
    {
        return app()->getLocale() === 'ar' ? $this->title_ar : $this->title_en;
    }

    /**
     * @return HasMany<CriteriaVersion, $this>
     */
    public function criteriaVersions(): HasMany
    {
        return $this->hasMany(CriteriaVersion::class);
    }

    /**
     * @return BelongsTo<CriteriaVersion, $this>
     */
    public function currentCriteriaVersion(): BelongsTo
    {
        return $this->belongsTo(CriteriaVersion::class, 'current_criteria_version_id');
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
