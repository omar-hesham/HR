<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\SetUserActive;
use App\Actions\Users\SyncUserAccess;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->target();

        $data['roles_input'] = $user->getRoleNames()->all();
        $data['permissions_input'] = $user->getDirectPermissions()->pluck('name')->all();
        $data['jobs_input'] = $user->assignedJobIds();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $record instanceof User, 403);
        abort_unless($actor->can('update', $record), 403);

        $record->fill(collect($data)->only(['name', 'email', 'password', 'locale', 'access_expires_at', 'name_blind_review'])->all());
        $changed = array_keys($record->getDirty());
        $record->save();

        if ($changed !== []) {
            activity('access')->causedBy($actor)->performedOn($record)->event('user.updated')
                ->withProperties(['fields' => array_values(array_diff($changed, ['password']))] + (in_array('password', $changed, true) ? ['password_changed' => true] : []))
                ->log('user.updated');
        }

        app(SetUserActive::class)->handle($record, (bool) ($data['active'] ?? $record->active), $actor);

        if ($actor->can('assignRoles', $record)) {
            app(SyncUserAccess::class)->handle(
                $record,
                array_values((array) ($data['roles_input'] ?? [])),
                array_values((array) ($data['permissions_input'] ?? [])),
                array_map('intval', array_values((array) ($data['jobs_input'] ?? []))),
                $actor,
            );
        }

        return $record;
    }

    private function target(): User
    {
        /** @var User $user */
        $user = $this->getRecord();

        return $user;
    }
}
