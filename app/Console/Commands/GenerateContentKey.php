<?php

namespace App\Console\Commands;

use App\Infrastructure\Storage\ContentEncrypter;
use Illuminate\Console\Command;

/**
 * Writes a new FILE_ENCRYPTION_KEY into .env (like key:generate). Refuses to replace an
 * existing key: files encrypted with the old key would become unreadable.
 */
final class GenerateContentKey extends Command
{
    protected $signature = 'recruit:key {--show : Print the key instead of writing it}';

    protected $description = 'Generate the content encryption key (FILE_ENCRYPTION_KEY) for CV files and text';

    public function handle(): int
    {
        $key = ContentEncrypter::generateKey();

        if ($this->option('show')) {
            $this->line($key);

            return self::SUCCESS;
        }

        $envPath = $this->laravel->environmentFilePath();
        $contents = is_file($envPath) ? (string) file_get_contents($envPath) : '';

        if (preg_match('/^FILE_ENCRYPTION_KEY=.+$/m', $contents) === 1) {
            $this->error('FILE_ENCRYPTION_KEY is already set. Rotating it needs a re-encryption plan; nothing changed.');

            return self::FAILURE;
        }

        $contents = preg_match('/^FILE_ENCRYPTION_KEY=$/m', $contents) === 1
            ? (string) preg_replace('/^FILE_ENCRYPTION_KEY=$/m', 'FILE_ENCRYPTION_KEY='.$key, $contents)
            : rtrim($contents).PHP_EOL.'FILE_ENCRYPTION_KEY='.$key.PHP_EOL;

        file_put_contents($envPath, $contents);
        $this->info('FILE_ENCRYPTION_KEY written to .env. Back it up separately from the data (SEC-P0-02).');

        return self::SUCCESS;
    }
}
