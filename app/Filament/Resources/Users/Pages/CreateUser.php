<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\SyncUserAccess;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $user = User::query()->create(collect($data)->only([
            'name', 'email', 'password', 'locale', 'active', 'access_expires_at', 'name_blind_review',
        ])->all());

        activity('access')->causedBy($actor)->performedOn($user)->event('user.created')->log('user.created');

        if (UserResource::canAssign(null)) {
            app(SyncUserAccess::class)->handle(
                $user,
                array_values((array) ($data['roles_input'] ?? [])),
                array_values((array) ($data['permissions_input'] ?? [])),
                array_map('intval', array_values((array) ($data['jobs_input'] ?? []))),
                $actor,
            );
        }

        return $user;
    }
}
