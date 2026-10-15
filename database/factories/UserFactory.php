<?php

namespace Database\Factories;

use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Synthetic users only (D11): example.test addresses, no real people.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->userName().'@example.test',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'locale' => 'ar',
            'active' => true,
            'name_blind_review' => false,
        ];
    }

    /**
     * User who has completed TOTP enrolment (random synthetic base32 secret).
     */
    public function withMfa(): static
    {
        return $this->state(fn (): array => [
            'app_authentication_secret' => self::randomBase32Secret(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    public function role(Role $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            $user->assignRole($role->value);

            if ($role === Role::Operator && $user->access_expires_at === null) {
                $user->forceFill(['access_expires_at' => now()->addDays(7)])->save();
            }
        });
    }

    public static function randomBase32Secret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }
}
