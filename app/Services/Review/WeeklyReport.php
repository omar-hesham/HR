<?php

namespace App\Services\Review;

use App\Domain\Access\Permission;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\DocumentSource;
use App\Models\IntakeMessage;
use App\Models\Score;
use App\Models\User;
use App\Services\Ai\CostMeter;
use App\Services\Operations\Backups;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class WeeklyReport
{
    public function allowed(User $user): bool
    {
        return $user->isUsable() && ($user->can('viewAny', Application::class) || $user->hasPermission(Permission::OpsHealthView));
    }

    /** @return array<string,mixed> */
    public function data(User $user, string $week): array
    {
        abort_unless($this->allowed($user), 403);
        [$start, $end] = $this->period($week);
        $global = $user->hasPermission(Permission::ApplicationsViewAll) || $user->hasPermission(Permission::OpsHealthView);
        $applications = $global ? Application::query()->where('status', '!=', 'deleted') : app(ReviewApplications::class)->visible($user);
        $ids = (clone $applications)->select('applications.id');
        $sources = DocumentSource::query()->when(! $global, fn (Builder $query) => $query->whereIn('application_id', $ids));
        $messages = IntakeMessage::query()->when(! $global, fn (Builder $query) => $query->whereIn('id', (clone $sources)->select('intake_message_id')));
        $units = DB::table('review_kpi_definitions')->pluck('unit', 'key')->all();
        $counts = [
            'intake' => (clone $messages)->whereBetween('received_at', [$start, $end])->count(),
            'files' => (clone $sources)->whereBetween('created_at', [$start, $end])->count(),
            'candidates' => Candidate::query()->whereNull('erased_at')->when(! $global, fn (Builder $query) => $query->whereIn('id', (clone $applications)->select('candidate_id')))
                ->whereBetween('received_at', [$start, $end])->count(),
            'applications' => (clone $applications)->whereBetween('received_at', [$start, $end])->count(),
            'duplicates' => (clone $sources)->where('disposition', 'duplicate_linked')->whereBetween('created_at', [$start, $end])->count(),
            'duplicate_resolutions' => DB::table('duplicate_resolutions')->when(! $global, fn ($query) => $query->where('user_id', $user->id))->whereBetween('created_at', [$start, $end])->count(),
            'reviewed' => $this->reviewed(clone $applications, $start, $end),
            'scored' => Score::query()->whereIn('application_id', $ids)->whereBetween('created_at', [$start, $end])->distinct()->count('application_id'),
            'decisions' => DB::table('status_events')->whereIn('application_id', $ids)->whereNotNull('user_id')
                ->whereIn('to_status', ['shortlisted', 'on_hold', 'rejected_by_human', 'interview', 'hired', 'not_hired', 'archived'])
                ->whereBetween('created_at', [$start, $end])->distinct()->count('application_id'),
        ];
        $visibility = $this->visibility($messages, $sources);
        $delays = array_column(array_filter($visibility, fn (array $row): bool => ! $row['pending']), 'seconds');
        sort($delays);
        $active = DB::table('activity_log')->where('causer_type', (new User)->getMorphClass())->whereNotNull('causer_id')->whereBetween('created_at', [$start, $end])
            ->when(! $global, fn ($query) => $query->where('causer_id', $user->id))->distinct()->count('causer_id');
        $pilot = DB::table('weekly_pilot_logs')->where('user_id', $user->id)->where('week_start', $start->copy()->timezone('Africa/Cairo')->toDateString())->value('minutes');

        return ['counts' => $counts, 'units' => $units, 'visibility' => $visibility, 'exceptions' => $this->exceptions($user),
            'worst' => $delays === [] ? null : max($delays), 'p95' => $delays === [] ? null : $delays[max(0, (int) ceil(count($delays) * .95) - 1)],
            'pending' => count(array_filter($visibility, fn (array $row): bool => $row['pending'])),
            'active_users' => $active, 'cloud' => app(CostMeter::class)->spentUnits(now()->format('Y-m')) / 10000,
            'cap' => app(SettingsRepository::class)->float('ai.monthly_cap_egp'), 'pilot' => $pilot, 'global' => $global,
            'backup' => app(Backups::class)->health(),
            'week_start' => $start->copy()->timezone('Africa/Cairo')->format('d/m/Y'), 'week_end' => $end->copy()->timezone('Africa/Cairo')->format('d/m/Y')];
    }

    /** @param Builder<Application> $applications */
    private function reviewed(Builder $applications, Carbon $start, Carbon $end): int
    {
        return $applications->where(function (Builder $query) use ($start, $end) {
            foreach (['notes', 'field_corrections', 'status_events'] as $table) {
                $events = DB::table($table)->whereNotNull('user_id')->whereBetween('created_at', [$start, $end]);
                if ($table === 'status_events') {
                    $events->whereNotNull('from_status');
                }
                $query->orWhereIn('applications.id', $events->select('application_id'));
            }
            $query->orWhereIn('applications.id', DB::table('overrides')->join('scores', 'scores.id', '=', 'overrides.score_id')
                ->whereBetween('overrides.created_at', [$start, $end])->select('scores.application_id'));
        })->count();
    }

    /** @param Builder<IntakeMessage> $messages
     * @param Builder<DocumentSource> $sources
     * @return list<array<string,mixed>> */
    private function visibility(Builder $messages, Builder $sources): array
    {
        $visibility = [];
        foreach ((clone $messages)->orderBy('id')->cursor() as $message) {
            $visibility[] = ['kind' => 'messages', 'id' => $message->id, 'received' => $message->received_at, 'visible' => $message->visible_at,
                'seconds' => (int) max(0, $message->received_at->diffInSeconds($message->visible_at ?? now())), 'pending' => $message->visible_at === null];
        }
        foreach ((clone $sources)->with(['application', 'intakeMessage'])->orderBy('id')->lazyById(100) as $source) {
            $received = $source->intakeMessage->received_at ?? $source->application->received_at ?? $source->created_at;
            $visibility[] = ['kind' => 'files', 'id' => $source->id, 'received' => $received, 'visible' => $source->created_at,
                'seconds' => (int) max(0, $received->diffInSeconds($source->created_at)), 'pending' => false];
        }
        usort($visibility, fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds'] ?: $a['id'] <=> $b['id']);

        return $visibility;
    }

    /** @return list<array<string,mixed>> */
    private function exceptions(User $user): array
    {
        $exceptions = [];
        foreach (app(ReviewApplications::class)->exceptions($user)->with(['job.users', 'sources'])->lazyById(100) as $application) {
            $opened = $application->sources->whereNull('review_resolved_at')->min('review_opened_at') ?? $application->received_at;
            $owners = $application->job?->users->pluck('id')->all() ?? [];
            if ($owners === []) {
                $owners = User::role('owner')->pluck('id')->all();
            }
            $exceptions[] = ['id' => $application->id, 'seconds' => (int) max(0, Carbon::parse($opened)->diffInSeconds(now())), 'owners' => $owners];
        }
        usort($exceptions, fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return $exceptions;
    }

    public function pilot(User $user, string $week, int $minutes): void
    {
        abort_unless($this->allowed($user), 403);
        abort_if($minutes < 0 || $minutes > 10080, 422);
        [$start] = $this->period($week);
        DB::table('weekly_pilot_logs')->updateOrInsert(['user_id' => $user->id, 'week_start' => $start->timezone('Africa/Cairo')->toDateString()],
            ['minutes' => $minutes, 'unit' => 'minutes', 'created_at' => now(), 'updated_at' => now()]);
        activity('review')->causedBy($user)->event('pilot.time_recorded')->log('pilot.time_recorded');
    }

    /** @return array{Carbon,Carbon} */
    private function period(string $week): array
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $week) === 1, 422);
        try {
            $start = Carbon::createFromFormat('!Y-m-d', $week, 'Africa/Cairo');
            abort_unless($start !== null && $start->format('Y-m-d') === $week, 422);
        } catch (\InvalidArgumentException $exception) {
            abort(422);
        }
        $start->startOfWeek();

        return [$start->copy()->utc(), $start->copy()->endOfWeek()->utc()];
    }
}
