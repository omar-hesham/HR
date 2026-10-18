<?php

namespace App\Console\Commands;

use App\Actions\Demo\ResetDemoData;
use App\Models\Application;
use App\Models\Candidate;
use Database\Seeders\DemoJobsSeeder;
use Database\Seeders\DemoMailboxSeeder;
use Database\Seeders\DemoReviewSeeder;
use Illuminate\Console\Command;

/**
 * Demo-machine helper: undoes rehearsal mutations without the migrate:fresh
 * wipe that would also destroy MFA enrolment. Local environment only.
 */
final class DemoReset extends Command
{
    protected $signature = 'recruit:demo-reset {--with-mail : Also run the fake mailbox once over tests/Fixtures/eml}';

    protected $description = 'Remove and re-seed synthetic demo review data; users, roles, permissions and settings stay untouched (local only)';

    public function handle(ResetDemoData $reset): int
    {
        if (! app()->environment('local')) {
            $this->error((string) __('app.demo_reset.refused', [], 'ar'));

            return self::FAILURE;
        }

        $reset->purge();
        $this->call('db:seed', ['--class' => DemoJobsSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DemoMailboxSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DemoReviewSeeder::class, '--force' => true]);

        $this->info((string) __('app.demo_reset.done', ['applications' => Application::query()->count(), 'candidates' => Candidate::query()->count()], 'ar'));

        if ($this->option('with-mail')) {
            $messages = $reset->runFixturesMailboxOnce();
            $this->info((string) ($messages >= 0
                ? __('app.demo_reset.mail', ['messages' => $messages], 'ar')
                : __('app.demo_reset.mail_failed', [], 'ar')));
        }

        $this->info((string) __('app.demo_reset.kept', [], 'ar'));

        return self::SUCCESS;
    }
}
