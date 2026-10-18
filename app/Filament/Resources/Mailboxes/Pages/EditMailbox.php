<?php

namespace App\Filament\Resources\Mailboxes\Pages;

use App\Actions\Intake\SaveMailbox;
use App\Filament\Resources\Mailboxes\MailboxResource;
use App\Models\Mailbox;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMailbox extends EditRecord
{
    protected static string $resource = MailboxResource::class;

    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['credentials']);
        $data['credentials_input'] = [];
        $data['start_now'] = false;
        $data['display_name'] ??= $data['address'];
        $data['folder'] ??= config('recruitment.intake.folder');

        return $data;
    }

    /** @param array<string,mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Mailbox, 403);

        return app(SaveMailbox::class)->handle(MailboxResource::actor(), $data, $record);
    }

    protected function afterSave(): void
    {
        $this->data['credentials_input'] = [];
    }
}
