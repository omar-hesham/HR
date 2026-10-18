<?php

namespace Database\Seeders;

use App\Actions\Criteria\ApproveCriteriaVersion;
use App\Actions\Criteria\StartDraftVersion;
use App\Actions\Scoring\EvaluateApplication;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\ReviewReason;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\Extraction;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Applications\ApplicationStatusService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Synthetic, offline review walkthrough. No intake, network, extraction binary or AI calls.
 *
 * Twelve Arabic-named synthetic candidates. Special indexes the demo script relies on:
 * 2 and 3 share one email (duplicate pair), 4 is low quality, 5 is unassigned,
 * 6 is scored on the superseded criteria version. Indexes 7-11 fill the ranked
 * list across the strong/needs-review/weak bands.
 */
class DemoReviewSeeder extends Seeder
{
    private const NAMES = ['محمد سيد أحمد', 'أحمد عبد الفتاح حسن', 'محمود رمضان عبد الله', 'مصطفى كامل السيد',
        'عمر هشام مصطفى', 'خالد فتحي إبراهيم', 'حسن علي محمد', 'كريم سامي عبد العزيز', 'طارق منير سعيد',
        'إسلام رجب عثمان', 'يوسف عادل عبد الرحمن', 'سيد إبراهيم فرغلي'];

    /** Certificates (85-100 strong candidates) per index. */
    private const CERTIFIED = [0, 2, 6, 7, 11];

    /** Skill sets per index; every scored candidate still passes all must-haves. */
    private const SKILLS = [7 => ['PLC', 'schematics'], 8 => ['PLC', 'schematics'], 9 => ['wire drawing'],
        10 => ['schematics'], 11 => ['PLC', 'schematics', 'wire drawing']];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || Application::query()->where('external_reference', 'm4a-demo-0')->exists()) {
            return;
        }
        $owner = User::query()->where('email', 'owner@example.test')->first();
        $job = RecruitmentJob::query()->where('title_en', 'DEMO - Electrical maintenance technician')->first();
        if ($owner === null || $job?->current_criteria_version_id === null) {
            return;
        }
        $oldVersion = $job->current_criteria_version_id;
        $version = app(ApproveCriteriaVersion::class)->handle(app(StartDraftVersion::class)->handle($job, $owner), $owner);
        $job->refresh();
        for ($index = 0; $index < count(self::NAMES); $index++) {
            $fixture = Model::withoutEvents(fn (): array => $this->fixture($job, $index));
            $application = $fixture['application'];
            if ($index === 4) {
                app(ApplicationStatusService::class)->transition($application, ApplicationStatus::NeedsReview, null, null, ReviewReason::LowQuality);

                continue;
            }
            if ($index === 5) {
                continue;
            }
            $score = app(EvaluateApplication::class)->handle($application->id, $index === 6 ? $oldVersion : $version->id);
            if ($index === 6 && $score !== null) {
                $application->refresh()->forceFill(['current_score_id' => $score->id])->save();
                app(ApplicationStatusService::class)->transition($application, ApplicationStatus::Processing);
                app(ApplicationStatusService::class)->transition($application, ApplicationStatus::Scored);
            }
            if ($index === 0) {
                app(ApplicationStatusService::class)->transition($application->refresh(), ApplicationStatus::Shortlisted, $owner, __('review.demo_decision', [], 'ar'));
            }
        }
    }

    /** @return array{application:Application} */
    private function fixture(RecruitmentJob $job, int $index): array
    {
        $name = self::NAMES[$index];
        $email = in_array($index, [2, 3], true) ? 'demo-shared@example.test' : 'demo-'.$index.'@example.test';
        $phone = '+2010'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);
        $text = __('review.demo_text', compact('name', 'email', 'phone'), 'ar');
        $candidate = Candidate::query()->create(['uuid' => (string) Str::uuid(), 'name' => $name, 'email' => $email, 'phone' => $phone,
            'email_hmac' => hash_hmac('sha256', $email, (string) config('recruitment.hash.dedup_key')),
            'phone_hmac' => hash_hmac('sha256', $phone, (string) config('recruitment.hash.dedup_key')), 'received_at' => now()->subHours($index + 1)]);
        $word = new PhpWord;
        $section = $word->addSection();
        foreach (explode("\n", $text) as $line) {
            $section->addText($line);
        }
        ob_start();
        try {
            IOFactory::createWriter($word, 'Word2007')->save('php://output');
            $bytes = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $uuid = (string) Str::uuid();
        $document = Document::query()->create(['uuid' => $uuid, 'candidate_id' => $candidate->id,
            'path' => app(EncryptedDocumentStore::class)->put($candidate->uuid, $uuid, $bytes), 'sha256' => hash('sha256', $bytes),
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'extension' => 'docx', 'size' => strlen($bytes),
            'kind' => 'cv', 'status' => $index === 4 ? 'needs_review' : 'extracted', 'encrypted' => true]);
        $candidate->forceFill(['active_document_id' => $document->id])->save();
        $application = Application::query()->create(['uuid' => (string) Str::uuid(), 'candidate_id' => $candidate->id,
            'recruitment_job_id' => $index === 5 ? null : $job->id, 'source' => 'upload', 'external_reference' => 'm4a-demo-'.$index,
            'status' => 'received', 'received_at' => $candidate->received_at, 'last_activity_at' => $candidate->received_at]);
        app(ApplicationStatusService::class)->recordCreated($application, null);
        $source = DocumentSource::query()->create(['document_id' => $document->id, 'application_id' => $application->id, 'source' => 'upload',
            'attachment_key' => 'm4a-demo-'.$index, 'attachment_kind' => 'cv', 'disposition' => 'stored', 'original_filename_enc' => 'synthetic-demo-'.$index.'.docx',
            'review_reason' => $index === 4 ? ReviewReason::LowQuality : null, 'review_opened_at' => $index === 4 ? now()->subHours(5) : null]);
        // Realistic visibility (KPI-P0-01 target: under one hour): registered minutes after receipt, not at seed time.
        $source->forceFill(['created_at' => $candidate->received_at->copy()->addMinutes(3 + $index * 4)])->save();
        $fact = function (mixed $value, string $quote) use ($text, $document): array {
            $start = mb_strpos($text, $quote);

            return ['value' => $value, 'confidence' => 1, 'source' => 'human_correction',
                'evidence' => [['document_id' => $document->id, 'quote' => $quote, 'page' => 1, 'start' => $start, 'end' => $start + mb_strlen($quote)]]];
        };
        $skills = array_map(fn (string $skill): array => $fact($skill, $skill), self::SKILLS[$index] ?? ['PLC', 'schematics']);
        $fields = ['education' => [['level' => $fact('technical_diploma', 'Electrical diploma'), 'field' => $fact('electrical', 'Electrical diploma')]],
            'experience_total_years' => $fact(3, '3 years'), 'experience_relevant_years' => [['job_id' => (string) $job->id, 'years' => $fact(3, '3 years')]],
            'skills' => $skills, 'certifications' => in_array($index, self::CERTIFIED, true) ? [$fact('electrical safety', 'Electrical safety training')] : [],
            'languages' => [], 'previous_roles' => [], 'shift_availability' => $fact(true, 'Willing to work rotating shifts')];
        Extraction::query()->create(['document_id' => $document->id, 'extractor' => 'synthetic-demo', 'extractor_version' => 'm4a-v1',
            'status' => $index === 4 ? 'needs_review' : 'ok', 'raw_text_enc' => $text, 'normalized_text_enc' => $text, 'offset_map_enc' => '[]',
            'fields_json_enc' => $index === 4 ? null : json_encode($fields, JSON_THROW_ON_ERROR), 'schema_version' => '1.0', 'char_count' => mb_strlen($text)]);

        return compact('application');
    }
}
