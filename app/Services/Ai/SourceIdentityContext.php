<?php

namespace App\Services\Ai;

use App\Domain\Intake\Contracts\MessageParser;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\Document;

final class SourceIdentityContext
{
    /** @return array{sender:?string,filename:?string} */
    public function forDocument(Document $document): array
    {
        $source = $document->sources()->with('intakeMessage')->orderBy('id')->first();
        $message = $source?->intakeMessage;
        $sender = null;
        $store = app(EncryptedDocumentStore::class);
        if ($message?->raw_path !== null && $store->exists($message->raw_path)) {
            $from = app(MessageParser::class)->parse($store->get($message->raw_path))->header('from');
            if ($from !== null && preg_match('/^\s*([^<]+)\s*</u', $from, $match)) {
                $sender = trim(mb_decode_mimeheader($match[1]), ' "');
            }
        }

        return ['sender' => $sender, 'filename' => $source?->originalFilename()];
    }
}
