<?php

namespace App\Http\Controllers;

use App\Models\ReviewExport;
use App\Models\User;
use App\Services\Review\ExportApplications;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReviewExportController
{
    public function __invoke(Request $request, ReviewExport $export, ExportApplications $exports): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $bytes = $exports->download($user, $export);

        return response($bytes, 200, [
            'Content-Type' => $export->format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="review-'.$export->id.'.'.$export->format.'"',
            'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
