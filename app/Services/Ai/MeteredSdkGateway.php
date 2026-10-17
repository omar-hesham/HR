<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiError;
use App\Domain\Ai\AiException;
use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;
use Laravel\Ai\Providers\Provider;
use Throwable;

/** SDK wire protocol retained; only the application owns retries/reservations. */
final class MeteredSdkGateway extends OpenAiCompatibleGateway
{
    protected function retryAfterSeconds(Throwable $exception): ?int
    {
        return null;
    }

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $url = (string) ($provider->additionalConfiguration()['url'] ?? '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        $private = $loopback || preg_match('/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.|f[cd][0-9a-f]{2}:)/i', $host) || str_ends_with($host, '.internal');
        if (($provider->name() === 'recruit_local' && (! $private || (! $loopback && $scheme !== 'https'))) || ($provider->name() === 'recruit_cloud' && $scheme !== 'https')) {
            throw new AiException(AiError::ProviderUnavailable);
        }

        return parent::client($provider, $timeout)->withoutRedirecting();
    }
}
