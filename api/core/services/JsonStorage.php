<?php

declare(strict_types=1);

class JsonStorage {
    /**
     * Безопасное чтение и декодирование JSON файла
     */
    public static function read(string $filePath, array $default = []): array {
        if (!is_readable($filePath)) {
            return $default;
        }

        $content = @file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            return $default;
        }

        $decoded = json_decode($content, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : $default;
    }

    /**
     * Атомарная запись JSON на диск с блокировкой LOCK_EX
     */
    public static function write(string $filePath, array $data): bool {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tmpFile = $filePath . '.' . uniqid('tmp_', true);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        if (@file_put_contents($tmpFile, $json, LOCK_EX) === false) {
            return false;
        }

        // Фикс блокировок перезаписи в ОС Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && file_exists($filePath)) {
            @unlink($filePath);
        }

        $success = @rename($tmpFile, $filePath);

        if (!$success && file_exists($tmpFile)) {
            @unlink($tmpFile);
        }

        return $success;
    }

    /**
     * Безопасное удаление отдельного файла
     */
    public static function delete(string $filePath): bool {
        if (is_file($filePath) && is_writable($filePath)) {
            return @unlink($filePath);
        }
        return false;
    }

    /**
     * Рекурсивное удаление папки со всем содержимым
     */
    public static function deleteDir(string $dirPath): bool {
        $dirPath = rtrim($dirPath, '/\\');
        if (!file_exists($dirPath)) {
            return false;
        }

        if (is_link($dirPath) || !is_dir($dirPath)) {
            return @unlink($dirPath);
        }

        $items = scandir($dirPath);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dirPath . DIRECTORY_SEPARATOR . $item;
            if (is_link($full)) {
                @unlink($full);
            } elseif (is_dir($full)) {
                self::deleteDir($full);
            } else {
                @unlink($full);
            }
        }

        return @rmdir($dirPath);
    }
}