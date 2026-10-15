<?php

namespace App\Filament\Resources\RecruitmentJobs\Pages;

use App\Filament\Resources\RecruitmentJobs\RecruitmentJobResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentJob extends ViewRecord
{
    protected static string $resource = RecruitmentJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
