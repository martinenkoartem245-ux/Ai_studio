<?php

declare(strict_types=1);

class ClaudeProvider extends AbstractProvider {
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private function getHeaders(): array {
        return [
            'x-api-key: ' . $this->getApiKey('CLAUDE_API_KEY'),
            'anthropic-version: 2023-06-01',
            'anthropic-beta: output-128k-2025-02-19',
            'Content-Type: application/json'
        ];
    }

    private function prepareMessages(array $history): array {
        $messages = [];
        foreach ($history as $msg) {
            if (!is_array($msg)) continue;
            
            $rawRole = $msg['role'] ?? '';
            if ($rawRole === 'system') continue;

            $role = ($rawRole === 'user') ? 'user' : 'assistant';
            $content = is_string($msg['content'] ?? null) ? $msg['content'] : '';

            if ($rawRole === 'assistant' && !empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                $blocks = [];
                if ($content !== '') $blocks[] = ['type' => 'text', 'text' => $content];
                foreach ($msg['tool_calls'] as $tc) {
                    $argsDecoded = json_decode((string)($tc['function']['arguments'] ?? '{}'), true);
                    $blocks[] = [
                        'type'  => 'tool_use',
                        'id'    => (string)($tc['id'] ?? ''),
                        'name'  => (string)($tc['function']['name'] ?? ''),
                        'input' => (is_array($argsDecoded) && !empty($argsDecoded)) ? $argsDecoded : new \stdClass(),
                    ];
                }
                $messages[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }

            if ($rawRole === 'tool') {
                $block = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string)($msg['tool_call_id'] ?? ''),
                    'content'     => $content,
                ];
                
                $lastIdx = count($messages) - 1;
                if ($lastIdx >= 0 && $messages[$lastIdx]['role'] === 'user') {
                    if (is_string($messages[$lastIdx]['content'])) {
                        $oldText = $messages[$lastIdx]['content'];
                        $messages[$lastIdx]['content'] = [];
                        if (trim($oldText) !== '') {
                            $messages[$lastIdx]['content'][] = ['type' => 'text', 'text' => $oldText];
                        }
                    }
                    $messages[$lastIdx]['content'][] = $block;
                } else {
                    $messages[] = ['role' => 'user', 'content' => [$block]];
                }
                continue;
            }

            if (trim($content) === '') continue;

            $messages[] = [
                'role' => $role,
                'content' => $content
            ];
        }
        return $messages;
    }

    private function buildPayload(string $model, string $systemContext, array $history, array $params = [], bool $isStream = false): array {
        $maxTokens = (int)($params['max_tokens'] ?? 4096);
        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $this->prepareMessages($history)
        ];

        if (!empty($systemContext)) {
            $payload['system'] = $systemContext;
        }

        // Подключаем инструменты при наличии активных схем
        $toolsList = $params['_tools'] ?? $params['_web_tools'] ?? [];
        if (!empty($toolsList) && empty($params['_tool_choice_none'])) {
            $payload['tools'] = ToolSchemaAdapter::forClaude($toolsList);
        }

        if ($isStream) {
            $payload['stream'] = true;
        }

        $enableThinking = (
            ($params['enable_thinking'] ?? '0') === '1' ||
            ($params['enable_extended_thinking'] ?? '0') === '1' ||
            (!empty($params['thinking_budget']) && (int)$params['thinking_budget'] > 0) ||
            !empty($params['thinking_level']) ||
            !empty($params['reasoning_effort'])
        );

        if ($enableThinking) {
            $level = strtolower((string)($params['thinking_level'] ?? $params['reasoning_effort'] ?? 'medium'));
               // Заменяем max на xhigh
            if ($level === 'max') {
                $level = 'xhigh';
            }

            // Допустимые уровни
            if (!in_array($level, ['low', 'medium', 'high', 'xhigh'], true)) {
                $level = 'medium';
            }

            $payload['output_config'] = [
                'effort' => $level
            ];

            $payload['thinking'] = [
                'type' => 'adaptive',
                'display' => 'summarized'
            ];


            unset($payload['temperature']);
        } else {
            $payload['thinking'] = [
                'type' => 'disabled'
            ];

            if (isset($params['temperature']) && $params['temperature'] !== '') {
                $payload['temperature'] = (float)$params['temperature'];
            }
        }

        return $payload;
    }

    public function call(string $model, string $systemContext, array $history, array $params = [], int $timeout = 120): array {
        $payload = $this->buildPayload($model, $systemContext, $history, $params, false);
        AppLogger::info('claude_provider', 'Payload подготовлен для синхронного вызова', ['model' => $model, 'tools_count' => count($payload['tools'] ?? [])]);
        
        $data = $this->requestJson(self::API_URL, $this->getHeaders(), $payload, $timeout);

        $text = '';
        $reasoningContent = '';

        if (!empty($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                $blockType = $block['type'] ?? '';
                if ($blockType === 'text') {
                    $text .= $block['text'] ?? '';
                } elseif ($blockType === 'thinking') {
                    $reasoningContent .= $block['thinking'] ?? '';
                }
            }
        }

        $usage = $data['usage'] ?? [];

        return [
            'text' => $text,
            'reasoning_content' => $reasoningContent,
            'prompt_tokens' => (int)($usage['input_tokens'] ?? 0),
            'completion_tokens' => (int)($usage['output_tokens'] ?? 0)
        ];
    }

    public function stream(string $model, string $systemContext, array $history, array $params = [], ?callable $callback = null): void {
        $payload = $this->buildPayload($model, $systemContext, $history, $params, true);
        AppLogger::info('claude_provider', 'Payload подготовлен для потокового вызова', ['model' => $model, 'tools_count' => count($payload['tools'] ?? [])]);

        $inputTokens = 0;
        $outputTokens = 0;
        $toolBlocks = [];

        $this->requestStream(self::API_URL, $this->getHeaders(), $payload, function(?array $json, string $rawLine) use ($callback, &$inputTokens, &$outputTokens, &$toolBlocks) {
            if ($json === null) return;

            $type = $json['type'] ?? '';

            if ($type === 'message_start' && isset($json['message']['usage']['input_tokens'])) {
                $inputTokens = (int)$json['message']['usage']['input_tokens'];
            } 
            elseif ($type === 'content_block_start') {
                $block = $json['content_block'] ?? [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $toolBlocks[(int)($json['index'] ?? 0)] = [
                        'id' => (string)($block['id'] ?? ''), 
                        'name' => (string)($block['name'] ?? ''), 
                        'arguments' => '',
                    ];
                }
            }
            elseif ($type === 'content_block_delta') {
                $delta = $json['delta'] ?? [];
                $deltaType = $delta['type'] ?? '';
                
                if ($deltaType === 'text_delta' && isset($delta['text'])) {
                    if ($callback) $callback('text', $delta['text']);
                } elseif ($deltaType === 'thinking_delta' && isset($delta['thinking'])) {
                    if ($callback) $callback('thinking', $delta['thinking']);
                } elseif ($deltaType === 'input_json_delta' && isset($delta['partial_json'])) {
                    $idx = (int)($json['index'] ?? 0);
                    if (isset($toolBlocks[$idx])) {
                        $toolBlocks[$idx]['arguments'] .= $delta['partial_json'];
                    }
                }
            } 
            elseif ($type === 'message_delta') {
                if (isset($json['usage']['output_tokens'])) {
                    $outputTokens = (int)$json['usage']['output_tokens'];
                }
                if ($callback && ($inputTokens > 0 || $outputTokens > 0)) {
                    $callback('usage', [
                        'prompt_tokens' => $inputTokens,
                        'completion_tokens' => $outputTokens
                    ]);
                }
            } 
            elseif ($type === 'message_stop') {
                if ($callback) $callback('done', null);
            } 
            elseif ($type === 'error') {
                $errorMsg = $json['error']['message'] ?? 'Claude API Streaming Error';
                AppLogger::error('claude_provider', "Ошибка стрима Claude API: {$errorMsg}");
                if ($callback) $callback('error', $errorMsg);
            }
        });

        if (!empty($toolBlocks) && $callback) {
            ksort($toolBlocks);
            $callback('tool_calls', array_values(array_map(fn($b) => [
                'id' => $b['id'], 
                'type' => 'function',
                'function' => [
                    'name' => $b['name'], 
                    'arguments' => $b['arguments'] !== '' ? $b['arguments'] : '{}'
                ],
            ], $toolBlocks)));
        }
    }
}
