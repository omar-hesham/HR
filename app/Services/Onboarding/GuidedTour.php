<?php

namespace App\Services\Onboarding;

use App\Models\User;

class GuidedTour
{
    public function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->isUsable() && filled($user->getAppAuthenticationSecret()), 403);

        return $user;
    }

    public function shouldStart(): bool
    {
        return request()->routeIs('filament.admin.resources.applications.index')
            && $this->user()->tour_completed_at === null;
    }

    public function complete(): void
    {
        // No client-supplied user id or timestamp, including during replay.
        User::query()->whereKey($this->user()->id)->whereNull('tour_completed_at')
            ->update(['tour_completed_at' => now()]);
    }

    /** @return list<array{title: string, body: string, target: ?string}> */
    public function steps(): array
    {
        $targets = [
            'welcome' => null,
            'screening' => '[data-tour="screening"] .fi-sidebar-group-btn',
            'top_three' => '[data-tour="top-three"]',
            'ranking' => '[data-tour="ranking"]',
            'exceptions' => '[data-tour="exceptions"]',
            'upload' => '[data-tour="upload"], a[href="'.route('filament.admin.pages.upload').'"]',
            'needs_review' => 'a[href="'.route('filament.admin.resources.needs-review.index').'"]',
            'evidence' => '[data-section="ranked"] tbody a, [data-section="exceptions"] tbody a',
            'export' => '[data-tour="export"]',
            'weekly' => 'a[href="'.route('filament.admin.pages.weekly-summary').'"]',
            'user_menu' => '.fi-user-menu-trigger',
            'finish' => null,
        ];

        $steps = [];

        foreach ($targets as $key => $target) {
            $steps[] = [
                'title' => __('tour.steps.'.$key.'.title'),
                'body' => __('tour.steps.'.$key.'.body'),
                'target' => $target,
            ];
        }

        return $steps;
    }
}
