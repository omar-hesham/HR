<?php

namespace App\Services\Review;

use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Domain\Privacy\NameBlindPolicy;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Models\Application;
use App\Models\RecruitmentJob;
use App\Models\ReviewExport;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExportApplications
{
    public function authorize(User $user, RecruitmentJob $job): void
    {
        abort_unless($user->isUsable() && $user->can('viewAny', Application::class) && JobScope::canWorkOnJob($user, $job->id)
            && ($user->hasPermission(Permission::ExportsAll)
                || ($user->hasPermission(Permission::ExportsAssigned) && $user->isAssignedToJob($job->id))), 403);
        if ($user->hasPermission(Permission::ExportsAll)) {
            $confirmed = (int) session()->get('auth.password_confirmed_at', 0);
            abort_if($confirmed < now()->subMinutes(15)->timestamp || $confirmed > now()->timestamp, 403, __('review.reauthenticate'));
        }
    }

    public function confirmPassword(User $user, string $password): void
    {
        $key = 'review-export-password:'.$user->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }
        RateLimiter::clear($key);
        session()->put('auth.password_confirmed_at', now()->timestamp);
        activity('access')->causedBy($user)->event('export.reauthenticated')->log('export.reauthenticated');
    }

    public function create(User $user, RecruitmentJob $job, string $format, string $purpose): ReviewExport
    {
        $this->authorize($user, $job);
        abort_unless(in_array($format, ['xlsx', 'csv'], true) && trim($purpose) !== '' && mb_strlen($purpose) <= 1000, 422);
        $blind = NameBlindPolicy::applies($user->name_blind_review, $job->name_blind_review);
        $applications = app(ReviewApplications::class)->visible($user)->where('recruitment_job_id', $job->id)
            ->with(['candidate', 'currentScore.criteriaVersion'])->orderBy('received_at')->orderBy('id')->get();
        $keys = [...($blind ? [] : ['name', 'phone', 'email']), 'status', 'score', 'band', 'reasons', 'coverage', 'version', 'received'];
        $rows = [array_map(fn (string $key): string => __('review.export_columns.'.$key), $keys)];
        foreach ($applications as $application) {
            $score = $application->currentScore;
            $values = ['name' => $application->candidate->displayName(), 'phone' => $application->candidate->phone ?? $application->candidate->phone_original ?? '',
                'email' => $application->candidate->email ?? '', 'status' => $application->status->label(), 'score' => $score->score ?? '',
                'band' => $score === null ? __('review.unscored') : (app(ReviewApplications::class)->isCurrent($application) ? __('review.bands.'.$score->band) : __('review.needs_recalculation')), 'reasons' => $score === null ? '' : $this->reasons($score),
                'coverage' => $score->coverage ?? '', 'version' => $score?->criteriaVersion->version ?? '',
                'received' => $application->received_at->timezone('Africa/Cairo')->format('d/m/Y H:i')];
            $rows[] = array_map(fn (string $key): string => (string) $values[$key], $keys);
        }
        $bytes = $format === 'csv' ? $this->csv($rows) : $this->xlsx($rows, $keys);
        $id = (string) Str::uuid();
        $path = $id.'.bin';
        $artifact = ReviewExport::query()->create(['id' => $id, 'user_id' => $user->id, 'recruitment_job_id' => $job->id,
            'format' => $format, 'path' => $path, 'identified' => ! $blind, 'application_ids' => $applications->modelKeys(),
            'purpose_enc' => $purpose, 'expires_at' => now()->addHours(24)]);
        Storage::disk('review_exports')->put($path, app(ContentEncrypter::class)->encryptString($bytes));
        activity('access')->causedBy($user)->performedOn($artifact)->event('export.created')
            ->withProperties(['job_id' => $job->id, 'rows' => $applications->count(), 'identified' => ! $blind, 'purpose' => 'weekly_screening_export'])->log('export.created');

        return $artifact;
    }

    public function download(User $user, ReviewExport $artifact): string
    {
        abort_unless($artifact->user_id === $user->id, 403);
        $job = RecruitmentJob::query()->findOrFail($artifact->recruitment_job_id);
        $this->authorize($user, $job);
        abort_if($artifact->purged_at !== null || $artifact->expires_at->lte(now()), 410);
        abort_if($artifact->identified && NameBlindPolicy::applies($user->name_blind_review, $job->name_blind_review), 403);
        $visible = JobScope::applyToApplications(Application::query(), $user)->whereIn('id', $artifact->application_ids)
            ->where('recruitment_job_id', $job->id)->where('status', '!=', 'deleted')->count();
        abort_unless($visible === count($artifact->application_ids), 403);
        abort_unless(Storage::disk('review_exports')->exists($artifact->path), 404);
        $bytes = app(ContentEncrypter::class)->decryptString(Storage::disk('review_exports')->get($artifact->path));
        activity('access')->causedBy($user)->performedOn($artifact)->event('export.downloaded')
            ->withProperties(['job_id' => $job->id, 'purpose' => 'weekly_screening_export', 'request_id' => (string) Str::uuid()])->log('export.downloaded');

        return $bytes;
    }

    public function purgeExpired(): int
    {
        $count = 0;
        ReviewExport::query()->whereNull('purged_at')->where('expires_at', '<=', now())->chunkById(100, function ($artifacts) use (&$count): void {
            foreach ($artifacts as $artifact) {
                Storage::disk('review_exports')->delete($artifact->path);
                $artifact->forceFill(['purged_at' => now()])->save();
                $count++;
            }
        });

        return $count;
    }

    private function reasons(Score $score): string
    {
        $reasons = [__('review.export_reason_summary', ['score' => $score->score ?? '—', 'coverage' => $score->coverage ?? '—', 'version' => $score->criteriaVersion->version], 'ar')];
        foreach ($score->criterionResults()->with('criterion')->orderBy('criterion_id')->get() as $criterion) {
            $reasons[] = __('review.export_reason', ['label' => $criterion->criterion->label_ar, 'outcome' => __('review.outcomes.'.$criterion->result, [], 'ar')], 'ar');
        }

        return implode("\n", $reasons);
    }

    public static function dangerous(string $value): bool
    {
        return preg_match('/^[\s\p{Z}\x{FEFF}\x{200B}]*[=+\-@]/u', $value) === 1;
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open memory stream.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $value): string => self::dangerous($value) ? "'".$value : $value, $row), ',', '"', '', "\r\n");
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    /** @param list<list<string>> $rows
     * @param list<string> $keys */
    private function xlsx(array $rows, array $keys): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setRightToLeft(true);
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $address = [$c + 1, $r + 1];
                $numeric = $r > 0 && in_array($keys[$c], ['score', 'coverage'], true) && is_numeric($value);
                $sheet->setCellValueExplicit($address, $numeric ? (float) $value : $value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                if (! $numeric && self::dangerous($value)) {
                    $sheet->getStyle($address)->setQuotePrefix(true);
                }
            }
        }
        $sheet->freezePane('A2');
        ob_start();
        try {
            (new Xlsx($book))->save('php://output');

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $book->disconnectWorksheets();
        }
    }
}
