<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DatabaseBackupService
{
    /** Tables skipped from email backups to keep dumps compact and fast. */
    private const EXCLUDED_TABLES = [
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    public function __construct(private readonly EmailNotifier $emailNotifier) {}

    /**
     * Generate a complete SQL dump (schema + data) for all application tables.
     */
    public function generateSqlDump(): string
    {
        $storeName = (string) Setting::get('store_name', 'SellMeTools');
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();

        $out = [];
        $out[] = '-- ==========================================================================';
        $out[] = "-- {$storeName} Complete Database Backup";
        $out[] = '-- Generated at: '.now()->toDateTimeString().' ('.config('app.timezone', 'UTC').')';
        $out[] = '-- Site URL: '.config('app.url');
        $out[] = '-- ==========================================================================';
        $out[] = '';
        $out[] = 'SET NAMES utf8mb4;';
        $out[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $out[] = '';

        $tables = $this->listTables($driver);

        foreach ($tables as $table) {
            if (in_array($table, self::EXCLUDED_TABLES, true)) {
                continue;
            }

            $out[] = '-- --------------------------------------------------------------------------';
            $out[] = "-- Table structure for `{$table}`";
            $out[] = '-- --------------------------------------------------------------------------';
            $out[] = "DROP TABLE IF EXISTS `{$table}`;";

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $createRow = (array) ($connection->selectOne("SHOW CREATE TABLE `{$table}`") ?? []);
                $createSql = $createRow['Create Table'] ?? array_values($createRow)[1] ?? null;
                if ($createSql) {
                    $out[] = $createSql.';';
                }
            } elseif ($driver === 'sqlite') {
                $row = $connection->selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
                if ($row && ! empty($row->sql)) {
                    $out[] = $row->sql.';';
                }
            }

            $out[] = '';

            // Export table rows in chunks of 100
            $rows = $connection->table($table)->get();
            if ($rows->isEmpty()) {
                continue;
            }

            $out[] = "-- Dumping data for table `{$table}` ({$rows->count()} rows)";

            $firstRow = (array) $rows->first();
            $columns = array_map(fn ($col) => "`{$col}`", array_keys($firstRow));
            $columnList = implode(', ', $columns);

            foreach ($rows->chunk(100) as $chunk) {
                $valueTuples = [];
                foreach ($chunk as $row) {
                    $escapedValues = [];
                    foreach ((array) $row as $val) {
                        if ($val === null) {
                            $escapedValues[] = 'NULL';
                        } elseif (is_bool($val)) {
                            $escapedValues[] = $val ? '1' : '0';
                        } elseif (is_int($val) || is_float($val)) {
                            $escapedValues[] = (string) $val;
                        } else {
                            $escapedValues[] = $pdo->quote((string) $val);
                        }
                    }
                    $valueTuples[] = '('.implode(', ', $escapedValues).')';
                }

                $out[] = "INSERT INTO `{$table}` ({$columnList}) VALUES\n".implode(",\n", $valueTuples).';';
            }

            $out[] = '';
        }

        $out[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $out[] = '-- End of Backup';

        return implode("\n", $out);
    }

    /**
     * Generate SQL backup and email it as an attachment to the configured backup email.
     *
     * @param  array<string, mixed>  $smtpOverrides
     * @return array{success: bool, message: string}
     */
    public function sendBackupEmail(?string $recipientOverride = null, array $smtpOverrides = []): array
    {
        $recipient = trim((string) ($recipientOverride ?: Setting::get('backup_email') ?: Setting::get('support_email') ?: Setting::get('mail_username')));

        if ($recipient === '' || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Please enter a valid Backup Destination Email address first.',
            ];
        }

        if (! $this->emailNotifier->configure($smtpOverrides)) {
            return [
                'success' => false,
                'message' => 'SMTP is not configured. Please fill in your SMTP Host, Username, and Password in the Email tab first.',
            ];
        }

        try {
            $sql = $this->generateSqlDump();
            $storeName = (string) Setting::get('store_name', 'SellMeTools');
            $dateStamp = now()->format('Y-m-d_His');
            $rawSizeKb = round(strlen($sql) / 1024, 1);

            // Compress with gzip if larger than 4 MB so email providers accept it effortlessly
            if (strlen($sql) > 4 * 1024 * 1024 && function_exists('gzencode')) {
                $attachmentBytes = gzencode($sql, 9);
                $filename = "sellmetools-backup-{$dateStamp}.sql.gz";
                $mime = 'application/gzip';
            } else {
                $attachmentBytes = $sql;
                $filename = "sellmetools-backup-{$dateStamp}.sql";
                $mime = 'application/sql';
            }

            $freq = ucfirst((string) Setting::get('backup_frequency', 'weekly'));
            $statsSummary = $this->collectQuickCounts();

            $body = "Assalam o Alaikum!\n\n"
                ."Attached is your automated {$freq} Database Backup for {$storeName} (".config('app.url').").\n\n"
                ."📊 Backup Summary:\n"
                ."- File Name: {$filename}\n"
                ."- Uncompressed SQL Size: {$rawSizeKb} KB\n"
                ."- Products: {$statsSummary['products']}\n"
                ."- Orders: {$statsSummary['orders']}\n"
                ."- Customers: {$statsSummary['users']}\n"
                .'- Generated At: '.now()->toDayDateTimeString()."\n\n"
                ."How to restore: You can import this file directly into phpMyAdmin -> Import.\n"
                ."— {$storeName} Automated Backup System";

            Mail::mailer('store_smtp')->raw($body, function ($message) use ($recipient, $storeName, $dateStamp, $attachmentBytes, $filename, $mime) {
                $message->to($recipient)
                    ->subject("📦 [{$storeName}] Database Backup ({$dateStamp})")
                    ->attachData($attachmentBytes, $filename, ['mime' => $mime]);
            });

            Setting::set('backup_last_sent_at', now()->toIso8601String());

            return [
                'success' => true,
                'message' => "Database backup ({$filename}, {$rawSizeKb} KB) emailed to {$recipient}!",
            ];
        } catch (Throwable $e) {
            Log::warning('Automated database backup email failed: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Backup email failed: '.$this->emailNotifier->explainError($e->getMessage()),
            ];
        }
    }

    /**
     * Checks if a scheduled backup (daily/weekly/monthly) is due and runs it after the response finishes.
     */
    public function checkAndRunScheduledBackup(): void
    {
        try {
            // Check at most once every 3 hours via cache lock
            if (! Cache::add('backup:schedule-check-lock', 1, 10800)) {
                return;
            }

            if ((string) Setting::get('backup_enabled', '0') !== '1') {
                return;
            }

            $email = trim((string) Setting::get('backup_email', ''));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $frequency = strtolower((string) Setting::get('backup_frequency', 'weekly'));
            $requiredHours = match ($frequency) {
                'daily' => 24,
                'monthly' => 24 * 30,
                default => 24 * 7, // weekly
            };

            $lastSentRaw = (string) Setting::get('backup_last_sent_at', '');
            if ($lastSentRaw !== '') {
                try {
                    $lastSent = Carbon::parse($lastSentRaw);
                    if ($lastSent->diffInHours(now()) < $requiredHours) {
                        return;
                    }
                } catch (Throwable) {
                    // Proceed to send if timestamp is invalid
                }
            }

            if (app()->runningInConsole()) {
                $this->sendBackupEmail($email);
            } else {
                app()->terminating(function () use ($email): void {
                    $this->sendBackupEmail($email);
                });
            }
        } catch (Throwable $e) {
            Log::debug('Backup schedule check skipped: '.$e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function listTables(string $driver): array
    {
        $connection = DB::connection();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = $connection->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');

            return array_values(array_map(fn ($r) => (string) array_values((array) $r)[0], $rows));
        }

        if ($driver === 'sqlite') {
            $rows = $connection->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

            return array_values(array_map(fn ($r) => (string) $r->name, $rows));
        }

        return [];
    }

    /**
     * @return array{products: int, orders: int, users: int}
     */
    private function collectQuickCounts(): array
    {
        try {
            return [
                'products' => (int) DB::table('products')->count(),
                'orders' => (int) DB::table('orders')->count(),
                'users' => (int) DB::table('users')->count(),
            ];
        } catch (Throwable) {
            return ['products' => 0, 'orders' => 0, 'users' => 0];
        }
    }
}

