<?php

namespace App\Filament\Resources\Applications\Pages;

use App\Filament\Pages\UploadDocuments;
use App\Filament\Resources\Applications\ApplicationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListApplications extends ListRecords
{
    protected static string $resource = ApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label(__('app.upload.title'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->url(UploadDocuments::getUrl())
                ->visible(fn (): bool => UploadDocuments::canAccess()),
        ];
    }
}
