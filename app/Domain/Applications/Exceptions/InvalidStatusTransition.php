<?php

namespace App\Domain\Applications\Exceptions;

use App\Domain\Applications\ApplicationStatus;
use DomainException;

final class InvalidStatusTransition extends DomainException
{
    public static function notAllowed(ApplicationStatus $from, ApplicationStatus $to): self
    {
        return new self("Transition {$from->value} -> {$to->value} is not allowed.");
    }

    public static function humanRequired(ApplicationStatus $from, ApplicationStatus $to): self
    {
        return new self("Transition {$from->value} -> {$to->value} requires a human actor.");
    }

    public static function reasonRequired(ApplicationStatus $from, ApplicationStatus $to): self
    {
        return new self("Transition {$from->value} -> {$to->value} requires a reason.");
    }
}
