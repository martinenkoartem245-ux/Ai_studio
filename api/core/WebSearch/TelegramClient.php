<?php
declare(strict_types=1);

class TelegramClient {
    private string $botToken;
    private const MAX_MESSAGE_LENGTH = 4096;

    public function __construct(?string $botToken = null) {
        $this->botToken = $botToken ?? (defined('TELEGRAM_BOT_TOKEN') ? (string)TELEGRAM_BOT_TOKEN : (getenv('TELEGRAM_BOT_TOKEN') ?: ''));
        
        if ($this->botToken === '') {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN не задан ни в constants.php, ни в getenv()');
        }
    }

    /**
     * Отправка сообщения в указанный чат
     */
    public function sendMessage(string|int $chatId, string $text, string $parseMode = 'HTML'): array {
        $chatIdStr = trim((string)$chatId);
        if ($chatIdStr === '') {
            throw new \RuntimeException('Chat ID не задан для отправки сообщения в Telegram');
        }

        $text = trim($text);
        if ($text === '') {
            return ['error' => 'Текст сообщения пуст'];
        }

        // Защита от лимита длины Telegram (4096 символов)
        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            $suffix = "\n...[сообщение обрезано Telegram]";
            $text = mb_substr($text, 0, self::MAX_MESSAGE_LENGTH - mb_strlen($suffix)) . $suffix;
        }

        $endpoint = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        // Попытка №1: Отправка с указанным parse_mode (HTML)
        try {
            $resp = HttpClient::postJson($endpoint, [
                'chat_id'    => $chatIdStr,
                'text'       => $text,
                'parse_mode' => $parseMode,
            ], [], 20);

            if (!empty($resp['ok'])) {
                return [
                    'success'    => true,
                    'chat_id'    => $chatIdStr,
                    'message_id' => $resp['result']['message_id'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            // Если ошибка связана с парсингом HTML-тегов, пробуем отправить чистым текстом
            if (str_contains($e->getMessage(), 'can\'t parse entities') || str_contains($e->getMessage(), 'HTTP 400')) {
                try {
                    $resp = HttpClient::postJson($endpoint, [
                        'chat_id' => $chatIdStr,
                        'text'    => strip_tags($text), // очищаем теги
                    ], [], 20);

                    if (!empty($resp['ok'])) {
                        return [
                            'success'    => true,
                            'chat_id'    => $chatIdStr,
                            'message_id' => $resp['result']['message_id'] ?? null,
                            'note'       => 'Отправлено без форматирования из-за ошибки в HTML тегах'
                        ];
                    }
                } catch (\Throwable $fallbackEx) {
                    throw new \RuntimeException('Ошибка отправки в Telegram (fallback): ' . $fallbackEx->getMessage());
                }
            }

            throw $e;
        }

        return ['error' => 'Не удалось отправить сообщение в Telegram'];
    }
}
