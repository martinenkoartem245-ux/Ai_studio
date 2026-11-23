<?php
declare(strict_types=1);

class ToolLoopRunner {
    private int $maxIterations = 4;

    public function stream(
        object $provider, 
        string $model, 
        string $systemContext, 
        array $history, 
        array $params, 
        callable $userCallback, 
        string $sessionPath = ''
    ): void {
        if (empty($sessionPath) && !empty($params['session_path'])) {
            $sessionPath = (string)$params['session_path'];
        }

        if (class_exists('InstructionLogger')) {
            InstructionLogger::logDebug(
                $sessionPath, 
                "ВХОД ToolLoopRunner::stream | Модель: {$model} | Сообщений в истории: " . count($history)
            );
        }

        $workingHistory  = array_values($history);
        $totalPrompt     = 0;
        $totalCompletion = 0;

        $params['_session_path'] = $sessionPath;

        try {
            for ($iteration = 0; $iteration <= $this->maxIterations; $iteration++) {
                $iterParams = $params;
                
                if ($iteration === $this->maxIterations) {
                    $iterParams['_tool_choice_none'] = true;
                    if (class_exists('InstructionLogger')) {
                        InstructionLogger::logDebug($sessionPath, "ToolLoopRunner: Достигнут лимит итераций ({$this->maxIterations}). Отключаем функции.");
                    }
                }

                $toolCalls       = [];
                $iterPrompt      = 0;
                $iterCompletion  = 0;
                $iterThinking    = '';
                $iterText        = '';

                $provider->stream($model, $systemContext, $workingHistory, $iterParams,
                    function (string $type, mixed $chunk) use (
                        $userCallback, &$toolCalls, &$iterPrompt, &$iterCompletion, &$iterThinking, &$iterText
                    ) {
                        if ($type === 'tool_calls' && is_array($chunk)) {
                            $toolCalls = $chunk;
                            return;
                        }
                        if ($type === 'usage' && is_array($chunk)) {
                            $iterPrompt     = (int)($chunk['prompt_tokens'] ?? 0);
                            $iterCompletion = (int)($chunk['completion_tokens'] ?? 0);
                            return;
                        }
                        if ($type === 'thinking' && is_string($chunk)) $iterThinking .= $chunk;
                        if ($type === 'text' && is_string($chunk))     $iterText .= $chunk;

                        $userCallback($type, $chunk);
                    });

                $totalPrompt     += $iterPrompt;
                $totalCompletion += $iterCompletion;

                if (empty($toolCalls)) {
                    if (class_exists('InstructionLogger')) {
                        InstructionLogger::logDebug(
                            $sessionPath, 
                            "ВЫХОД ToolLoopRunner::stream | Цикл завершён на итерации {$iteration} | Токены [prompt: {$totalPrompt}, completion: {$totalCompletion}]"
                        );
                    }

                    if ($totalPrompt > 0 || $totalCompletion > 0) {
                        $userCallback('usage', [
                            'prompt_tokens'     => $totalPrompt,
                            'completion_tokens' => $totalCompletion,
                        ]);
                    }
                    return;
                }

                if (class_exists('InstructionLogger')) {
                    InstructionLogger::logDebug(
                        $sessionPath, 
                        "ToolLoopRunner [Итерация {$iteration}]: Вызов функций -> " . count($toolCalls)
                    );
                }

                $assistantMsg = ['role' => 'assistant', 'content' => $iterText, 'tool_calls' => $toolCalls];
                if ($iterThinking !== '') {
                    $assistantMsg['reasoning_content'] = $iterThinking;
                }
                $workingHistory[] = $assistantMsg;

                $executedToolsLog = [];

                foreach ($toolCalls as $call) {
                    $name = (string)($call['function']['name'] ?? '');
                    $argsRaw = $call['function']['arguments'] ?? '{}';
                    
                    if (is_array($argsRaw)) {
                        $args = $argsRaw;
                    } else {
                        $args = json_decode((string)$argsRaw, true);
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($args)) {
                            $args = [];
                            if (class_exists('InstructionLogger')) {
                                InstructionLogger::logDebug($sessionPath, "ОШИБКА парсинга JSON аргументов инструмента {$name}: " . json_last_error_msg());
                            }
                        }
                    }

                    $userCallback('tool_status', ['status' => 'running', 'tool' => $name, 'args' => $args]);

                    $t0 = microtime(true);
                    $errorOccurred = null;
                    $result = null;

                    try {
                        // Используем универсальный ToolRegistry
                        $result = ToolRegistry::execute($name, $args, $sessionPath);
                    } catch (\Throwable $e) {
                        $errorOccurred = $e->getMessage();
                        $result = ['error' => $errorOccurred];
                    }
                    $duration = round(microtime(true) - $t0, 4);

                    $userCallback('tool_status', ['status' => 'done', 'tool' => $name]);

                    $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

                    $executedToolsLog[] = [
                        'id'       => $call['id'] ?? null,
                        'name'     => $name,
                        'args'     => $args,
                        'result'   => $result,
                        'duration' => $duration,
                        'exception'=> $errorOccurred
                    ];

                    $workingHistory[] = [
                        'role'         => 'tool',
                        'tool_call_id' => (string)($call['id'] ?? ''),
                        'name'         => $name,
                        'content'      => $resultJson,
                    ];
                }

                if (!empty($sessionPath) && class_exists('InstructionLogger')) {
                    try {
                        InstructionLogger::logTools($sessionPath, $iteration, $executedToolsLog);
                    } catch (\Throwable $logEx) {
                        InstructionLogger::logDebug($sessionPath, "Сбой записи InstructionLogger::logTools: " . $logEx->getMessage());
                    }
                }
            }

            $userCallback('tool_status', ['status' => 'max_iterations_reached']);
            
            if (class_exists('InstructionLogger')) {
                InstructionLogger::logDebug(
                    $sessionPath, 
                    "ВЫХОД ToolLoopRunner::stream | Превышен лимит итераций ({$this->maxIterations}) | Токены [prompt: {$totalPrompt}, completion: {$totalCompletion}]"
                );
            }

            if ($totalPrompt > 0 || $totalCompletion > 0) {
                $userCallback('usage', ['prompt_tokens' => $totalPrompt, 'completion_tokens' => $totalCompletion]);
            }

        } catch (\Throwable $e) {
            if (class_exists('InstructionLogger')) {
                InstructionLogger::logDebug($sessionPath, "ОШИБКА ToolLoopRunner::stream: " . $e->getMessage());
            }
            throw $e;
        }
    }
}