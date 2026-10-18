<?php

namespace App\Filament\Resources\Mailboxes\Pages;

use App\Filament\Pages\IntakeHealth;
use App\Filament\Resources\Mailboxes\MailboxResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMailboxes extends ListRecords
{
    protected static string $resource = MailboxResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), Action::make('intakeHealth')->label(__('app.intake.title'))
            ->url(IntakeHealth::getUrl())->visible(fn (): bool => IntakeHealth::canAccess())];
    }

    public function getSubheading(): ?string
    {
        return __('mailboxes.read_only');
    }
}
