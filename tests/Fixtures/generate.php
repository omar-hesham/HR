<?php

/**
 * Generates the SYNTHETIC CV fixtures in tests/Fixtures/cvs/ (QA-P0-02, D11).
 * Every person, phone and email below is invented; example.test is a reserved domain.
 * Run: composer fixtures   (needs dev dependencies: mpdf/mpdf, phpoffice/phpword)
 */

declare(strict_types=1);

use Mpdf\Mpdf;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Language;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$out = __DIR__.'/cvs';

if (! is_dir($out)) {
    mkdir($out, 0775, true);
}

$tmp = sys_get_temp_dir().'/recruit-fixtures-mpdf';

if (! is_dir($tmp)) {
    mkdir($tmp, 0775, true);
}

/**
 * @param  array<string, mixed>  $options
 */
function pdf(string $path, string $html, array $options = [], ?callable $configure = null): void
{
    global $tmp;

    $mpdf = new Mpdf(array_merge([
        'mode' => 'utf-8',
        'format' => 'A4',
        'tempDir' => $tmp,
        'default_font' => 'dejavusans',
        'autoScriptToLang' => true,
        'autoLangToFont' => true,
    ], $options));

    // Fixed metadata so regenerated files are byte-identical where possible.
    $mpdf->SetCreator('recruit-app synthetic fixtures');
    $mpdf->SetAuthor('synthetic');

    if ($configure !== null) {
        $configure($mpdf);
    }

    $mpdf->WriteHTML($html);
    $mpdf->Output($path, 'F');
}

function docx(string $path, callable $build): void
{
    $word = new PhpWord;
    $word->getSettings()->setThemeFontLang(new Language('ar-EG'));
    $build($word);
    $word->save($path, 'Word2007');
}

$rtl = '<html dir="rtl"><body style="font-family: dejavusans; direction: rtl;">';
$ltr = '<html><body style="font-family: dejavusans;">';
$end = '</body></html>';

// 01 — Arabic PDF, electrician (presentation forms expected from the PDF text layer).
pdf("$out/01_ar_electrician.pdf", $rtl.'
<h2>السيرة الذاتية</h2>
<p><b>الاسم:</b> سامح عبد الرحيم منصور</p>
<p><b>الهاتف:</b> <span dir="ltr">0100 000 1001</span> — <b>البريد الإلكتروني:</b> <span dir="ltr">sameh.synthetic@example.test</span></p>
<h3>المؤهل الدراسي</h3>
<p>دبلوم صناعي قسم كهرباء، دفعة 2016، تقدير جيد جدا.</p>
<h3>الخبرات العملية</h3>
<p>فني صيانة كهرباء في مصنع كابلات بمدينة العاشر من رمضان من 2018 حتى الآن.</p>
<p>المهام: صيانة وقائية للمحركات ولوحات التحكم، تشخيص الأعطال، قراءة المخططات الكهربائية، تشغيل ماكينات السحب.</p>
<h3>المهارات</h3>
<p>صيانة المحركات، لوحات التحكم، القياس والمعايرة، السلامة المهنية، العمل بنظام الورديات.</p>
<h3>الدورات</h3>
<p>دورة السلامة الكهربائية، دورة إطفاء الحريق.</p>
<h3>اللغات</h3>
<p>العربية: اللغة الأم. الإنجليزية: متوسط.</p>
'.$end);

// 02 — English PDF, accountant.
pdf("$out/02_en_accountant.pdf", $ltr.'
<h2>Curriculum Vitae</h2>
<p><b>Name:</b> Mariam Fathy Nassar<br/><b>Mobile:</b> +20 100 000 1002<br/><b>Email:</b> mariam.synthetic@example.test</p>
<h3>Education</h3>
<p>Bachelor of Commerce, Accounting department, Zagazig University, 2015. Grade: very good.</p>
<h3>Professional Experience</h3>
<p>Senior Accountant, industrial company, 2019 - present. Responsibilities: general ledger, bank reconciliation, monthly financial statements, taxes, payroll, inventory costing.</p>
<p>Accountant, trading company, 2015 - 2019. Prepared invoices, accounts payable and receivable reports.</p>
<h3>Skills</h3>
<p>Microsoft Excel, ERP systems, financial analysis, attention to detail, teamwork.</p>
<h3>Languages</h3>
<p>Arabic: native. English: very good.</p>
'.$end);

// 03 — Mixed Arabic/English PDF with PLC terms, email and phone.
pdf("$out/03_mixed_plc_technician.pdf", $rtl.'
<h2>السيرة الذاتية - PLC Technician</h2>
<p><b>الاسم:</b> كريم حسن الشاذلي</p>
<p><b>Email:</b> <span dir="ltr">karim.synthetic@example.test</span> &nbsp; <b>Mobile:</b> <span dir="ltr">(012) 0000-1003</span></p>
<h3>الخبرة</h3>
<p>فني PLC في مصنع أسلاك ومشتركات كهربائية من 2017 حتى الآن. برمجة Siemens S7 وتشخيص أعطال HMI و SCADA.</p>
<p>Electrical maintenance technician, extrusion lines, 2014 - 2017.</p>
<h3>المؤهل</h3>
<p>دبلوم فني صناعي - كهرباء (Industrial Technical Diploma, Electrical).</p>
<h3>Skills / المهارات</h3>
<p>PLC, HMI, قراءة المخططات، صيانة وقائية، Microsoft Excel.</p>
'.$end);

// 04 — Two-column layout.
pdf("$out/04_two_column.pdf", $ltr.'
<h2 style="text-align:center">Mostafa Adel Ragab — Quality Control Engineer</h2>
<columns column-count="2" vAlign="J" column-gap="8" />
<h3>Profile</h3>
<p>Quality control engineer with six years of experience in cable manufacturing plants, inspection of copper wire, insulation testing and ISO documentation.</p>
<h3>Experience</h3>
<p>Quality Control Engineer, wires factory, 2020 - present. Daily inspection reports, calibration of measuring instruments, customer complaints handling.</p>
<p>Production Engineer, plastics plant, 2018 - 2020.</p>
<columnbreak />
<h3>Contact</h3>
<p>Phone: 0111 000 1004<br/>Email: mostafa.synthetic@example.test</p>
<h3>Education</h3>
<p>Bachelor of Engineering, Electrical Power, 2017.</p>
<h3>Certifications</h3>
<p>ISO 9001 internal auditor; occupational safety and health.</p>
<h3>Languages</h3>
<p>Arabic native, English very good.</p>
'.$end);

// 05 — Table layout CV (Arabic + English).
pdf("$out/05_table_layout.pdf", $rtl.'
<h2>سيرة ذاتية</h2>
<table border="1" cellpadding="6" style="border-collapse: collapse; width: 100%;">
<tr><td><b>الاسم</b></td><td>هبة سعيد الدمرداش</td></tr>
<tr><td><b>الهاتف</b></td><td dir="ltr">0101 000 1005</td></tr>
<tr><td><b>البريد</b></td><td dir="ltr">heba.synthetic@example.test</td></tr>
<tr><td><b>المؤهل</b></td><td>بكالوريوس تجارة - شعبة محاسبة</td></tr>
<tr><td><b>الخبرة</b></td><td>أمين مخزن في شركة توزيع من 2016 حتى 2021، ثم مسؤول مشتريات حتى الآن</td></tr>
<tr><td><b>المهارات</b></td><td>إدارة المخزون، الجرد، أنظمة ERP، Microsoft Excel</td></tr>
<tr><td><b>اللغات</b></td><td>العربية، الإنجليزية جيد</td></tr>
</table>
'.$end);

// 06 — Image-only PDF (scanned look): no text layer at all.
$image = imagecreatetruecolor(1240, 1754);
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 20, 20, 20);
imagefilledrectangle($image, 0, 0, 1240, 1754, $white);
$lines = ['CURRICULUM VITAE (SCANNED, SYNTHETIC)', 'Name: Synthetic Scan Person', 'Phone: 0100 000 1006', 'Experience: machine operator, 5 years', 'Education: technical diploma'];
foreach ($lines as $i => $line) {
    imagestring($image, 5, 120, 160 + $i * 60, $line, $black);
}
imagepng($image, "$tmp/scan.png");
imagedestroy($image);
pdf("$out/06_image_only_scan.pdf", '<html><body><img src="'.$tmp.'/scan.png" style="width: 180mm;" /></body></html>');

// 07 — Password-protected PDF (user password required to open).
pdf("$out/07_password_protected.pdf", $ltr.'<h2>Protected CV</h2><p>Synthetic candidate, warehouse supervisor, eight years of experience.</p>'.$end, [], function (Mpdf $mpdf): void {
    $mpdf->SetProtection(['print'], 'synthetic-user-pass', 'synthetic-owner-pass');
});

// 08 — Exact duplicate of 02 (byte-identical copy).
copy("$out/02_en_accountant.pdf", "$out/08_en_accountant_duplicate.pdf");

// 09 — Arabic DOCX, storekeeper.
docx("$out/09_ar_storekeeper.docx", function (PhpWord $word): void {
    $section = $word->addSection();
    $p = ['rtl' => true];
    $f = ['rtl' => true, 'name' => 'Arial', 'size' => 12];
    $section->addText('السيرة الذاتية', ['rtl' => true, 'bold' => true, 'size' => 16], $p);
    $section->addText('الاسم: محمود جمال السيد', $f, $p);
    $section->addText('الهاتف: 01000001009 - البريد: mahmoud.synthetic@example.test', $f, $p);
    $section->addText('المؤهل: دبلوم تجارة، 2012.', $f, $p);
    $section->addText('الخبرة: أمين مخزن في مصنع مشتركات كهربائية من 2014 حتى الآن. استلام الخامات وصرفها، الجرد الشهري، متابعة المخزون على نظام ERP.', $f, $p);
    $section->addText('المهارات: إدارة المخازن، الجرد، Microsoft Excel، الالتزام بالمواعيد.', $f, $p);
    $section->addText('اللغات: العربية.', $f, $p);
});

// 10 — English DOCX, quality engineer.
docx("$out/10_en_quality.docx", function (PhpWord $word): void {
    $section = $word->addSection();
    $section->addTitle('Curriculum Vitae', 1);
    $section->addText('Name: Omneya Samir Khalil');
    $section->addText('Email: omneya.synthetic@example.test | Phone: +20 122 000 1010');
    $section->addText('Education: Bachelor of Engineering, Mechanical Power, 2018.');
    $section->addText('Experience: Quality assurance engineer at a cable factory, 2019 - present. Inspection of insulation thickness, ISO 9001 documentation, internal audit, corrective actions.');
    $section->addText('Skills: quality control, measurement, calibration, Microsoft Office, teamwork, problem solving.');
    $section->addText('Languages: Arabic (native), English (fluent).');
});

// 11 — DOCX without any contact details (mixed language).
docx("$out/11_docx_no_contacts.docx", function (PhpWord $word): void {
    $section = $word->addSection();
    $p = ['rtl' => true];
    $f = ['rtl' => true];
    $section->addText('سيرة ذاتية - Machine Operator', $f, $p);
    $section->addText('الاسم: ياسر فؤاد', $f, $p);
    $section->addText('الخبرة: مشغل ماكينات في خط إنتاج الأسلاك لمدة 4 سنوات. Operating extrusion machines and packing.', $f, $p);
    $section->addText('المؤهل: دبلوم صناعي.', $f, $p);
    $section->addText('مستعد للعمل بنظام الورديات.', $f, $p);
});

// 12 — DOCX with a table layout.
docx("$out/12_table_cv.docx", function (PhpWord $word): void {
    $section = $word->addSection();
    $section->addText('Curriculum Vitae — Electrical Technician');
    $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999']);
    $rows = [
        ['Name', 'Tamer Wagdy Ibrahim'],
        ['Phone', '0100 000 1012'],
        ['Email', 'tamer.synthetic@example.test'],
        ['Education', 'Technical diploma, electricity, 2013'],
        ['Experience', 'Electrical maintenance technician, power strips factory, 2015 - present'],
        ['Skills', 'Wiring, control panels, troubleshooting, PLC basics, preventive maintenance'],
        ['Languages', 'Arabic, English basic'],
    ];
    foreach ($rows as [$label, $value]) {
        $table->addRow();
        $table->addCell(2500)->addText($label);
        $table->addCell(6500)->addText($value);
    }
});

// 13 — Arabic DOCX in visual (reversed) character order, as some PDF converters produce.
docx("$out/13_reversed_arabic.docx", function (PhpWord $word): void {
    $reverse = static function (string $line): string {
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_reverse($chars));
    };
    $section = $word->addSection();
    foreach ([
        'السيرة الذاتية',
        'الخبرة العملية في صيانة الكهرباء والمحركات ولوحات التحكم',
        'المؤهل الدراسي دبلوم صناعي قسم كهرباء',
        'المهارات قراءة المخططات والسلامة المهنية والعمل بنظام الورديات',
        'الدورات التدريبية في السلامة الكهربائية والإطفاء',
        'اللغات العربية والإنجليزية',
    ] as $line) {
        $section->addText($reverse($line));
    }
});

// 14 — Corrupt PDF (valid header, truncated body).
file_put_contents("$out/14_corrupt.pdf", "%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj << /Type /Pa");

// 15 — Wrong type: PNG bytes with a .pdf extension.
copy("$tmp/scan.png", "$out/15_wrong_type.pdf");

// 16 — Legacy .doc placeholder: OLE2 signature + filler (no real Word content).
file_put_contents("$out/16_legacy.doc", "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504).'SYNTHETIC LEGACY DOC PLACEHOLDER');

echo "Fixtures written to $out\n";
