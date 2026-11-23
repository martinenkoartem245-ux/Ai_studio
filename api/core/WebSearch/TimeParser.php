<?php
declare(strict_types=1);

class TimeParser {
    /**
     * Преобразует строку времени (относительную или абсолютную) в Unix Timestamp.
     */
    public static function parse(string $timeStr, string $timezone = 'Europe/Warsaw'): ?int {
        if (function_exists('date_default_timezone_set')) {
            date_default_timezone_set($timezone);
        }

        $timeStr = trim($timeStr);
        if ($timeStr === '') {
            return null;
        }

        // 1. Проверяем относительные паттерны: 30s, 15m, 2h, 1d, 2w
        if (preg_match('/^(\d+)\s*([s|m|h|d|w])$/i', $timeStr, $matches)) {
            $value = (int)$matches[1];
            $unit  = strtolower($matches[2]);

            $seconds = match ($unit) {
                's' => $value,
                'm' => $value * 60,
                'h' => $value * 3600,
                'd' => $value * 86400,
                'w' => $value * 604800,
                default => 0,
            };

            return time() + $seconds;
        }

        // 2. Универсальный strtotime (для "tomorrow 09:00", "2025-05-20 15:30" и т.д.)
        $timestamp = strtotime($timeStr);
        if ($timestamp !== false && $timestamp > time()) {
            return $timestamp;
        }

        return null;
    }
}
