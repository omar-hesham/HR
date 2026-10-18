<?php

return [
    'singular' => 'Mailbox', 'title' => 'Mailboxes', 'connection' => 'Recruitment mailbox connection',
    'read_only' => 'The system only reads the approved recruitment mailbox. Clear rules identify applications and CV attachments; AI suggests a classification for ambiguous emails for human review. Messages are never moved, flagged or deleted on the source server.',
    'display_name' => 'Display name', 'address' => 'Recruitment email address', 'provider' => 'Provider',
    'providers' => ['imap' => 'IMAP', 'm365' => 'Microsoft 365', 'google' => 'Google Workspace', 'fake' => 'Synthetic local mailbox'],
    'host' => 'Host / synthetic fixture folder', 'host_help' => 'For the fake provider only, enter a local folder containing synthetic .eml files.',
    'port' => 'Port', 'encryption' => 'Encryption', 'folder' => 'Folder', 'ssl' => 'TLS (port 993)', 'tls' => 'STARTTLS (port 143)',
    'credentials' => 'Credentials', 'write_only' => 'Write-only encrypted credentials. Saved values are never shown back. Leave a field blank to keep it; changing provider requires new credentials.',
    'username' => 'Username (defaults to mailbox address)', 'password' => 'Password / app password',
    'tenant_id' => 'Microsoft tenant ID', 'client_id' => 'OAuth client ID', 'client_secret' => 'OAuth client secret', 'refresh_token' => 'Google refresh token',
    'polling' => 'Polling and alerts', 'start_now' => 'Start from now', 'start_at' => 'Start pointer', 'active' => 'Active',
    'silence' => 'Silence alert after (hours)', 'last_success' => 'Last successful poll',
    'test' => 'Test connection', 'poll' => 'Poll now', 'poll_queued' => 'Poll queued',
    'connection_ok' => 'connection_ok — connected; no message bodies fetched.',
    'connection_failed' => 'connection_failed — check the host, TLS, folder and credentials. No message bodies fetched.',
];
