<?php

declare(strict_types=1);

class Response {
    public static function json(array $data, int $statusCode = 200): void {
        if (ob_get_length()) {
            @ob_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($statusCode);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $json = json_encode([
                'error' => true,
                'message' => 'JSON Encoding Error: ' . json_last_error_msg()
            ], JSON_UNESCAPED_UNICODE);
            http_response_code(500);
        }

        echo $json;
        exit;
    }

    /**
     * Строгий формат ошибки согласно контракту: { "error": true, "message": "..." }
     */
    public static function error(string $message, int $statusCode = 400, array $extra = []): void {
        self::json(array_merge([
            'error' => true,
            'message' => $message
        ], $extra), $statusCode);
    }
}