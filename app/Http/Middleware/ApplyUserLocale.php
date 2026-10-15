<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UI language per user (TXT-P0-02): the user's choice, else the factory default (Arabic).
 */
final class ApplyUserLocale
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('recruitment.supported_locales', ['ar', 'en']);
        $user = $request->user();

        $locale = $user instanceof User ? $user->locale : $this->settings->string('ui.default_locale');

        if (! in_array($locale, $supported, true)) {
            $locale = $supported[0];
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
