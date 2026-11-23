<?php
declare(strict_types=1);

class ToolRegistry {
    public static function schemas(?array $params = null): array {
        if ($params === null) {
            return array_merge(
                self::getWebSchemas(),
                self::getTelegramSchemas(),
                self::getPeriodSchemas()
            );
        }

        $schemas = [];

        if (!empty($params['use_web_search'])) {
            $schemas = array_merge($schemas, self::getWebSchemas());
        }

        if (!empty($params['telegram_notify'])) {
            $schemas = array_merge($schemas, self::getTelegramSchemas());
        }

        if (!empty($params['set_period'])) {
            $schemas = array_merge($schemas, self::getPeriodSchemas());
        }

        return $schemas;
    }

    public static function getWebSchemas(): array {
        return [
            [
                'name'        => 'web_search',
                'description' => 'Поиск в интернете через Tavily. Используй для актуальной информации, новостей, фактов.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query'       => ['type' => 'string', 'description' => 'Поисковый запрос'],
                        'max_results' => ['type' => 'integer', 'description' => 'Кол-во результатов (1-10), по умолчанию 5'],
                    ],
                    'required'   => ['query'],
                ],
            ],
            [
                'name'        => 'web_fetch',
                'description' => 'Загрузить содержимое конкретной веб-страницы (URL) в виде markdown через Firecrawl.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'Полный URL страницы'],
                    ],
                    'required'   => ['url'],
                ],
            ],
        ];
    }

public static function getTelegramSchemas(): array {
        return [
            [
                'name'        => 'telegram_notify',
                'description' => 'Отправить сообщение в Telegram. Поддерживает базовый HTML (<b>, <i>, <code>).',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'text'    => [
                            'type'        => 'string', 
                            'description' => 'Текст сообщения для отправки'
                        ],
                        'chat_id' => [
                            'type'        => 'string',
                            'enum'        => ['1', '2'],
                            'description' => 'Получатель: "1" — Я (отправить сообщение мне), "2" — девушка (отправить сообщение девушке). По умолчанию "1"'
                        ],
                    ],
                    'required'   => ['text'],
                ],
            ],
        ];
    }


    /**
     * Схемы 3 инструментов управления отложенными задачами
     */
    public static function getPeriodSchemas(): array {
        return [
            [
                'name'        => 'set_period',
                'description' => 'Запланировать отложенный запуск задачи для ИИ.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'period'   => [
                            'type'        => 'string',
                            'description' => 'Время запуска: относительное ("15m", "2h", "1d") или дата ("2025-05-20 15:30", "tomorrow 09:00")',
                        ],
                        'prompt'   => [
                            'type'        => 'string',
                            'description' => 'Инструкция или задача для ИИ, которую необходимо выполнить при срабатывании',
                        ],
                        'priority' => [
                            'type'        => 'string',
                            'enum'        => ['strategic', 'important', 'minor', 'fast'],
                            'description' => 'Уровень важности: strategic (Claude 3.7 Thinking), important (Claude 3.5 Sonnet), minor (Haiku), fast (Gemini Flash Lite). По умолчанию: fast',
                        ],
                    ],
                    'required'   => ['period', 'prompt'],
                ],
            ],
            [
                'name'        => 'list_scheduled_tasks',
                'description' => 'Получить список всех отложенных задач текущей беседы/сессии.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [
                            'type'        => 'string',
                            'description' => 'Фильтр статуса: pending, completed, cancelled (необязательно)',
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'cancel_scheduled_task',
                'description' => 'Отменить ранее запланированную задачу по её ID.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id' => [
                            'type'        => 'string',
                            'description' => 'Идентификатор задачи (id)',
                        ],
                    ],
                    'required'   => ['task_id'],
                ],
            ],
        ];
    }

        public static function execute(string $name, array $args, string $sessionPath = ''): array {
        try {
            return match ($name) {
                'web_search' => (new TavilyClient())->search((string)($args['query'] ?? ''), (int)($args['max_results'] ?? 5)),
                'web_fetch'  => (new FirecrawlClient())->scrape((string)($args['url'] ?? '')),

                // Новые раздельные вызовы
                'send_message_to_me'         => self::sendTelegram('TELEGRAM_CHAT_ID_1', $args),
                'send_message_to_girlfriend' => self::sendTelegram('TELEGRAM_CHAT_ID_2', $args),

                // 1. Создание отложенной задачи
                'set_period' => self::executeSetPeriod($args, $sessionPath),

                // 2. Просмотр списка задач сессии
                'list_scheduled_tasks' => [
                    'status' => 'success',
                    'tasks'  => TaskStorageService::listTasks($sessionPath, $args['status'] ?? null)
                ],

                // 3. Отмена задачи по ID
                'cancel_scheduled_task' => [
                    'status'  => TaskStorageService::cancelTask($sessionPath, (string)($args['task_id'] ?? '')) ? 'success' : 'error',
                    'message' => 'Результат выполнения отмены задачи'
                ],

                default => ['error' => "Неизвестный инструмент: {$name}"],
            };
        } catch (\Throwable $e) {
            return ['error' => "Ошибка выполнения {$name}: " . $e->getMessage()];
        }
    }


    private static function executeSetPeriod(array $args, string $sessionPath): array {
        $periodStr = (string)($args['period'] ?? '');
        $prompt    = (string)($args['prompt'] ?? '');
        $priority  = (string)($args['priority'] ?? 'fast');

        // Авто-определение пресета беседы из параметров или папки сессии
        $preset = (string)($args['_preset'] ?? '');
        if ($preset === '' && $sessionPath !== '') {
            $presetFile = rtrim($sessionPath, '/\\') . DIRECTORY_SEPARATOR . 'preset.txt';
            if (is_file($presetFile)) {
                $preset = trim((string)@file_get_contents($presetFile));
            }
        }
        if ($preset === '') {
            $preset = 'default';
        }

        $executeAt = TimeParser::parse($periodStr);

        if ($executeAt === null) {
            return [
                'status'  => 'error',
                'message' => "Не удалось распознать время «{$periodStr}». Используйте форматы: '15m', '2h', 'tomorrow 09:00', '2025-05-20 15:30'."
            ];
        }

        $task = TaskStorageService::createTask($sessionPath, $preset, $prompt, $executeAt, $priority);

        return [
            'status'     => 'scheduled',
            'task_id'    => $task['id'],
            'execute_at' => date('Y-m-d H:i:s', $executeAt),
            'priority'   => $task['priority'],
            'message'    => "Задача запланирована на " . date('Y-m-d H:i:s', $executeAt),
        ];
    }

    private static function sendTelegram(string $chatIdConstantName, array $args): array {
        $chatId = defined($chatIdConstantName) ? (string)constant($chatIdConstantName) : (string)(getenv($chatIdConstantName) ?: '');
        if ($chatId === '') {
            throw new \RuntimeException("Константа {$chatIdConstantName} не задана");
        }
        return (new TelegramClient())->sendMessage($chatId, (string)($args['text'] ?? ''));
    }
}

class_alias('ToolRegistry', 'WebToolRegistry');
