<?php

/**
 * Generates the SYNTHETIC .eml fixtures in tests/Fixtures/eml/ (Milestone 2).
 * Every person, address and domain below is invented; example.test is reserved.
 * The EICAR "infected" message is NEVER generated here — tests build it at
 * run time from parts (brief §9).
 *
 * Run: php tests/Fixtures/eml/generate.php   (after composer fixtures)
 */

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

$out = __DIR__;

$cvs = dirname(__DIR__).'/cvs';

/** 1x1 transparent PNG (synthetic). */
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/**
 * @param  array<string, string>  $headers
 * @param  list<array{filename: string, content: string, type: string, inline: bool, id?: string}>  $attachments
 */
function eml(array $headers, string $body, array $attachments = []): string
{
    $lines = [];

    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }

    if ($attachments === []) {
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';
        $lines[] = '';
        $lines[] = $body;

        return implode("\r\n", $lines)."\r\n";
    }

    $boundary = '=_recruit'.bin2hex(random_bytes(6));
    $lines[] = 'MIME-Version: 1.0';
    $lines[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
    $lines[] = '';
    $parts = ["--{$boundary}", 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit', '', $body, ''];

    foreach ($attachments as $attachment) {
        $parts[] = "--{$boundary}";
        $parts[] = "Content-Type: {$attachment['type']}; name=\"{$attachment['filename']}\"";

        if (isset($attachment['id'])) {
            $parts[] = "Content-ID: <{$attachment['id']}>";
        }

        $parts[] = 'Content-Disposition: '.($attachment['inline'] ? 'inline' : 'attachment')."; filename=\"{$attachment['filename']}\"";
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = '';
        $parts[] = chunk_split($attachment['content'], 76, "\r\n");
    }

    $parts[] = "--{$boundary}--";

    return implode("\r\n", $lines)."\r\n".implode("\r\n", $parts);
}

function attach(string $path, string $filename, string $type = 'application/octet-stream', bool $inline = false, ?string $id = null): array
{
    $bytes = (string) file_get_contents($path);

    return [
        'filename' => $filename,
        'content' => base64_encode($bytes),
        'type' => $type,
        'base64' => true,
        'inline' => $inline,
        'id' => $id,
    ];
}

function png(string $filename, bool $inline = false, ?string $id = null): array
{
    return ['filename' => $filename, 'content' => PNG_1X1, 'type' => 'image/png', 'base64' => true, 'inline' => $inline, 'id' => $id];
}

function write(string $name, string $content): void
{
    file_put_contents(__DIR__."/{$name}", $content);
}

$englishCv = attach($cvs.'/02_en_accountant.pdf', 'john-smith-cv.pdf', 'application/pdf');
$arabicCv = attach($cvs.'/01_ar_electrician.pdf', 'cv-ahmed-hassan.pdf', 'application/pdf');

// 01: Arabic body + Arabic PDF CV.
write('01-arabic-cv.eml', eml([
    'From' => 'Ahmed Hassan <applicant1@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'طلب وظيفة فني كهرباء',
    'Date' => 'Wed, 30 Sep 2026 09:00:00 +0000',
    'Message-ID' => '<fixture-01@example.test>',
], 'السلام عليكم، أرفق سيرتي الذاتية لوظيفة فني الكهرباء. شكرًا لكم.', [$arabicCv]));

// 02: English body + English PDF CV.
write('02-english-cv.eml', eml([
    'From' => 'John Smith <applicant2@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'Application: accountant position',
    'Date' => 'Wed, 30 Sep 2026 09:30:00 +0000',
    'Message-ID' => '<fixture-02@example.test>',
], 'Dear hiring team, please find my CV attached for the accountant position.', [$englishCv]));

// 03: Two CVs in one message (each is an independent review item, M1-P0-05).
write('03-two-cvs.eml', eml([
    'From' => 'Sara Refaat <applicant3@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'CVs of two relatives applying for technician roles',
    'Date' => 'Wed, 30 Sep 2026 10:00:00 +0000',
    'Message-ID' => '<fixture-03@example.test>',
], 'Both CVs are attached; each applicant applied separately.', [
    attach($cvs.'/03_mixed_plc_technician.pdf', 'first-cv.pdf', 'application/pdf'),
    attach($cvs.'/04_two_column.pdf', 'second-cv.pdf', 'application/pdf'),
]));

// 04: CV + image + unsupported file: one stored, image to review, other logged.
write('04-mixed-attachments.eml', eml([
    'From' => 'Mona Adel <applicant4@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'Application with photo and notes',
    'Date' => 'Wed, 30 Sep 2026 11:00:00 +0000',
    'Message-ID' => '<fixture-04@example.test>',
], 'My CV, a recent photo and a note file are attached.', [
    attach($cvs.'/05_table_layout.pdf', 'mona-cv.pdf', 'application/pdf'),
    png('personal-photo.png'),
    ['filename' => 'notes.txt', 'content' => base64_encode("Synthetic note.\r\nSecond line.\r\n"), 'type' => 'text/plain', 'base64' => true, 'inline' => false],
]));

// 05: Image-only "CV": goes to review, no OCR, not stored.
write('05-image-only-cv.eml', eml([
    'From' => 'Hazem Kamal <applicant5@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'My CV as a photo',
    'Date' => 'Wed, 30 Sep 2026 12:00:00 +0000',
    'Message-ID' => '<fixture-05@example.test>',
], 'I do not have a PDF version; my CV is a photo.', [png('my-cv.png')]));

// 06: Forwarded message as message/rfc822: uncertain, to review.
$inner = eml([
    'From' => 'Forwarded Friend <forwarded@example.test>',
    'To' => 'applicant6@example.test',
    'Subject' => 'FW: technician CV',
    'Date' => 'Tue, 29 Sep 2026 15:00:00 +0000',
    'Message-ID' => '<fixture-06-inner@example.test>',
], 'Forwarding this CV.', [attach($cvs.'/09_ar_storekeeper.docx', 'storekeeper-cv.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]);

write('06-forwarded-cv.eml', eml([
    'From' => 'Nader Fathy <applicant6@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'CV forwarded to me',
    'Date' => 'Wed, 30 Sep 2026 13:00:00 +0000',
    'Message-ID' => '<fixture-06@example.test>',
], 'A friend forwarded me this vacancy; forwarding the CV I received.', [
    ['filename' => 'forwarded.eml', 'content' => base64_encode($inner), 'type' => 'message/rfc822', 'base64' => true, 'inline' => false],
]));

// 07: Auto-reply header with a CV attached: processed, but NEVER answered (M10-P0-01).
write('07-auto-reply.eml', eml([
    'From' => 'Mail Bot <applicant7@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'Automatic follow-up',
    'Date' => 'Wed, 30 Sep 2026 14:00:00 +0000',
    'Message-ID' => '<fixture-07@example.test>',
    'Auto-Submitted' => 'auto-replied',
    'Precedence' => 'bulk',
], 'This is an automatic message.', [attach($cvs.'/10_en_quality.docx', 'auto-cv.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]));

// 08: No attachments: ignored with reason; the stored raw is dropped (D-108).
write('08-no-attachments.eml', eml([
    'From' => 'Curious Person <asker@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'Question about open positions',
    'Date' => 'Wed, 30 Sep 2026 15:00:00 +0000',
    'Message-ID' => '<fixture-08@example.test>',
], 'Are there any open positions right now? No CV attached.'));

// 09: DOCX CV.
write('09-docx-cv.eml', eml([
    'From' => 'Youssef Emad <applicant8@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'Storekeeper application',
    'Date' => 'Wed, 30 Sep 2026 16:00:00 +0000',
    'Message-ID' => '<fixture-09@example.test>',
], 'Attached is my CV in Word format.', [attach($cvs.'/09_ar_storekeeper.docx', 'youssef-cv.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]));

// 10: Same PDF bytes as 02 under a new Message-ID: file-level duplicate (M3-P0-01).
write('10-duplicate-file.eml', eml([
    'From' => 'John Smith Again <applicant2@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'Re-sending my CV',
    'Date' => 'Thu, 01 Oct 2026 08:00:00 +0000',
    'Message-ID' => '<fixture-10@example.test>',
], 'Sending the same CV again.', [$englishCv]));

// 11: Same Message-ID as 02 with different content: message-level duplicate.
write('11-duplicate-message-id.eml', eml([
    'From' => 'John Smith <applicant2@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'Application: accountant position (copy)',
    'Date' => 'Thu, 01 Oct 2026 08:30:00 +0000',
    'Message-ID' => '<fixture-02@example.test>',
], 'A different body under a duplicated Message-ID.', [attach($cvs.'/11_docx_no_contacts.docx', 'copy-cv.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]));

// 12: Explicit job title in the subject (M1-P0-02 job hint).
write('12-job-title.eml', eml([
    'From' => ' Kareem Samir <applicant9@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'Application for Production Supervisor',
    'Date' => 'Thu, 01 Oct 2026 09:00:00 +0000',
    'Message-ID' => '<fixture-12@example.test>',
], 'Please consider my application for the Production Supervisor role.', [attach($cvs.'/12_table_cv.docx', 'kareem-cv.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]));

// 13: Inline cid image (signature logo) + a real CV: image logged, not reviewed.
write('13-inline-image.eml', eml([
    'From' => 'Laila Mostafa <applicant10@example.test>',
    'To' => 'recruitment@example.test',
    'Subject' => 'Technician application',
    'Date' => 'Thu, 01 Oct 2026 10:00:00 +0000',
    'Message-ID' => '<fixture-13@example.test>',
], 'CV attached; the small logo below my signature is inline.', [
    attach($cvs.'/05_table_layout.pdf', 'laila-cv.pdf', 'application/pdf'),
    png('logo.png', inline: true, id: 'logo@fixture'),
]));

echo count(glob($out.'/*.eml') ?: []).' .eml fixtures written.'.PHP_EOL;
