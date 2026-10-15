<?php

namespace App\Models;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $locale
 * @property bool $active
 * @property Carbon|null $access_expires_at
 * @property bool $name_blind_review
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'active',
        'access_expires_at',
        'name_blind_review',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'active' => 'boolean',
            'access_expires_at' => 'datetime',
            'name_blind_review' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Server-side gate for the whole panel: inactive or expired accounts are refused
     * on every request, not only at login (M9-P0-02).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isUsable();
    }

    public function isUsable(): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->access_expires_at !== null && $this->access_expires_at->isPast()) {
            return false;
        }

        // The support account must always be time-limited (D-15).
        if ($this->hasRole(Role::Operator->value) && $this->access_expires_at === null) {
            return false;
        }

        return true;
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * Job scope (D-12).
     *
     * @return BelongsToMany<RecruitmentJob, $this>
     */
    public function assignedJobs(): BelongsToMany
    {
        return $this->belongsToMany(RecruitmentJob::class)->withTimestamps();
    }

    /**
     * @return list<int>
     */
    public function assignedJobIds(): array
    {
        /** @var list<int> $ids */
        $ids = $this->assignedJobs()->pluck('recruitment_jobs.id')->map(fn ($id): int => (int) $id)->all();

        return $ids;
    }

    public function isAssignedToJob(?int $jobId): bool
    {
        return $jobId !== null && in_array($jobId, $this->assignedJobIds(), true);
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->can($permission->value);
    }
}
