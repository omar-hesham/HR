<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Privacy\HiredHandover;
use App\Services\Privacy\PrivacyRequests;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class PrivacyArtifactController
{
    public function __invoke(string $artifact): Response
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);
        $file = DB::table('privacy_artifacts')->where('id', $artifact)->first();
        $bytes = $file !== null && $file->application_id !== null ? app(HiredHandover::class)->download($u, $artifact) : app(PrivacyRequests::class)->download($u, $artifact);

        return response($bytes, 200, ['Content-Type' => 'application/json; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="subject-data.json"', 'Cache-Control' => 'no-store, private']);
    }
}
