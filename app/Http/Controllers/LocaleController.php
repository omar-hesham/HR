<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * User-menu language switch. Only changes the caller's own preference (D-80).
 */
final class LocaleController
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        /** @var list<string> $supported */
        $supported = config('recruitment.supported_locales', ['ar', 'en']);
        abort_unless(in_array($locale, $supported, true), 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $user->forceFill(['locale' => $locale])->save();

        $previous = url()->previous();

        return redirect()->to(str_starts_with($previous, url('/')) ? $previous : url('/admin'));
    }
}
