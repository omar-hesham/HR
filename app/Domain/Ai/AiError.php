<?php

namespace App\Domain\Ai;

enum AiError: string
{
    case Timeout = 'Timeout';
    case AuthenticationFailed = 'AuthenticationFailed';
    case RateLimited = 'RateLimited';
    case BudgetExceeded = 'BudgetExceeded';
    case InvalidSchema = 'InvalidSchema';
    case LowConfidence = 'LowConfidence';
    case MaskingFailed = 'MaskingFailed';
    case ProviderUnavailable = 'ProviderUnavailable';

    public function retryable(): bool
    {
        return in_array($this, [self::Timeout, self::RateLimited, self::ProviderUnavailable], true);
    }

    public function providerFailure(): bool
    {
        return in_array($this, [self::Timeout, self::AuthenticationFailed, self::RateLimited, self::ProviderUnavailable], true);
    }
}
