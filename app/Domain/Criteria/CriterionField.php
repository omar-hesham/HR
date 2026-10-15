<?php

namespace App\Domain\Criteria;

use App\Support\HasTranslatedLabel;

/**
 * Closed registry of fields a criterion may test (D-50). Protected attributes and
 * location (governorate/city/address) are deliberately absent (kickoff §5.3–5.4).
 */
enum CriterionField: string
{
    use HasTranslatedLabel;

    case EducationLevel = 'education_level';
    case EducationField = 'education_field';
    case ExperienceTotalYears = 'experience_total_years';
    case ExperienceRelevantYears = 'experience_relevant_years';
    case PreviousRoleTitles = 'previous_role_titles';
    case Skills = 'skills';
    case Certifications = 'certifications';
    case Languages = 'languages';
    case ShiftAvailability = 'shift_availability';
    // Job-related replacement for location (Labour Law 14/2025): answered by the
    // candidate or the recruiter, never inferred from an address.
    case CanReachPlantForShift = 'can_reach_plant_for_shift';
    case ExpectedSalary = 'expected_salary';
    // Allowed only with a documented legal basis (spec §7.1).
    case MilitaryStatus = 'military_status';
    // Yes/no legal minimum-age check only; never a number (kickoff §5.4 exception).
    case LegalMinimumAgeConfirmed = 'legal_minimum_age_confirmed';

    public const MILITARY_VALUES = ['completed', 'exempted', 'postponed', 'not_applicable'];

    public static function translationGroup(): string
    {
        return 'enums.criterion_field';
    }

    /**
     * @return list<CriterionOperator>
     */
    public function operators(): array
    {
        return match ($this) {
            self::EducationLevel => [CriterionOperator::Gte, CriterionOperator::In, CriterionOperator::Equals],
            self::EducationField, self::PreviousRoleTitles => [CriterionOperator::ContainsAny],
            self::ExperienceTotalYears, self::ExperienceRelevantYears => [CriterionOperator::Gte, CriterionOperator::Lte, CriterionOperator::Between],
            self::Skills, self::Certifications, self::Languages => [CriterionOperator::ContainsAny, CriterionOperator::ContainsAll],
            self::ShiftAvailability, self::CanReachPlantForShift, self::LegalMinimumAgeConfirmed => [CriterionOperator::IsExplicitlyTrue],
            self::ExpectedSalary => [CriterionOperator::Lte],
            self::MilitaryStatus => [CriterionOperator::Equals, CriterionOperator::In],
        };
    }

    /**
     * 'level' | 'text' | 'number' | 'boolean' | 'military'
     */
    public function valueType(): string
    {
        return match ($this) {
            self::EducationLevel => 'level',
            self::EducationField, self::PreviousRoleTitles, self::Skills, self::Certifications, self::Languages => 'text',
            self::ExperienceTotalYears, self::ExperienceRelevantYears, self::ExpectedSalary => 'number',
            self::ShiftAvailability, self::CanReachPlantForShift, self::LegalMinimumAgeConfirmed => 'boolean',
            self::MilitaryStatus => 'military',
        };
    }

    public function requiresLegalBasis(): bool
    {
        return $this === self::MilitaryStatus || $this === self::LegalMinimumAgeConfirmed;
    }

    /**
     * Value comes from a human answer (candidate or recruiter), not from CV extraction.
     */
    public function isHumanAnswered(): bool
    {
        return $this === self::CanReachPlantForShift;
    }
}
