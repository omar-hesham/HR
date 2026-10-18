<?php

namespace App\Console\Commands;

use App\Actions\Demo\PrepareDemoAiRun;
use App\Actions\Demo\RescoreDemoAiRun;
use App\Models\User;
use App\Services\Demo\DemoAiSummary;
use Illuminate\Console\Command;

final class DemoAiRun extends Command
{
    protected $signature = 'recruit:demo-ai-run {--with-mail} {--work} {--summary} {--rescore} {--actor=owner@example.test}';

    public function getDescription(): string
    {
        return __('brain.command_description');
    }

    public function handle(PrepareDemoAiRun $prepare, DemoAiSummary $summary, RescoreDemoAiRun $rescore): int
    {
        if (! app()->environment('local')) {
            $this->error(__('brain.local_only'));

            return self::FAILURE;
        }
        if ($this->option('rescore') && ($this->option('work') || $this->option('with-mail'))) {
            $this->error(__('brain.rescore_options'));

            return self::FAILURE;
        }
        if ($this->option('rescore') || ! $this->option('summary')) {
            $actor = User::query()->where('email', $this->option('actor'))->first();
            if ($actor === null || ! $actor->isUsable() || ! $actor->can('ai.pipeline.manage')) {
                $this->error(__('brain.actor_required'));

                return self::FAILURE;
            }
            if ($this->option('rescore')) {
                $this->info(__('brain.rescored', $rescore->handle($actor)));
            } else {
                $prepare->handle($actor);
                if ($this->option('with-mail')) {
                    $prepare->withMail($actor);
                }
                $this->info(__('brain.queued'));
            }
        }
        if ($this->option('work')) {
            // Two passes let scoring enqueue optional explanations after structured extraction.
            for ($pass = 0; $pass < 2; $pass++) {
                if (config('queue.default') !== 'sync') {
                    $this->call('queue:work', ['connection' => config('queue.default'), '--queue' => 'extraction,scoring,intake,default',
                        '--stop-when-empty' => true, '--max-time' => 60, '--sleep' => 1, '--tries' => 3]);
                }
                $this->call('queue:work', ['connection' => 'ai', '--queue' => 'ai', '--stop-when-empty' => true,
                    '--max-time' => (int) config('ai-pipeline.timeout') * 12, '--sleep' => 1, '--tries' => 3]);
            }
        }
        $snapshot = $summary->snapshot();
        $this->table([__('brain.metric'), __('brain.count')], collect($snapshot)->except('failures')
            ->map(fn ($count, $metric): array => [__('brain.'.$metric), $count])->values()->all());
        foreach ($snapshot['failures'] as $code => $count) {
            $this->warn($code.': '.$count);
        }
        $this->info(__($this->option('rescore') ? 'brain.rescore_help' : 'brain.summary_help'));

        return self::SUCCESS;
    }
}
