<?php

namespace App\Infrastructure\Intake;

use App\Domain\Intake\Contracts\MessageParser;
use App\Domain\Intake\InboundAttachment;
use App\Domain\Intake\MessageParseFailure;
use App\Domain\Intake\ParsedInboundMessage;
use Illuminate\Support\Carbon;
use Webklex\PHPIMAP\Message;

/**
 * Parses raw RFC 5322 bytes with webklex/php-imap's offline parser
 * (`Message::fromString`), so the fake and the IMAP driver share one code
 * path (D-100). Envelope headers are read from the raw text directly so
 * auto-reply detection never depends on vendor magic.
 */
final class WebklexMessageParser implements MessageParser
{
    public function parse(string $raw): ParsedInboundMessage
    {
        $headers = $this->parseRawHeaders($raw);

        try {
            $message = Message::fromString($raw);
        } catch (\Throwable) {
            throw MessageParseFailure::malformed();
        }

        $attachments = [];

        $collection = $message->getAttachments();

        foreach ($collection as $index => $attachment) {
            $filename = $attachment->getName();
            $content = $attachment->getContent();

            if (! is_string($content)) {
                throw MessageParseFailure::malformed();
            }

            $attachments[] = new InboundAttachment(
                partId: (string) ($index + 1),
                filename: is_string($filename) && $filename !== '' ? $filename : null,
                mime: $attachment->getMimeType(),
                bytes: $content,
                inline: $attachment->getDisposition() === 'inline',
            );
        }

        if (strlen($headers['message-id'] ?? '') > 512) {
            throw MessageParseFailure::malformed();
        }

        return new ParsedInboundMessage(
            messageIdHeader: $this->normalizeHeaderValue($headers['message-id'] ?? null),
            subject: $this->decodeSubject($message, $headers['subject'] ?? null),
            fromAddress: $this->extractAddress($headers['from'] ?? null),
            receivedAt: $this->parseDate($headers['date'] ?? null),
            headers: $headers,
            attachments: $attachments,
            textBody: (string) $message->getTextBody(),
        );
    }

    /**
     * Split the raw message at the first blank line and unfold continuation lines.
     *
     * @return array<string, string>
     */
    private function parseRawHeaders(string $raw): array
    {
        $normalized = str_replace("\r\n", "\n", $raw);
        $blank = strpos($normalized, "\n\n");

        if ($blank === false) {
            throw MessageParseFailure::malformed();
        }

        $headerBlock = substr($normalized, 0, $blank);
        $headers = [];

        foreach (explode("\n", $headerBlock) as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
                // Folded continuation of the previous header.
                $keys = array_keys($headers);
                $last = $keys === [] ? null : $keys[count($keys) - 1];

                if ($last !== null) {
                    $headers[$last] .= ' '.trim($line);
                }

                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            $headers[$name] = trim(substr($line, $separator + 1));
        }

        if ($headers === []) {
            throw MessageParseFailure::malformed();
        }

        return $headers;
    }

    private function decodeSubject(Message $message, ?string $rawSubject): ?string
    {
        $header = $message->getHeader();

        if ($header !== null && $header->has('subject')) {
            $decoded = $header->get('subject')->toString();

            if ($decoded !== '') {
                return $decoded;
            }
        }

        return $this->normalizeHeaderValue($rawSubject);
    }

    private function normalizeHeaderValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remove RFC 5322 comments and trim; keep the encoded form otherwise.
        $value = trim((string) preg_replace('/\s*\([^)]*\)\s*/', ' ', $value));

        return $value === '' ? null : $value;
    }

    /**
     * Extract the first addr-spec from a From/Return-Path header value.
     */
    private function extractAddress(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $matches) === 1) {
            return strtolower($matches[1]);
        }

        if (preg_match('/[^\s<>]+@[^\s<>]+/', $value, $matches) === 1) {
            return strtolower($matches[0]);
        }

        return null;
    }

    private function parseDate(?string $value): Carbon
    {
        if ($value !== null) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                // Fall through to "now": received_at must always exist.
            }
        }

        return Carbon::now();
    }
}
