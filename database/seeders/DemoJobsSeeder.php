<?php

namespace Database\Seeders;

use App\Actions\Criteria\ApproveCriteriaVersion;
use App\Actions\Criteria\SaveCriterion;
use App\Actions\Criteria\StartDraftVersion;
use App\Domain\Access\Role;
use App\Models\RecruitmentJob;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * ILLUSTRATIVE demo job built from spec §7.5 — NOT the factory's criteria (Q02 open).
 * Local/testing only. Goes through the real actions so the example is validated too.
 */
class DemoJobsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $owner = User::query()->where('email', 'owner@example.test')->first();

        if ($owner === null) {
            return;
        }

        $job = RecruitmentJob::query()->firstOrCreate(['title_en' => 'DEMO - Electrical maintenance technician'], [
            'title_ar' => 'تجريبي - فني صيانة كهرباء',
            'aliases' => ['electrical technician', 'فني كهرباء'],
            'description_ar' => 'وظيفة توضيحية من المواصفة 7.5 وليست معايير المصنع.',
            'description_en' => 'Illustrative job from spec §7.5; not the factory\'s criteria.',
            'active' => true,
        ]);

        foreach (User::role([Role::HiringManager->value, Role::Recruiter->value, Role::Management->value])->get() as $user) {
            $job->users()->syncWithoutDetaching([$user->id]);
        }

        if ($job->current_criteria_version_id !== null) {
            return;
        }

        $draft = app(StartDraftVersion::class)->handle($job, $owner);
        $save = app(SaveCriterion::class);

        foreach ($this->criteria() as $sort => $criterion) {
            $save->handle($draft, [...$criterion, 'sort' => $sort], $owner);
        }

        app(ApproveCriteriaVersion::class)->handle($draft, $owner);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function criteria(): array
    {
        return [
            [
                'type' => 'must_have', 'field' => 'education_level', 'operator' => 'gte',
                'target' => ['value' => 'technical_diploma'], 'weight' => null,
                'label_ar' => 'دبلوم كهرباء أو أعلى', 'label_en' => 'Electrical diploma or higher',
                'justification' => 'Work on electrical panels requires formal electrical training.',
            ],
            [
                'type' => 'must_have', 'field' => 'experience_relevant_years', 'operator' => 'gte',
                'target' => ['value' => 2], 'weight' => null,
                'label_ar' => 'خبرة ذات صلة سنتان على الأقل', 'label_en' => 'Relevant experience of at least 2 years',
                'justification' => 'Maintenance is done on live production lines.',
            ],
            [
                'type' => 'must_have', 'field' => 'shift_availability', 'operator' => 'is_explicitly_true',
                'target' => [], 'weight' => null,
                'label_ar' => 'الاستعداد للعمل بنظام الورديات', 'label_en' => 'Willing to work shifts',
                'justification' => 'The plant runs rotating shifts.',
            ],
            [
                'type' => 'nice_to_have', 'field' => 'skills', 'operator' => 'contains_any',
                'target' => ['values' => ['PLC']], 'weight' => 40,
                'label_ar' => 'خبرة في PLC', 'label_en' => 'PLC experience',
                'justification' => 'Extrusion lines are PLC-controlled.',
            ],
            [
                'type' => 'nice_to_have', 'field' => 'skills', 'operator' => 'contains_any',
                'target' => ['values' => ['schematics', 'قراءة المخططات']], 'weight' => 25,
                'label_ar' => 'قراءة المخططات الكهربائية', 'label_en' => 'Reading schematics',
                'justification' => 'Fault finding relies on control-panel drawings.',
            ],
            [
                'type' => 'nice_to_have', 'field' => 'certifications', 'operator' => 'contains_any',
                'target' => ['values' => ['electrical safety', 'السلامة الكهربائية']], 'weight' => 20,
                'label_ar' => 'تدريب على السلامة الكهربائية', 'label_en' => 'Electrical safety training',
                'justification' => 'Reduces risk when working on energised equipment.',
            ],
            [
                'type' => 'nice_to_have', 'field' => 'skills', 'operator' => 'contains_any',
                'target' => ['values' => ['wire drawing', 'ماكينات السحب']], 'weight' => 15,
                'label_ar' => 'صيانة ماكينات سحب الأسلاك', 'label_en' => 'Wire-drawing machine maintenance',
                'justification' => 'The factory runs wire-drawing machines.',
            ],
        ];
    }
}
