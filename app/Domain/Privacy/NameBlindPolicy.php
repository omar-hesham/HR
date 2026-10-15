<?php

namespace App\Domain\Privacy;

/**
 * Kickoff §5.6: name-blind review hides name, photo and contact details in ranked
 * lists when enabled for the viewing user or for the job. UI arrives in Milestone 4.
 */
final class NameBlindPolicy
{
    public static function applies(bool $userSetting, ?bool $jobSetting): bool
    {
        return $userSetting || ($jobSetting ?? false);
    }
}
