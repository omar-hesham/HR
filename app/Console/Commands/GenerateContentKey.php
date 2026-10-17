<?php

namespace App\Console\Commands;

use App\Infrastructure\Storage\ContentEncrypter;
use Illuminate\Console\Command;

/**
 * Writes missing FILE_ENCRYPTION_KEY and DEDUP_HMAC_KEY into .env (like
 * key:generate). Existing keys are never replaced: files encrypted with the old
 * key would become unreadable, and rotating the HMAC key would break sender
 * de-duplication.
 */
final class GenerateContentKey extends Command
{
    protected $signature = 'recruit:key {--show : Print the keys instead of writing them}';

    protected $description = 'Generate the content encryption key (FILE_ENCRYPTION_KEY) and the dedup HMAC key (DEDUP_HMAC_KEY)';

    public function handle(): int
    {
        $keys = [
            'FILE_ENCRYPTION_KEY' => ContentEncrypter::generateKey(),
            'DEDUP_HMAC_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        ];

        if ($this->option('show')) {
            foreach ($keys as $name => $key) {
                $this->line("{$name}={$key}");
            }

            return self::SUCCESS;
        }

        $envPath = $this->laravel->environmentFilePath();
        $contents = is_file($envPath) ? (string) file_get_contents($envPath) : '';
        $written = false;

        foreach ($keys as $name => $key) {
            if (preg_match('/^'.preg_quote($name, '/').'=..*$/m', $contents) === 1) {
                $this->components->twoColumnDetail($name, 'already set (kept)');

                continue;
            }

            $replacement = '^'.preg_quote($name, '/').'=$';
            $contents = preg_match('/'.$replacement.'/m', $contents) === 1
                ? (string) preg_replace('/'.$replacement.'/m', $name.'='.$key, $contents)
                : rtrim($contents).PHP_EOL.$name.'='.$key.PHP_EOL;

            $written = true;
            $this->components->twoColumnDetail($name, 'written');
        }

        if ($written) {
            file_put_contents($envPath, $contents);
        }

        $this->info('Back the keys up separately from the data (SEC-P0-02).');

        return self::SUCCESS;
    }
}
