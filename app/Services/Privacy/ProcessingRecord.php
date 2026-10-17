<?php

namespace App\Services\Privacy;

use App\Models\CriteriaVersion;
use App\Models\User;
use App\Services\Settings\SettingsRepository;

final class ProcessingRecord
{
    /** @return array<string,mixed> */
    public function export(User $actor): array
    {
        abort_unless(app(PrivacyAccess::class)->approver($actor), 403);
        $settings = app(SettingsRepository::class);
        $record = [];
        foreach (['purpose', 'categories', 'recipients', 'storage_location', 'controller', 'rights_channel', 'dpo'] as $field) {
            $record[$field] = $settings->get('processing.'.$field);
        }
        $record['criteria_versions'] = CriteriaVersion::query()->whereNotNull('approved_at')->get(['id', 'recruitment_job_id', 'version', 'approved_at'])->toArray();
        $record['retention'] = array_filter($settings->all(), fn (string $key): bool => str_starts_with($key, 'retention.'), ARRAY_FILTER_USE_KEY);
        $record['disclaimer'] = __('ops.not_certificate');
        activity('privacy')->causedBy($actor)->event('processing_record.exported')->withProperties(['purpose' => 'processing_record'])->log('processing_record.exported');

        return $record;
    }
}
