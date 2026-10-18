<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Applications\ApplicationResource;
use Filament\Pages\Dashboard;

/**
 * The panel root. The review list is the product's home screen, so the root only
 * redirects there; this does not depend on navigation order or the UI language.
 */
class Home extends Dashboard
{
    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $this->redirect(ApplicationResource::getUrl('index'));
    }
}
