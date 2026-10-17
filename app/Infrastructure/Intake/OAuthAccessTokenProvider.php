<?php

namespace App\Infrastructure\Intake;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OAuth2 access tokens for XOAUTH2 (M1-P0-04): Microsoft 365 client-credentials
 * (`IMAP.AccessAsApp`, scope .../.default) or Google refresh-token exchange.
 * Intentionally narrow: reading only, no sending.
 */
final class OAuthAccessTokenProvider
{
    public function microsoft365(MailboxCredentialSet $credentials): string
    {
        $tenant = $credentials->tenantId();

        if ($tenant === null || $credentials->clientId() === null || $credentials->clientSecret() === null) {
            throw new RuntimeException('Microsoft 365 mailbox credentials are incomplete (tenant/client id/secret).');
        }

        if (preg_match('/^[a-zA-Z0-9.-]+$/D', $tenant) !== 1) {
            throw new RuntimeException('Invalid Microsoft 365 tenant identifier.');
        }

        $url = sprintf((string) config('recruitment.intake.m365_token_url'), $tenant);

        $response = Http::asForm()->timeout(15)->post($url, [
            'client_id' => $credentials->clientId(),
            'client_secret' => $credentials->clientSecret(),
            'scope' => 'https://outlook.office365.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Microsoft 365 token request failed with status '.$response->status().'.');
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Microsoft 365 token response contained no access token.');
        }

        return $token;
    }

    public function google(MailboxCredentialSet $credentials): string
    {
        if ($credentials->clientId() === null || $credentials->clientSecret() === null || $credentials->refreshToken() === null) {
            throw new RuntimeException('Google mailbox credentials are incomplete (client id/secret/refresh token).');
        }

        $response = Http::asForm()->timeout(15)->post((string) config('recruitment.intake.google_token_url'), [
            'client_id' => $credentials->clientId(),
            'client_secret' => $credentials->clientSecret(),
            'refresh_token' => $credentials->refreshToken(),
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Google token request failed with status '.$response->status().'.');
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Google token response contained no access token.');
        }

        return $token;
    }
}
