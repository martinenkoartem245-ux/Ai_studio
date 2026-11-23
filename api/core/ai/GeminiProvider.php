<?php

declare(strict_types=1);

class GeminiProvider extends AbstractProvider {
    private const HEADERS = ['Content-Type: application/json'];

    private function prepareContents(array $history): array {
        $contents = [];
        $lastRole = null;

        foreach ($history as $msg) {
            if (!is_array($msg)) continue;

            $rawRole = $msg['role'] ?? '';
            
            if ($rawRole === 'assistant' && !empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                $text = is_string($msg['content'] ?? null) ? trim($msg['content']) : '';
                $parts = [];
                if ($text !== '') $parts[] = ['text' => $text];
                foreach ($msg['tool_calls'] as $tc) {
                    $argsDecoded = json_decode((string)($tc['function']['arguments'] ?? '{}'), true);
                    $argsObject = (is_array($argsDecoded) && !empty($argsDecoded)) ? $argsDecoded : new \stdClass();

                    $part = ['functionCall' => [
                        'name' => (string)($tc['function']['name'] ?? ''),
                        'args' => $argsObject,
                    ]];
                    if (!empty($tc['thought_signature'])) {
                        $part['thoughtSignature'] = $tc['thought_signature'];
                    }
                    $parts[] = $part;
                }
                $contents[] = ['role' => 'model', 'parts' => $parts];
                $lastRole = 'model';
                continue;
            }

            if ($rawRole === 'tool') {
                $text = is_string($msg['content'] ?? null) ? trim($msg['content']) : '';
                $decoded = json_decode($text, true);
                $respPart = ['functionResponse' => [
                    'name'     => (string)($msg['name'] ?? ''),
                    'response' => ['result' => $decoded ?? $text],
                ]];

                $lastIdx = count($contents) - 1;
                if ($lastIdx >= 0 && isset($contents[$lastIdx]['parts'][0]['functionResponse'])) {
                    $contents[$lastIdx]['parts'][] = $respPart;
                } else {
                    $contents[] = ['role' => 'user', 'parts' => [$respPart]];
                }
                $lastRole = 'user';
                continue;
            }

            $role = $rawRole === 'user' ? 'user' : 'model';
            $text = is_string($msg['content'] ?? null) ? trim($msg['content']) : '';

            if ($text === '') continue;

            if ($lastRole === $role && !empty($contents)) {
                $lastIndex = count($contents) - 1;
                $contents[$lastIndex]['parts'][] = ['text' => $text];
            } else {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $text]]
                ];
                $lastRole = $role;
            }
        }

        if (!empty($contents) && end($contents)['role'] !== 'user') {
            array_pop($contents);
        }

        return $contents;
    }

    private function buildPayload(string $model, string $systemContext, array $history, array $params = []): array {
        $contents = $this->prepareContents($history);

        if (empty($contents)) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => 'Hello']]
            ];
        }

        $payload = ['contents' => $contents];

        if (!empty($systemContext)) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemContext]]
            ];
        }

        $generationConfig = [];

        if (isset($params['temperature'])) {
            $generationConfig['temperature'] = (float)$params['temperature'];
        }

        $enableThinking = !empty($params['enable_thinking'])
            || ($params['enable_thinking'] ?? '0') === '1'
            || ($params['enable_extended_thinking'] ?? '0') === '1'
            || (!empty($params['thinking_budget']) && (int)$params['thinking_budget'] > 0);

        if ($enableThinking) {
            $thinkingConfig = ['includeThoughts' => true];
            $budget = (int)($params['thinking_budget'] ?? 0);

            if ($budget > 0) {
                $thinkingConfig['thinkingBudget'] = $budget;
            } else {
                $level = strtoupper((string)($params['thinking_level'] ?? $params['reasoning_effort'] ?? 'MEDIUM'));
                if (!in_array($level, ['LOW', 'MEDIUM', 'HIGH', 'MINIMAL'], true)) {
                    $level = 'MEDIUM';
                }
                $thinkingConfig['thinkingLevel'] = $level;
            }

            $generationConfig['thinkingConfig'] = $thinkingConfig;
        }

        if (!empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        $tools = [];
        $toolsList = $params['_tools'] ?? $params['_web_tools'] ?? [];
        
        // Подключаем пользовательские функции
        if (!empty($toolsList) && empty($params['_tool_choice_none'])) {
            $tools[] = ToolSchemaAdapter::forGemini($toolsList);
        }

        // Подключаем нативные фичи Gemini
        if (!empty($params['enable_google_search']))  $tools[] = ['googleSearch'  => (object)[]];
        if (!empty($params['enable_code_execution'])) $tools[] = ['codeExecution' => (object)[]];
        if (!empty($params['enable_url_context']))    $tools[] = ['urlContext'    => (object)[]];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        if (!empty($params['_tool_choice_none'])) {
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => 'NONE']];
        }

        return $payload;
    }

    public function call(string $model, string $systemContext, array $history, array $params = [], int $timeout = 120): array {
        $apiKey = $this->getApiKey('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $payload = $this->buildPayload($model, $systemContext, $history, $params);
        
        $sessionPath = (string)($params['_session_path'] ?? '');
        InstructionLogger::logPayload($sessionPath, 'gemini', $payload);

        AppLogger::info('gemini_provider', 'Запрос к Gemini API (call)', [
            'model' => $model,
            'contents_count' => count($payload['contents'] ?? []),
            'has_tools' => !empty($payload['tools']),
        ]);

        $data = $this->requestJson($url, self::HEADERS, $payload, $timeout);

        $text = '';
        $reasoningContent = '';
        $parts = $data['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $part) {
            $partText = $part['text'] ?? '';
            $isThought = !empty($part['thought']);

            if ($isThought) {
                $reasoningContent .= is_string($part['thought']) ? $part['thought'] : $partText;
            } else {
                $text .= $partText;
            }
        }

        $usage = $data['usageMetadata'] ?? [];

        return [
            'text' => $text,
            'reasoning_content' => $reasoningContent,
            'prompt_tokens' => (int)($usage['promptTokenCount'] ?? 0),
            'completion_tokens' => (int)($usage['candidatesTokenCount'] ?? 0)
        ];
    }

    public function stream(string $model, string $systemContext, array $history, array $params = [], ?callable $callback = null): void {
        $apiKey = $this->getApiKey('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:streamGenerateContent?alt=sse&key={$apiKey}";

        $payload = $this->buildPayload($model, $systemContext, $history, $params);

        $sessionPath = (string)($params['_session_path'] ?? '');
        InstructionLogger::logPayload($sessionPath, 'gemini', $payload);

        AppLogger::info('gemini_provider', 'Отправка SSE-запроса в Gemini', [
            'model' => $model,
            'contents_blocks_count' => count($payload['contents'] ?? []),
            'has_tools' => !empty($payload['tools']),
            'tools_mode' => $payload['toolConfig']['functionCallingConfig']['mode'] ?? 'AUTO'
        ]);

        $pendingToolCalls = [];
        $usage = [];

        $this->requestStream($url, self::HEADERS, $payload, function(?array $json, string $rawLine) use ($callback, &$pendingToolCalls, &$usage) {
            if ($json === null) return;

            if (isset($json['error'])) {
                $errorMsg = json_encode($json['error'], JSON_UNESCAPED_UNICODE);
                AppLogger::error('gemini_provider', "ОШИБКА API Gemini в потоке: {$errorMsg}");
                if ($callback) $callback('error', "Gemini Error: " . ($json['error']['message'] ?? 'Unknown'));
                return;
            }

            if (isset($json['promptFeedback']['blockReason'])) {
                $reason = $json['promptFeedback']['blockReason'];
                AppLogger::warning('gemini_provider', "Запрос заблокирован Gemini: {$reason}");
                if ($callback) $callback('error', "Запрос заблокирован Gemini: {$reason}");
                return;
            }

            if (isset($json['usageMetadata'])) {
                $usage = $json['usageMetadata'];
            }

            $candidate = $json['candidates'][0] ?? null;
            if (!$candidate) return;

            $finishReason = $candidate['finishReason'] ?? null;
            if ($finishReason !== null && !in_array($finishReason, ['STOP', 'MAX_TOKENS'], true)) {
                AppLogger::warning('gemini_provider', "Преждевременное завершение генерации: {$finishReason}");
                if ($callback) $callback('error', "Генерация прервана по причине: {$finishReason}");
                return;
            }

            if (isset($candidate['content']['parts'])) {
                foreach ($candidate['content']['parts'] as $part) {
                    $partText = $part['text'] ?? '';
                    $isThought = !empty($part['thought']);

                    if ($isThought) {
                        $thoughtContent = is_string($part['thought']) ? $part['thought'] : $partText;
                        if ($thoughtContent !== '' && $callback) {
                            $callback('thinking', $thoughtContent);
                        }
                    } elseif (isset($part['functionCall'])) {
                        $fc = $part['functionCall'];
                        AppLogger::info('gemini_provider', 'Gemini вернула запрос на вызов функции (Tool Call)', [
                            'function_name' => $fc['name'] ?? '',
                            'args' => $fc['args'] ?? []
                        ]);

                        $call = [
                            'id'       => 'gemini_' . count($pendingToolCalls),
                            'type'     => 'function',
                            'function' => [
                                'name'      => (string)($fc['name'] ?? ''),
                                'arguments' => json_encode($fc['args'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                            ],
                        ];
                        if (!empty($part['thoughtSignature'])) {
                            $call['thought_signature'] = $part['thoughtSignature'];
                        }
                        $pendingToolCalls[] = $call;
                    } else {
                        if ($partText !== '' && $callback) {
                            $callback('text', $partText);
                        }
                    }
                }
            }
        });

        if (!empty($usage) && $callback) {
            $callback('usage', [
                'prompt_tokens'     => (int)($usage['promptTokenCount'] ?? 0),
                'completion_tokens' => (int)($usage['candidatesTokenCount'] ?? 0),
            ]);
        }

        if (!empty($pendingToolCalls) && $callback) {
            $callback('tool_calls', $pendingToolCalls);
        }
    }
}