<?php

namespace App\Actions\Demo;

use App\Actions\Documents\StoreCvFile;
use App\Domain\Ai\DispatchContext;
use App\Domain\Documents\SourceChannel;
use App\Jobs\PollMailbox;
use App\Models\Application;
use App\Models\IntakeMessage;
use App\Models\Mailbox;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Ai\AiWorkCoordinator;
use App\Services\Ai\ConsentRepository;
use Database\Seeders\DemoJobsSeeder;
use Database\Seeders\DemoReviewSeeder;
use Illuminate\Support\Facades\DB;

final readonly class PrepareDemoAiRun
{
    public function __construct(private ResetDemoData $reset, private StoreCvFile $files, private ConsentRepository $consents) {}

    public function handle(User $actor): void
    {
        abort_unless(app()->environment('local') && $actor->isUsable() && $actor->can('ai.pipeline.manage'), 403);
        $this->reset->purge();
        app(DemoJobsSeeder::class)->run();
        $job = RecruitmentJob::query()->where('title_en', 'DEMO - Electrical maintenance technician')->firstOrFail();
        foreach (DemoReviewSeeder::syntheticTexts() as $index => $text) {
            DB::transaction(function () use ($actor, $job, $index, $text): void {
                $stored = $this->files->store(DemoReviewSeeder::documentBytes($text), 'synthetic-demo-'.$index.'.docx',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', SourceChannel::Upload,
                    actor: $actor, job: $job, externalReference: 'demo-ai-'.$index);
                if ($stored->applicationId !== null) {
                    $application = Application::query()->findOrFail($stored->applicationId);
                    $this->consents->verify(new DispatchContext(candidateId: $application->candidate_id), 'SYNTHETIC-DEMO-ONLY', $actor);
                }
            });
        }
    }

    public function withMail(User $actor): void
    {
        abort_unless(app()->environment('local') && $actor->isUsable() && $actor->can('ai.pipeline.manage'), 403);
        DB::transaction(function () use ($actor): void {
            $mailbox = Mailbox::query()->updateOrCreate(['address' => 'demo-ai-mail@example.test'], [
                'display_name' => __('brain.fixture_mailbox'), 'provider' => 'fake', 'auth_type' => 'password',
                'credential_ref' => 'ui', 'host' => base_path('tests/Fixtures/eml'), 'active' => true,
                'cursor' => null, 'start_at' => '2026-01-01 00:00:00',
            ]);
            // Hold after-commit extraction/classification until synthetic consent is recorded.
            app()->call([new PollMailbox($mailbox->id), 'handle']);
            foreach (IntakeMessage::query()->where('mailbox_id', $mailbox->id)->get() as $message) {
                $this->consents->verify(new DispatchContext(intakeId: $message->id), 'SYNTHETIC-DEMO-ONLY', $actor);
            }
            foreach (Application::query()->whereHas('sources.intakeMessage', fn ($query) => $query->where('mailbox_id', $mailbox->id))->get() as $application) {
                $this->consents->verify(new DispatchContext(candidateId: $application->candidate_id), 'SYNTHETIC-DEMO-ONLY', $actor);
            }
        });
        app(AiWorkCoordinator::class)->recover();
    }
}
