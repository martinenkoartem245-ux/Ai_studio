<?php

declare(strict_types=1);

class SseResponse {
    /**
     * Инициализация SSE-соединения и отключение всех видов буферизации
     */
    public static function init(): void {
        if (headers_sent()) {
            return;
        }

        // 1. Специфичный сброс для Apache
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
            @apache_setenv('dont-vary', '1');
        }

        // 2. Отключение сжатия и буферизации PHP
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');

        // 3. Отключаем лимиты буферов
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        // 4. Обязательные заголовки для устойчивого SSE
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        // Запрет буферизации для Nginx (критично для стабильного стриминга и предотвращения таймаутов прокси)
        header('X-Accel-Buffering: no');

        @flush();
    }

    /**
     * Безопасная отправка SSE-события клиенту.
     * Если клиент отключился — тихо игнорирует вывод, НЕ ломая работу PHP-скрипта.
     */
    public static function send(string $event, mixed $data): void {
        // Если клиент отменил запрос / закрыл вкладку — не пишем в мертвый сокет!
        if (connection_aborted()) {
            return;
        }

        $payloadStr = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);

        echo "event: {$event}\n";
        echo "data: {$payloadStr}\n\n";

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    /**
     * Keep-Alive комментарий для предотвращения обрыва соединения по таймауту Nginx/Браузера
     */
    public static function ping(): void {
        if (connection_aborted()) {
            return;
        }

        echo ": ping\n\n";

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    /**
     * Отправка ошибки в формате SSE по контракту
     */
    public static function error(string $message, int $code = 400): void {
        self::send('error', [
            'error'   => true,
            'message' => $message,
            'code'    => $code
        ]);
    }
}
