<?php

declare(strict_types=1);

class AppLogger
{
    private static string $logDir = '';

    public static function init(?string $customDir = null): void
    {
        $base = defined('STORAGE_PATH')
            ? STORAGE_PATH
            : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage');

        self::$logDir = rtrim($customDir ?? ($base . DIRECTORY_SEPARATOR . 'logs'), '\\/') . DIRECTORY_SEPARATOR;

        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0775, true);
        }
    }

    public static function log(string $channel, string $level, string $message, array $context = []): void
    {
        if (empty(self::$logDir)) {
            self::init();
        }

        $date = date('Y-m-d');
        $filePath = self::$logDir . "{$channel}-{$date}.log";

        $entry = [
            'timestamp' => date('Y-m-d H:i:s.v'),
            'level'     => strtoupper($level),
            'message'   => $message,
            'context'   => $context
        ];

        $jsonLine = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($filePath, $jsonLine . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'error', $message, $context);
    }

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'info', $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'warning', $message, $context);
    }
}