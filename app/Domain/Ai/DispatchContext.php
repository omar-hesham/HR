<?php

namespace App\Domain\Ai;

final readonly class DispatchContext
{
    public function __construct(public ?int $documentId = null, public ?int $applicationId = null, public ?int $intakeId = null, public ?int $scoreId = null, public ?int $candidateId = null, public ?int $workItemId = null) {}
}
