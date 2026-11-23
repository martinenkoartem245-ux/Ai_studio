<?php

declare(strict_types=1);

class InstructionLogger {
    /**
     * Логирование ВХОДЯЩИХ данных (инструкции, контекст, история и параметры)
     */
    public static function log(string $sessionPath, string $systemContext, array $apiHistory, array $params): void {
        $logDir = self::resolveLogDir($sessionPath);
        $timestamp = date('Y-m-d H:i:s');
        
        $content = "==================================================\n";
        $content .= "БЛОК ИНСТРУКЦИЙ И КОНТЕКСТА [{$timestamp}]\n";
        $content .= "==================================================\n\n";

        $content .= "--- 1. ИТОГОВЫЙ СИСТЕМНЫЙ КОНТЕКСТ ---\n{$systemContext}\n\n";
        $content .= "--- 2. ПАРАМЕТРЫ ЗАПРОСА ---\n" . (json_encode($params, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}') . "\n\n";
        $content .= "--- 3. ИСТОРИЯ СООБЩЕНИЙ ДЛЯ API ---\n" . (json_encode($apiHistory, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]') . "\n";

        @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'last_instructions.txt', $content);
    }

    /**
     * Логирование ИСХОДЯЩИХ данных (результат работы модели)
     */
    public static function logResponse(string $sessionPath, array $response): void {
        $logDir = self::resolveLogDir($sessionPath);
        $timestamp = date('Y-m-d H:i:s');

        $content = "==================================================\n";
        $content .= "ОТВЕТ МОДЕЛИ НА ВЫХОДЕ [{$timestamp}]\n";
        $content .= "==================================================\n\n";

        $content .= "--- 1. ТЕКСТ ОТВЕТА ---\n" . ($response['text'] ?? '') . "\n\n";
        
        if (!empty($response['reasoning_content'])) {
            $content .= "--- 2. REASONING CONTENT ---\n" . $response['reasoning_content'] . "\n\n";
        }
        
        $content .= "--- 3. ИСПОЛЬЗОВАНИЕ ТОКЕНОВ И СТОИМОСТЬ ---\n";
        $content .= (json_encode([
            'usage' => $response['usage'] ?? [],
            'cost'  => $response['cost'] ?? 0
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}') . "\n";

        @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'last_response.txt', $content);
    }

    /**
     * Логирование работы инструментов (tools) в формате JSON Lines (одна строка — одна итерация)
     */
    public static function logTools(string $sessionPath, int $iteration, array $toolsLog): void {

        $logDir = self::resolveLogDir($sessionPath);
        $filePath = $logDir . DIRECTORY_SEPARATOR . 'tools_execution.log';

        $timestamp = date('Y-m-d H:i:s.v');
        $entry = [
            'timestamp' => $timestamp,
            'iteration' => $iteration,
            'tools'     => $toolsLog
        ];

        // ИСПРАВЛЕНИЕ: Пишем в одну строку без JSON_PRETTY_PRINT, чтобы не ломать построчные парсеры
        $jsonLine = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($filePath, $jsonLine . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Дамп ПОЛНОГО JSON-запроса к API провайдера
     */
    public static function logPayload(string $sessionPath, string $providerName, array $payload): void {
        $logDir = self::resolveLogDir($sessionPath);
        $filePath = $logDir . DIRECTORY_SEPARATOR . 'last_payload_' . strtolower($providerName) . '.json';
        
        @file_put_contents(
            $filePath, 
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );
    }

    /**
     * Общий файл отладочного лога
     */
    public static function logDebug(string $sessionPath, string $message): void {
        $logDir = self::resolveLogDir($sessionPath);
        $time = date('Y-m-d H:i:s');
        $file = $logDir . DIRECTORY_SEPARATOR . 'debug.log';
        @file_put_contents($file, "[{$time}] {$message}\n", FILE_APPEND | LOCK_EX);
    }

    private static function resolveLogDir(string $sessionPath): string {
        $logDir = !empty($sessionPath) 
            ? $sessionPath 
            : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs');
            
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        return $logDir;
    }
}
