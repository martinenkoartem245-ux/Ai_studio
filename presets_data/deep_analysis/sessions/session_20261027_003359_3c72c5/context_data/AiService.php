<?php

class AiService {
    private array $providers = [];

    private function getProvider(string $model): object {
        $modelLower = strtolower($model);

        if (str_contains($modelLower, 'gemini')) {
            if (!isset($this->providers['gemini'])) {
                $this->providers['gemini'] = new GeminiProvider();
            }
            return $this->providers['gemini'];
        }

        if (str_contains($modelLower, 'claude')) {
            if (!isset($this->providers['claude'])) {
                $this->providers['claude'] = new ClaudeProvider();
            }
            return $this->providers['claude'];
        }

        if (!isset($this->providers['kimi'])) {
            $this->providers['kimi'] = new KimiProvider();
        }
        return $this->providers['kimi'];
    }

    private function prepareProviderParams(string $model, array $params): array {
        return $params;
    }

/**
 * Подготавливает историю сообщений для отправки в LLM:
 * - Если time_in_chat включен — подмешивает дату (timestamp) в текст сообщений.
 * - Очищает техническое поле timestamp перед отправкой в API провайдеров.
 */
private function prepareHistory(array $history, array $params): array 
{
    $includeTime = filter_var($params['time_in_chat'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $prepared = [];

    foreach ($history as $msg) {
        $role    = (string)($msg['role'] ?? 'user');
        $content = $msg['content'] ?? '';
        $ts      = $msg['timestamp'] ?? null;

        // Модификация контента при включенном флаге времени
        if ($includeTime && !empty($ts)) {
            // Если передали Unix-time (число) — превращаем в "15:45", иначе оставляем как есть
            $timeStr = is_numeric($ts) ? date('H:i', (int)$ts) : $ts;
            $prefix  = '[' . $timeStr . '] ';

            if (is_array($content)) {
                array_unshift($content, ['type' => 'text', 'text' => $prefix]);
            } else {
                // Сработает и для обычного текста, и для null, и для пустой строки
                $content = $prefix . (string)$content;
            }
        }


        $clean = [
            'role'    => $role,
            'content' => $content,
        ];

        // Копируем только разрешенные дополнительные поля, если они заданы и не null
        $allowedKeys = ['tool_calls', 'tool_call_id', 'name', 'reasoning_content'];
        foreach ($allowedKeys as $key) {
            if (isset($msg[$key])) {
                $clean[$key] = $msg[$key];
            }
        }

        $prepared[] = $clean;
    }

    return $prepared;
}




    public function callModel(string $model, string $systemContext, array $history, array $params = [], int $timeout = 120): array {
        $sessionPath = (string)($params['session_path'] ?? '');

        // Подмешиваем время Варшавы при активном флаге
       $history = $this->prepareHistory($history, $params);

        // --- ВХОД: Логируем входящие контекст, историю и параметры ---
        if (class_exists('InstructionLogger')) {
            InstructionLogger::log($sessionPath, $systemContext, $history, array_merge($params, [
                'model' => $model,
                'timeout' => $timeout,
                'method' => 'callModel'
            ]));
            InstructionLogger::logDebug($sessionPath, "ВХОД callModel | Модель: {$model} | Timeout: {$timeout}s");
        }

        $provider = $this->getProvider($model);
        $preparedParams = $this->prepareProviderParams($model, $params);

        try {
            $res = $provider->call($model, $systemContext, $history, $preparedParams, $timeout);
        } catch (\Throwable $e) {
            if (class_exists('InstructionLogger')) {
                InstructionLogger::logDebug($sessionPath, "ОШИБКА вызова модели {$model}: " . $e->getMessage());
            }
            throw $e;
        }

        if (!is_array($res)) {
            $res = [];
        }

        [$promptTokens, $completionTokens] = class_exists('CostCalculator')
            ? CostCalculator::extractTokens($res)
            : [(int)($res['prompt_tokens'] ?? 0), (int)($res['completion_tokens'] ?? 0)];

        $cost = class_exists('CostCalculator') 
            ? CostCalculator::calculate($model, $promptTokens, $completionTokens) 
            : 0;

        $response = [
            'text' => (string)($res['text'] ?? ''),
            'reasoning_content' => (string)($res['reasoning_content'] ?? ''),
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens
            ],
            'cost' => $cost
        ];

        // --- ВЫХОД: Логируем итоговый ответ модели и метаданные ---
        if (class_exists('InstructionLogger')) {
            InstructionLogger::logResponse($sessionPath, $response);
            InstructionLogger::logDebug(
                $sessionPath, 
                "ВЫХОД callModel | Модель: {$model} | Токены [prompt: {$promptTokens}, completion: {$completionTokens}] | Стоимость: {$cost}"
            );
        }

        return $response;
    }

    public function streamModelResponse(string $model, string $systemContext, array $history, array $params = [], ?callable $callback = null, string $sessionPath = ''): void {
        if (empty($sessionPath) && !empty($params['session_path'])) {
            $sessionPath = (string)$params['session_path'];
        }

        $safeCallback = $callback ?? function(string $type, mixed $chunk) {};

        // Подмешиваем время Варшавы при активном флаге
      $history = $this->prepareHistory($history, $params);

        // --- ВХОД: Логируем входящие данные стрима ---
        if (class_exists('InstructionLogger')) {
            InstructionLogger::log($sessionPath, $systemContext, $history, array_merge($params, [
                'model' => $model,
                'method' => 'streamModelResponse'
            ]));
            InstructionLogger::logDebug($sessionPath, "ВХОД streamModelResponse | Модель: {$model}");
        }

        $provider = $this->getProvider($model);
        $preparedParams = $this->prepareProviderParams($model, $params);

        try {
            // Получаем только те схемы инструментов, чьи флаги активированы в $preparedParams
            $toolRegistryClass = class_exists('ToolRegistry') ? 'ToolRegistry' : (class_exists('WebToolRegistry') ? 'WebToolRegistry' : null);
            $activeTools = $toolRegistryClass !== null ? $toolRegistryClass::schemas($preparedParams) : [];

            $hasActiveTools = !empty($activeTools);
            $hasLoopRunner = class_exists('ToolLoopRunner');

            // Проверяем, запросил ли клиент какие-либо тулы
            $requestedAnyTools = !empty($preparedParams['use_web_search']) 
                || !empty($preparedParams['telegram_notify']) 
                || !empty($preparedParams['set_period']);

            if ($requestedAnyTools && (!$hasActiveTools || !$hasLoopRunner)) {
                if (class_exists('InstructionLogger')) {
                    InstructionLogger::logDebug($sessionPath, 'Инструменты запрошены, но схемы пусты или ToolLoopRunner не найден (автозагрузка?)');
                }
            }

            if ($hasActiveTools && $hasLoopRunner) {
                // Передаем схемы (и в _tools, и в _web_tools для совместимости со всеми провайдерами)
                $preparedParams['_tools'] = $activeTools;
                $preparedParams['_web_tools'] = $activeTools;

                (new ToolLoopRunner())->stream($provider, $model, $systemContext, $history, $preparedParams, $safeCallback, $sessionPath);
            } else {
                $provider->stream($model, $systemContext, $history, $preparedParams, $safeCallback);
            }

            // --- ВЫХОД: Успешное завершение передачи потока ---
            if (class_exists('InstructionLogger')) {
                InstructionLogger::logDebug($sessionPath, "ВЫХОД streamModelResponse | Модель: {$model} | Стрим успешно завершён");
            }
        } catch (\Throwable $e) {
            if (class_exists('InstructionLogger')) {
                InstructionLogger::logDebug($sessionPath, "ОШИБКА стрима {$model}: " . $e->getMessage());
            }
            throw $e;
        }
    }
}