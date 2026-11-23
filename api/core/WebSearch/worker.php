<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

// ==================== НАСТРОЙКИ СЛУШАТЕЛЯ И УВЕДОМЛЕНИЙ TELEGRAM ====================
define('TG_BOT_TOKEN', '8934351206:AAHOyPrlYpJQ5-LDPhpjPvlQWAewRKzzc1Q');
define('TG_ALLOWED_USER_ID', 7169445843); // Твой ID (число)
define('TG_TRIGGER_COMMAND', '.');       // Команда для запуска start.sh
// ====================================================================================

// 1. Автоматическое подтягивание конфигурации с API-ключами
$possibleConstantPaths = [
    __DIR__ . '/../../config/constants.php',
    __DIR__ . '/../../../config/constants.php',
    dirname(__DIR__, 3) . '/config/constants.php',
    dirname(__DIR__, 2) . '/config/constants.php',
];

foreach ($possibleConstantPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

// 2. Подключаем базовые сервисы и логирование
require_once __DIR__ . '/../Autoloader.php';

foreach ($possibleLoggerPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

/**
 * Определение модели по уровню приоритета/важности
 */
function resolveModelByPriority(string $priority): string {
    return match ($priority) {
        'strategic' => 'claude-opus-5-5',   
        'important' => 'claude-sonnet-5-5', 
        'minor'     => 'claude-haiku-5-5',  
        'fast'      => 'gemini-3.5-flash-lite', 
        default     => 'claude-haiku-5-5',
    };
}

/**
 * Определение и тонкая настройка параметров работы моделей по приоритетам
 */
function resolveParamsByPriority(string $priority, string $sessionPath): array {
    $baseParams = [
        'session_path'    => $sessionPath,
        'use_web_search'   => true,
        'telegram_notify'  => true,
        'set_period'       => true,
        'time_in_chat'     => true,
    ];

    $priorityParams = match ($priority) {
        'strategic' => [
            'temperature'              => 0.7,
            'max_tokens'               => 32000,
            'enable_thinking'          => true,
            'enable_extended_thinking' => '1',
            'thinking_budget'          => 16000,
            'thinking_level'           => 'high',
            'enable_code_execution'    => true,
            'enable_google_search'     => true,
            'enable_url_context'       => true,
        ],
        'important' => [
            'temperature'              => 0.7,
            'max_tokens'               => 16000,
            'enable_thinking'          => true,
            'enable_extended_thinking' => '1',
            'thinking_budget'          => 4096,
            'thinking_level'           => 'medium',
            'enable_code_execution'    => true,
            'enable_google_search'     => true,
            'enable_url_context'       => true,
        ],
        'minor' => [
            'temperature'              => 0.3,
            'max_tokens'               => 12000,
            'enable_thinking'          => true,
            'enable_extended_thinking' => '1',
            'thinking_budget'          => 4096,
            'thinking_level'           => 'medium',
            'enable_code_execution'    => true,
            'enable_google_search'     => true,
            'enable_url_context'       => true,
        ],
        'fast' => [
            'temperature'              => 0.7,
            'max_tokens'               => 32000,
            'enable_thinking'          => true,
            'enable_extended_thinking' => '1',
            'thinking_budget'          => 16000,
            'thinking_level'           => 'high',
            'enable_code_execution'    => true,
            'enable_google_search'     => true,
            'enable_url_context'       => true,
        ],
        default => [
            'temperature'              => 0.7,
            'max_tokens'               => 4096,
            'enable_thinking'          => false,
            'enable_extended_thinking' => '0',
            'thinking_budget'          => 0,
            'thinking_level'           => 'medium',
        ],
    };

    return array_merge($baseParams, $priorityParams);
}

/**
 * Проверяет Telegram на наличие команды "." и запускает start.sh
 */
function checkTelegramTriggerCommands(): void {
    $token     = defined('TG_BOT_TOKEN') ? TG_BOT_TOKEN : '';
    $allowedId = defined('TG_ALLOWED_USER_ID') ? (int)TG_ALLOWED_USER_ID : 0;
    $trigger   = defined('TG_TRIGGER_COMMAND') ? TG_TRIGGER_COMMAND : '.';

    if (empty($token) || $token === 'ТВОЙ_ТОКЕН_БОТА' || $allowedId === 0) {
        return;
    }

    $offsetFile = __DIR__ . '/tg_offset.txt';
    $offset = 0;
    if (file_exists($offsetFile)) {
        $offset = (int)trim((string)file_get_contents($offsetFile));
    }

    $url = "https://api.telegram.org/bot{$token}/getUpdates?offset={$offset}&timeout=0";
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        return;
    }

    $data = json_decode($response, true);
    if (empty($data['result']) || !is_array($data['result'])) {
        return;
    }

    foreach ($data['result'] as $update) {
        $nextOffset = $update['update_id'] + 1;
        file_put_contents($offsetFile, (string)$nextOffset);

        if (!isset($update['message'])) {
            continue;
        }

        $msg    = $update['message'];
        $fromId = $msg['from']['id'] ?? 0;
        $chatId = $msg['chat']['id'] ?? $fromId;
        $text   = trim($msg['text'] ?? '');

        // Проверяем, что сообщение пришло ТОЛЬКО от твоего ID
        if ($fromId !== $allowedId) {
            AppLogger::info('worker', "Игнорируем сообщение от стороннего Telegram ID: {$fromId}");
            continue;
        }

        // Если пришла команда "."
        if ($text === $trigger) {
            AppLogger::info('worker', "Получена команда '{$trigger}' от владельца ({$fromId}). Запускаем start.sh...");

            sendTgNotification($token, $chatId, "⚙️ <b>Команда принята!</b> Запускаю скрипт <code>start.sh</code>...");

            $homeDir = getenv('HOME') ?: '/data/data/com.termux/files/home';
            
            // Ищем start.sh первично в .termux/boot/start.sh
            $possiblePaths = [
                $homeDir . '/.termux/boot/start.sh',
                $homeDir . '/start.sh',
                __DIR__ . '/start.sh',
            ];

            $scriptToRun = null;
            foreach ($possiblePaths as $p) {
                if (file_exists($p)) {
                    $scriptToRun = $p;
                    break;
                }
            }

            if ($scriptToRun) {
                exec("bash " . escapeshellarg($scriptToRun) . " > /dev/null 2>&1 &");
                sendTgNotification($token, $chatId, "🚀 <b>Скрипт start.sh успешно запущен!</b>\nПуть: <code>{$scriptToRun}</code>");
            } else {
                sendTgNotification($token, $chatId, "❌ <b>Ошибка:</b> файл <code>start.sh</code> не найден по пути: <code>{$homeDir}/.termux/boot/start.sh</code>");
            }
        }
    }
}

/**
 * Отправка сообщений в Telegram
 */
function sendTgNotification(string $token, int|string $chatId, string $text): void {
    if (empty($token) || $token === 'ТВОЙ_ТОКЕН_БОТА' || empty($chatId)) {
        return;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    $options = [
        'http' => [
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 5,
        ],
    ];
    @file_get_contents($url, false, stream_context_create($options));
}

// Инициализация логгера
AppLogger::init();
AppLogger::info('worker', 'Запущен фоновый процесс отложенных задач и слушатель Telegram');

// Основной бесконечный цикл
while (true) {
    try {
        // 1. Проверяем Telegram на входящие команды "."
        checkTelegramTriggerCommands();

        // 2. Проверяем и выполняем отложенные задачи ИИ
        $dueTasks = TaskStorageService::getDueTasks();

        foreach ($dueTasks as $task) {
            $taskId      = $task['id'];
            $sessionPath = $task['session_path'];
            $prompt      = $task['prompt'];
            $priority    = $task['priority'] ?? 'fast';

            $model  = resolveModelByPriority($priority);
            $params = resolveParamsByPriority($priority, $sessionPath);

            AppLogger::info('worker', "Запуск выполнения задачи {$taskId}", [
                'task_id'      => $taskId,
                'priority'     => $priority,
                'model'        => $model,
                'session_path' => $sessionPath,
                'params'       => $params,
            ]);

            TaskStorageService::updateTaskStatus($taskId, 'processing');

            $historyFile = rtrim($sessionPath, '/\\') . DIRECTORY_SEPARATOR . 'chat_history.json';
            if (!file_exists($historyFile)) {
                $altHistory = rtrim($sessionPath, '/\\') . DIRECTORY_SEPARATOR . 'history.json';
                if (file_exists($altHistory)) {
                    $historyFile = $altHistory;
                }
            }

            $history = JsonStorage::read($historyFile, []);

            $history[] = [
                'role'      => 'user',
                'content'   => "[ОТЛОЖЕННЫЙ ЗАПУСК ЗАДАЧИ]: {$prompt}",
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            $systemContext = 'Ты полезный ИИ-помощник.';
            $metaFile = rtrim($sessionPath, '/\\') . DIRECTORY_SEPARATOR . 'meta.json';
            $meta = JsonStorage::read($metaFile, []);
            if (!empty($meta['system_context'])) {
                $systemContext = $meta['system_context'];
            }

            $aiService = new AiService();
            $fullResponseText = '';
            $fullThinkingText = '';

            try {
                $aiService->streamModelResponse(
                    $model,
                    $systemContext,
                    $history,
                    $params,
                    function (string $type, mixed $chunk) use (&$fullResponseText, &$fullThinkingText) {
                        if ($type === 'text' && is_string($chunk)) {
                            $fullResponseText .= $chunk;
                        }
                        if ($type === 'thinking' && is_string($chunk)) {
                            $fullThinkingText .= $chunk;
                        }
                    },
                    $sessionPath
                );

                if (trim($fullResponseText) === '') {
                    $fullResponseText = '[Задача успешно выполнена ИИ без текста сообщения]';
                }

                $assistantMsg = [
                    'role'      => 'assistant',
                    'content'   => $fullResponseText,
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
                if ($fullThinkingText !== '') {
                    $assistantMsg['reasoning_content'] = $fullThinkingText;
                }

                $history[] = $assistantMsg;
                JsonStorage::write($historyFile, $history);

                TaskStorageService::updateTaskStatus($taskId, 'completed');

                AppLogger::info('worker', "Задача {$taskId} успешно выполнена и сохранена в историю", [
                    'task_id' => $taskId,
                ]);

            } catch (\Throwable $e) {
                $errorMsg = "[$model] " . $e->getMessage();
                TaskStorageService::updateTaskStatus($taskId, 'failed', $errorMsg);

                AppLogger::error('worker', "Ошибка при исполнении задачи {$taskId}", [
                    'task_id' => $taskId,
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);

                // Отправка уведомления об ошибке задачи в Telegram
                $token     = defined('TG_BOT_TOKEN') ? TG_BOT_TOKEN : '';
                $allowedId = defined('TG_ALLOWED_USER_ID') ? (int)TG_ALLOWED_USER_ID : 0;

                if (!empty($token) && $token !== 'ТВОЙ_ТОКЕН_БОТА' && $allowedId !== 0) {
                    $shortPrompt = mb_strimwidth($prompt, 0, 150, '...');
                    $cleanError  = htmlspecialchars($e->getMessage(), ENT_QUOTES);
                    $cleanPrompt = htmlspecialchars($shortPrompt, ENT_QUOTES);

                    $failMessage = "⚠️ <b>Не удалось выполнить запланированную задачу!</b>\n\n"
                                 . "🆔 <b>ID задачи:</b> <code>{$taskId}</code>\n"
                                 . "🤖 <b>Модель:</b> <code>{$model}</code>\n"
                                 . "📝 <b>Промпт:</b> <i>{$cleanPrompt}</i>\n\n"
                                 . "❌ <b>Причина ошибки:</b>\n<code>{$cleanError}</code>";

                    sendTgNotification($token, $allowedId, $failMessage);
                }
            }
        }
    } catch (\Throwable $e) {
        AppLogger::error('worker', "Критическая ошибка цикла воркера: {$e->getMessage()}", [
            'trace' => $e->getTraceAsString(),
        ]);
    }

    sleep(10);
}
