<?php

namespace App\Domain\Intake;

/**
 * Detects auto-generated / bulk / loop mail so the privacy notice is never sent to
 * it (M10-P0-01). Returns a stable reason code, or null for a human sender.
 */
final class AutoReplyDetector
{
    public const REASON_AUTO_SUBMITTED = 'auto_submitted';

    public const REASON_AUTO_RESPONDER = 'auto_responder';

    public const REASON_BULK = 'bulk_mail';

    public const REASON_LIST = 'mailing_list';

    public const REASON_NO_REPLY_SENDER = 'no_reply_sender';

    public const REASON_NULL_RETURN_PATH = 'null_return_path';

    public const REASON_FEEDBACK = 'feedback_report';

    private const NO_REPLY_LOCAL = ['no-reply', 'noreply', 'no_reply', 'do-not-reply', 'donotreply', 'postmaster', 'mailer-daemon', 'bounces', 'bounce', 'notifications'];

    /**
     * @param  array<string, string>  $headers  Lower-cased names (ParsedInboundMessage::headers).
     */
    public function detect(array $headers, ?string $fromAddress): ?string
    {
        $autoSubmitted = $headers['auto-submitted'] ?? null;

        if ($autoSubmitted !== null && strtolower(trim($autoSubmitted)) !== 'no') {
            return self::REASON_AUTO_SUBMITTED;
        }

        foreach (['x-autoreply', 'x-autorespond', 'x-autoreply-from', 'x-auto-response-suppress'] as $header) {
            if (($headers[$header] ?? null) !== null) {
                return self::REASON_AUTO_RESPONDER;
            }
        }

        if (isset($headers['feedback-type']) || isset($headers['x-feedback-type']) || isset($headers['x-failed-recipients'])) {
            return self::REASON_FEEDBACK;
        }

        $precedence = strtolower(trim((string) ($headers['precedence'] ?? '')));

        if (in_array($precedence, ['bulk', 'junk'], true)) {
            return self::REASON_BULK;
        }

        if ($precedence === 'list' || isset($headers['x-mailing-list']) || $this->hasListHeader($headers)) {
            return self::REASON_LIST;
        }

        $returnPath = strtolower(trim((string) ($headers['return-path'] ?? '')));

        if ($returnPath === '<>') {
            return self::REASON_NULL_RETURN_PATH;
        }

        if ($this->isNoReplyAddress($fromAddress)) {
            return self::REASON_NO_REPLY_SENDER;
        }

        return null;
    }

    private function isNoReplyAddress(?string $address): bool
    {
        if ($address === null) {
            return false;
        }

        // Subaddressing (no-reply+alerts@…) still identifies an automated sender.
        $local = explode('+', strtolower(explode('@', $address)[0]))[0];

        return in_array($local, self::NO_REPLY_LOCAL, true);
    }

    /**
     * RFC 2369 list headers (List-Id, List-Unsubscribe, List-Post, ...) mark list mail.
     *
     * @param  array<string, mixed>  $headers
     */
    private function hasListHeader(array $headers): bool
    {
        foreach (array_keys($headers) as $name) {
            if (str_starts_with((string) $name, 'list-')) {
                return true;
            }
        }

        return false;
    }
}
