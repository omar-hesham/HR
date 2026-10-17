<?php

/**
 * Privacy auto-reply (M10-P0-01, brief §6): one notice per sender per window,
 * never to auto-generated or bulk mail, text from settings (ar/en), sent
 * through notice_outbox.
 */

use App\Domain\Documents\SourceChannel;
use App\Domain\Intake\IntakeOutcome;
use App\Jobs\SendPrivacyNotice;
use App\Mail\PrivacyNoticeMail;
use App\Models\IntakeMessage;
use App\Models\NoticeOutbox;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Storage::fake('private');
    Mail::fake();
});

it('queues and sends one notice per submission with the approved text', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '01-arabic-cv');
    pollMailbox($mailbox);

    // The sync queue sends immediately; the row keeps the audit trail.
    $notice = NoticeOutbox::query()->sole();
    $message = IntakeMessage::query()->sole();

    expect($notice->status)->toBe('sent')
        ->and($notice->sent_at)->not->toBeNull()
        ->and($notice->channel)->toBe('email')
        ->and($notice->notice_version)->toBe('1')
        ->and($notice->intake_message_id)->toBe($message->id)
        ->and($notice->application_id)->toBe($message->documentSources()->sole()->application_id)
        ->and($notice->idempotency_key)->toMatch('/^[a-f0-9]{64}$/');

    Mail::assertSent(function (PrivacyNoticeMail $mail): bool {
        return $mail->hasTo('applicant1@example.test')
            && str_contains($mail->subject, 'إشعار خصوصية')
            && str_contains($mail->body, 'privacy policy')
            && str_contains($mail->body, 'سياسة الخصوصية');
    });
    Mail::assertSent(PrivacyNoticeMail::class);
});

it('sends at most one notice per sender within the configured window', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '01-arabic-cv'); // applicant1
    pollMailbox($mailbox);

    // A second, different CV from the same sender (new message id, new file).
    pushEml($mailbox, '99-second-cv', buildEml(
        'Ahmed Hassan <applicant1@example.test>',
        '<runtime-second@example.test>',
        'CV again',
        [['filename' => 'second-cv.docx', 'bytes' => (string) file_get_contents(cvFixture('10_en_quality.docx')), 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']],
    ));
    pollMailbox($mailbox);

    expect(IntakeMessage::query()->count())->toBe(2)
        ->and(NoticeOutbox::query()->count())->toBe(1);

    // After the window passes, a new submission is answered again.
    $this->travel((int) app(SettingsRepository::class)->int('intake.privacy_notice_resend_days') + 1)->days();

    pushEml($mailbox, '99-third-cv', buildEml(
        'Ahmed Hassan <applicant1@example.test>',
        '<runtime-third@example.test>',
        'CV after the window',
        [['filename' => 'third-cv.docx', 'bytes' => (string) file_get_contents(cvFixture('12_table_cv.docx')), 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']],
    ));
    pollMailbox($mailbox);

    expect(NoticeOutbox::query()->count())->toBe(2);
});

it('never replies to auto-generated or bulk mail', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '07-auto-reply'); // Auto-Submitted: auto-replied + Precedence: bulk, CV attached
    pollMailbox($mailbox);

    $message = IntakeMessage::query()->sole();

    expect($message->outcome)->toBe(IntakeOutcome::Processed->value)
        ->and(NoticeOutbox::query()->count())->toBe(0);

    Mail::assertNothingSent();
});

it('uses the notice text from settings when provided', function () {
    app(SettingsRepository::class)->update([
        'privacy.notice_text_ar' => 'نص عربي مخصص معتمد.',
        'privacy.notice_text_en' => 'Custom approved English text.',
    ], null);

    $mailbox = fakeMailbox();
    pushEml($mailbox, '01-arabic-cv');
    pollMailbox($mailbox);

    Mail::assertSent(function (PrivacyNoticeMail $mail): bool {
        return str_contains($mail->body, 'نص عربي مخصص معتمد.')
            && str_contains($mail->body, 'Custom approved English text.');
    });
});

it('blocks retries after an uncertain transport failure, keeping the outbox visible', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '08-no-attachments'); // nothing to notice; keeps the mailbox harmless
    pollMailbox($mailbox);

    $message = IntakeMessage::query()->create([
        'mailbox_id' => $mailbox->id,
        'source' => SourceChannel::Email,
        'external_id' => 'manual-notice-test',
        'sender_enc' => 'applicant@example.test',
        'sender_hmac' => hash_hmac('sha256', 'applicant@example.test', config('recruitment.hash.dedup_key')),
        'received_at' => now(),
    ]);

    $notice = NoticeOutbox::query()->create([
        'intake_message_id' => $message->id,
        'channel' => 'email',
        'notice_version' => '1',
        'idempotency_key' => bin2hex(random_bytes(16)),
        'status' => 'pending',
    ]);

    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('smtp down'));

    $job = new SendPrivacyNotice($notice->id);

    try {
        $job->handle();
    } catch (RuntimeException) {
        // First attempt throws; the queue would retry then call failed().
    }

    $job->handle(); // A redelivered job must not send again.
    $job->failed(new RuntimeException('smtp down'));

    expect($notice->refresh()->status)->toBe('uncertain')
        ->and($notice->last_error)->not->toBeNull()
        ->and($notice->attempts)->toBe(1);
});
