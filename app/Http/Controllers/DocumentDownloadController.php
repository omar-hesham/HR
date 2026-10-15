<?php

namespace App\Http\Controllers;

use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way to read a CV file (ARC-P0-04): authorized by DocumentPolicy on the
 * server, decrypted in memory, never cached, and logged (M10-P0-03).
 */
final class DocumentDownloadController
{
    public function __invoke(Request $request, Document $document, EncryptedDocumentStore $store): Response
    {
        Gate::authorize('download', $document);

        abort_if($document->path === null || ! $store->exists($document->path), 404);

        $inline = $request->boolean('inline') && $document->extension === 'pdf';
        $requestId = (string) Str::uuid();

        activity('access')
            ->causedBy($request->user())
            ->performedOn($document)
            ->event($inline ? 'document.viewed' : 'document.downloaded')
            ->withProperties([
                'document' => $document->uuid,
                'candidate_id' => $document->candidate_id,
                'request_id' => $requestId,
            ])
            ->log($inline ? 'document.viewed' : 'document.downloaded');

        $filename = sprintf('cv-%s.%s', substr($document->uuid, 0, 8), $document->extension);

        return response($store->get($document->path), 200, [
            'Content-Type' => $document->mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-Id' => $requestId,
        ]);
    }
}
