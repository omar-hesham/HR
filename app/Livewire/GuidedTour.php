<?php

namespace App\Livewire;

use App\Services\Onboarding\GuidedTour as Tour;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class GuidedTour extends Component
{
    #[Locked]
    public bool $open = false;

    public function mount(Tour $tour): void
    {
        $tour->user();
        $this->open = $tour->shouldStart();
    }

    public function replay(Tour $tour): void
    {
        $tour->user();
        $this->open = true;
    }

    public function complete(Tour $tour): void
    {
        $tour->complete();
        $this->open = false;
    }

    public function skip(Tour $tour): void
    {
        $this->complete($tour);
    }

    public function render(): View
    {
        return view('livewire.guided-tour', ['steps' => app(Tour::class)->steps()]);
    }
}
