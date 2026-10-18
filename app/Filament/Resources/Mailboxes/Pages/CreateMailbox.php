<?php

namespace App\Filament\Resources\Mailboxes\Pages;

use App\Actions\Intake\SaveMailbox;
use App\Filament\Resources\Mailboxes\MailboxResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMailbox extends CreateRecord
{
    protected static string $resource = MailboxResource::class;

    /** @param array<string,mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveMailbox::class)->handle(MailboxResource::actor(), $data);
    }

    protected function afterCreate(): void
    {
        $this->data['credentials_input'] = [];
    }
}
