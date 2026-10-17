<?php

namespace App\Domain\Privacy;

enum DeletionReason: string
{
    case Retention = 'retention';
    case HardCap = 'hard_cap';
    case ErasureRequest = 'erasure_request';
    case HiredHandover = 'hired_handover';
    case RestoreLedger = 'restore_ledger';
}
