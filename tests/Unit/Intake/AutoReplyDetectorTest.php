<?php

/**
 * M10-P0-01: loop and automated-mail suppression for the privacy notice.
 */

use App\Domain\Intake\AutoReplyDetector;

it('detects the common auto-generated headers', function (array $headers, string $expectedReason) {
    expect((new AutoReplyDetector)->detect($headers, 'person@example.test'))->toBe($expectedReason);
})->with([
    'auto-submitted auto' => [['auto-submitted' => 'auto-replied'], 'auto_submitted'],
    'auto-submitted auto-generated' => [['auto-submitted' => 'auto-generated'], 'auto_submitted'],
    'x-autoreply' => [['x-autoreply' => 'yes'], 'auto_responder'],
    'x-autorespond' => [['x-autorespond' => 'vacation'], 'auto_responder'],
    'precedence bulk' => [['precedence' => 'bulk'], 'bulk_mail'],
    'precedence junk' => [['precedence' => 'junk'], 'bulk_mail'],
    'list-id' => [['list-id' => '<dev.list.example.test>'], 'mailing_list'],
    'list-unsubscribe only' => [['list-unsubscribe' => '<mailto:leave@list.example.test>'], 'mailing_list'],
    'null return path' => [['return-path' => '<>'], 'null_return_path'],
    'feedback type' => [['feedback-type' => 'abuse'], 'feedback_report'],
]);

it('detects no-reply senders by local part', function (string $address) {
    expect((new AutoReplyDetector)->detect([], $address))->toBe('no_reply_sender');
})->with([
    'no-reply' => ['no-reply@corp.example.test'],
    'noreply' => ['noreply@corp.example.test'],
    'postmaster' => ['postmaster@example.test'],
    'mailer-daemon' => ['mailer-daemon@example.test'],
    'subaddressed no-reply' => ['no-reply+alerts@corp.example.test'],
]);

it('lets normal senders through', function () {
    $detector = new AutoReplyDetector;

    expect($detector->detect(['auto-submitted' => 'no'], 'ahmed@example.test'))->toBeNull()
        ->and($detector->detect(['precedence' => 'first-class'], 'sara@example.test'))->toBeNull()
        ->and($detector->detect([], 'mona@example.test'))->toBeNull();
});
