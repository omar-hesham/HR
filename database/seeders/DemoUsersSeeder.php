<?php

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Synthetic accounts for local/testing only (D11). Never run in production.
 * Each account must enrol TOTP MFA at first login (Filament forces it).
 */
class DemoUsersSeeder extends Seeder
{
    /**
     * @var array<string, array{0: Role, 1: string, 2: string}>
     */
    public const USERS = [
        'owner@example.test' => [Role::Owner, 'مالك تجريبي', 'ar'],
        'manager@example.test' => [Role::HiringManager, 'مدير توظيف تجريبي', 'ar'],
        'recruiter@example.test' => [Role::Recruiter, 'مسؤول توظيف تجريبي', 'ar'],
        'coordinator@example.test' => [Role::Coordinator, 'منسق تجريبي', 'ar'],
        'management@example.test' => [Role::Management, 'Demo Management', 'en'],
        'support@example.test' => [Role::Operator, 'Demo Support', 'en'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('DemoUsersSeeder skipped in production.');

            return;
        }

        $password = (string) config('recruitment.seed_user_password', '');

        if ($password === '') {
            $this->command->warn('SEED_USER_PASSWORD is empty; demo users not created. Set it in .env (local only).');

            return;
        }

        foreach (self::USERS as $email => [$role, $name, $locale]) {
            $user = User::query()->updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => $password,
                'locale' => $locale,
                'active' => true,
                'access_expires_at' => $role === Role::Operator ? now()->addDays(30) : null,
            ]);

            $user->syncRoles([$role->value]);
        }

        $this->command->info('Demo users created (password from SEED_USER_PASSWORD; MFA enrolment at first login).');
    }
}
