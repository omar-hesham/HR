<?php

namespace App\Filament\Resources\PrivacyRequests\Pages;

use App\Filament\Resources\PrivacyRequests\PrivacyRequestResource;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\Privacy\PrivacyAccess;
use App\Services\Privacy\PrivacyRequests;
use App\Services\Privacy\ProcessingRecord;
use App\Services\Review\ReviewApplications;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListPrivacyRequests extends ListRecords
{
    protected static string $resource = PrivacyRequestResource::class;

    private static function actor(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }

    private static function approver(): bool
    {
        return app(PrivacyAccess::class)->approver(self::actor());
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('register')->label(__('ops.register'))->schema([
            Select::make('candidate')->label(__('ops.subject'))->options(fn (): array => Candidate::query()->whereNull('erased_at')->whereIn('id', app(ReviewApplications::class)->visible(self::actor())->select('candidate_id'))->get()->mapWithKeys(fn (Candidate $c): array => [$c->id => $c->displayName()])->all())->required()->searchable(),
            Select::make('type')->label(__('ops.type'))->options(__('ops.types'))->required(), TextInput::make('channel')->label(__('ops.channel'))->helperText(__('ops.mailbox_responsibility'))->required()->maxLength(500), Textarea::make('details')->label(__('ops.details'))->required()->maxLength(2000),
        ])->action(fn (array $data) => app(PrivacyRequests::class)->register(self::actor(), Candidate::query()->findOrFail($data['candidate']), $data['type'], $data['channel'], $data['details'])),
            Action::make('processing')->label(__('ops.processing_record'))->visible(fn (): bool => self::approver())->action(fn () => response()->streamDownload(function (): void {
                echo json_encode(app(ProcessingRecord::class)->export(self::actor()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }, 'processing-record.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']))];
    }

    /** @return list<Action> */
    public static function requestActions(): array
    {
        return [
            Action::make('execute')->label(__('ops.execute'))->visible(fn (PrivacyRequest $record): bool => self::approver() && $record->status === 'approved' && $record->type !== 'rectification')->action(fn (PrivacyRequest $record) => app(PrivacyRequests::class)->execute(self::actor(), $record)),
            Action::make('verify')->label(__('ops.verify'))->visible(fn (PrivacyRequest $record): bool => self::approver() && $record->status === 'registered')->schema([Textarea::make('note')->label(__('ops.verification_note'))->required()->maxLength(1000)])->action(fn (PrivacyRequest $record, array $data) => app(PrivacyRequests::class)->verify(self::actor(), $record, $data['note'])),
            Action::make('decide')->label(__('ops.decide'))->visible(fn (PrivacyRequest $record): bool => self::approver() && $record->status === 'verified')->schema([Select::make('decision')->label(__('ops.decision'))->options(['approved' => __('ops.states.approved'), 'refused' => __('ops.states.refused')])->required(), Textarea::make('reason')->label(__('ops.reason'))->required()])->action(fn (PrivacyRequest $record, array $data) => app(PrivacyRequests::class)->decide(self::actor(), $record, $data['decision'], $data['reason'])),
            Action::make('rectify')->label(__('ops.rectify'))->visible(fn (PrivacyRequest $record): bool => self::approver() && $record->type === 'rectification' && $record->status === 'approved')->schema([
                TextInput::make('application')->label(__('ops.application'))->integer()->required(), TextInput::make('path')->label(__('ops.field_path'))->required(), Textarea::make('value')->label(__('ops.value'))->required(), Textarea::make('evidence')->label(__('ops.evidence'))->required(), Textarea::make('reason')->label(__('ops.reason'))->required(),
            ])->action(fn (PrivacyRequest $record, array $data) => app(PrivacyRequests::class)->rectify(self::actor(), $record, Application::query()->findOrFail($data['application']), $data)),
            Action::make('download')->label(__('ops.subject_export'))->visible(fn (PrivacyRequest $record): bool => self::approver() && $record->type === 'access' && $record->status === 'completed')->url(fn (PrivacyRequest $record): string => route('privacy-artifacts.download', ['artifact' => DB::table('privacy_artifacts')->where('privacy_request_id', $record->id)->value('id') ?? 'missing'])),
            Action::make('notify')->label(__('ops.record_notification'))->visible(fn (PrivacyRequest $record): bool => self::approver() && in_array($record->status, ['completed', 'refused'], true) && $record->getAttribute('notified_at') === null)->schema([Textarea::make('note')->label(__('ops.notification_note'))->helperText(__('ops.mailbox_responsibility'))->required()])->action(fn (PrivacyRequest $record, array $data) => app(PrivacyRequests::class)->notify(self::actor(), $record, $data['note'])),
        ];
    }
}
