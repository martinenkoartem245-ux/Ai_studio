<?php

declare(strict_types=1);

class KimiProvider extends AbstractProvider {
    private const API_URL = 'https://api.moonshot.ai/v1/chat/completions';

    private const FIXED_PARAM_MODELS = [
        'kimi-k3.1-pro',
        'kimi-k2.7-code',
        'kimi-k2.6',
    ];

    private function getHeaders(): array {
        return [
            'Authorization: Bearer ' . $this->getApiKey('KIMI_API_KEY'),
            'Content-Type: application/json'
        ];
    }

    private function prepareMessages(string $systemContext, array $history): array {
        $messages = [];
        if (!empty($systemContext)) {
            $messages[] = ['role' => 'system', 'content' => $systemContext];
        }

        foreach ($history as $msg) {
            if (!is_array($msg)) continue;

            $rawRole = $msg['role'] ?? '';
            $allowedRoles = ['user', 'assistant', 'system', 'tool'];
            $role = in_array($rawRole, $allowedRoles, true) ? $rawRole : 'assistant';

            $content = is_string($msg['content'] ?? null) ? $msg['content'] : '';
            $message = ['role' => $role, 'content' => $content];

            if (!empty($msg['reasoning_content']) && is_string($msg['reasoning_content'])) {
                $message['reasoning_content'] = $msg['reasoning_content'];
            }

            if ($role === 'assistant' && !empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                $message['tool_calls'] = $msg['tool_calls'];
            }

            if ($role === 'tool') {
                if (!empty($msg['tool_call_id']) && is_string($msg['tool_call_id'])) {
                    $message['tool_call_id'] = $msg['tool_call_id'];
                }
                if (!empty($msg['name']) && is_string($msg['name'])) {
                    $message['name'] = $msg['name'];
                }
            }

            $lastIdx = count($messages) - 1;
            if ($lastIdx >= 0 && $messages[$lastIdx]['role'] === $role && $role !== 'tool' && empty($message['tool_calls']) && empty($messages[$lastIdx]['tool_calls'])) {
                $messages[$lastIdx]['content'] .= "\n\n" . $content;
            } else {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function isFixedParamModel(string $model): bool {
        foreach (self::FIXED_PARAM_MODELS as $fixed) {
            if ($model === $fixed || str_starts_with($model, $fixed)) {
                return true;
            }
        }
        return false;
    }

    public function buildPayload(string $model, string $systemContext, array $history, array $params = [], bool $isStream = false): array {
        $isThinkingEnabled = !empty($params['enable_thinking']) && in_array((string)$params['enable_thinking'], ['1', 'true', 'on'], true);

        if (!isset($params['thinking'])) {
            $params['thinking'] = $isThinkingEnabled
                ? ['type' => 'enabled', 'keep' => 'all']
                : ['type' => 'disabled'];
        }

        $messages = $this->prepareMessages($systemContext, $history);

        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        $isFixed = $this->isFixedParamModel($model);

        if (!empty($params['max_completion_tokens'])) {
            $payload['max_completion_tokens'] = (int)$params['max_completion_tokens'];
        } elseif (!empty($params['max_tokens'])) {
            $payload['max_tokens'] = (int)$params['max_tokens'];
        }

        if (str_starts_with($model, 'kimi-k3.1-pro')) {
            $effort = $params['reasoning_effort'] ?? $params['thinking_level'] ?? 'high';
            if (in_array($effort, ['low', 'high', 'max', 'medium'], true)) {
                $payload['reasoning_effort'] = ($effort === 'medium') ? 'high' : $effort;
            }
        } elseif ($model === 'kimi-k2.7-code' || $model === 'kimi-k2.7-code-highspeed') {
            if (!empty($params['thinking']) && is_array($params['thinking'])) {
                $thinking = $params['thinking'];
                if (($thinking['type'] ?? '') === 'enabled') {
                    $payload['thinking'] = ['type' => 'enabled', 'keep' => 'all'];
                }
            }
        } elseif ($model === 'kimi-k2.6' || str_starts_with($model, 'kimi-k2.6')) {
            if (!empty($params['thinking']) && is_array($params['thinking'])) {
                $type = $params['thinking']['type'] ?? ($isThinkingEnabled ? 'enabled' : 'disabled');
                if (in_array($type, ['enabled', 'disabled'], true)) {
                    $payload['thinking'] = ['type' => $type];
                    if ($type === 'enabled' && ($params['thinking']['keep'] ?? 'all') === 'all') {
                        $payload['thinking']['keep'] = 'all';
                    }
                }
            } else {
                $payload['thinking'] = ['type' => $isThinkingEnabled ? 'enabled' : 'disabled'];
            }
        }

        if ($isThinkingEnabled || (($payload['thinking']['type'] ?? '') === 'enabled')) {
            $payload['temperature'] = 1.0;
        } elseif (!$isFixed) {
            foreach (['temperature' => 'float', 'top_p' => 'float', 'presence_penalty' => 'float', 'frequency_penalty' => 'float', 'n' => 'int'] as $param => $type) {
                if (isset($params[$param])) {
                    $payload[$param] = ($type === 'float') ? (float)$params[$param] : (int)$params[$param];
                }
            }
        }

        // Подключаем пользовательские и встроенные инструменты
        $tools = !empty($params['tools']) && is_array($params['tools']) ? $params['tools'] : [];
        $toolsList = $params['_tools'] ?? $params['_web_tools'] ?? [];

        if (!empty($toolsList) && empty($params['_tool_choice_none'])) {
            $existing = array_column(array_column($tools, 'function'), 'name');
            foreach (ToolSchemaAdapter::forOpenAi($toolsList) as $t) {
                if (!in_array($t['function']['name'], $existing, true)) {
                    $tools[] = $t;
                }
            }
        }

        if (!empty($params['use_builtin_web_search'])) {
            $tools[] = self::webSearchTool();
        }

        if (!empty($tools)) {
            $payload['tools'] = $tools;

            if (!empty($params['tool_choice'])) {
                $tc = $params['tool_choice'];
                if ($tc === 'required' && !str_starts_with($model, 'kimi-k3.1-pro')) {
                    $payload['tool_choice'] = 'auto';
                } elseif (in_array($tc, ['auto', 'none', 'required'], true) || is_array($tc)) {
                    $payload['tool_choice'] = $tc;
                }
            }
        }

        if (!empty($params['response_format']) && is_array($params['response_format'])) {
            $payload['response_format'] = $params['response_format'];
        }

        if ($isStream) {
            $payload['stream'] = true;
            $payload['stream_options'] = (!empty($params['stream_options']) && is_array($params['stream_options']))
                ? $params['stream_options']
                : ['include_usage' => true];
        }

        if (!empty($params['logprobs'])) {
            $payload['logprobs'] = true;
            if (isset($params['top_logprobs'])) {
                $payload['top_logprobs'] = min(20, max(0, (int)$params['top_logprobs']));
            }
        }

        return $payload;
    }

    public function call(string $model, string $systemContext, array $history, array $params = [], int $timeout = 120): array {
        $payload = $this->buildPayload($model, $systemContext, $history, $params, false);

        $data = $this->requestJson(self::API_URL, $this->getHeaders(), $payload, $timeout);

        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $usage = $data['usage'] ?? [];

        return [
            'text' => $message['content'] ?? '',
            'reasoning_content' => $message['reasoning_content'] ?? '',
            'finish_reason' => $choice['finish_reason'] ?? '',
            'tool_calls' => $message['tool_calls'] ?? [],
            'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int)($usage['total_tokens'] ?? 0),
        ];
    }

    public function stream(string $model, string $systemContext, array $history, array $params = [], ?callable $callback = null): void {
        $payload = $this->buildPayload($model, $systemContext, $history, $params, true);

        $currentToolCalls = [];
        $usage = [];

        $this->requestStream(self::API_URL, $this->getHeaders(), $payload, function(?array $json, string $rawLine) use ($callback, &$currentToolCalls, &$usage) {
            if ($rawLine === '[DONE]') {
                if (!empty($usage) && $callback) {
                    $callback('usage', $usage);
                }
                return;
            }

            if ($json === null) return;

            if (isset($json['error'])) {
                $errorMsg = $json['error']['message'] ?? 'Kimi Streaming Error';
                if ($callback) $callback('error', $errorMsg);
                return;
            }

            if (isset($json['usage']) && is_array($json['usage'])) {
                $usage = $json['usage'];
                return;
            }

            $choice = $json['choices'][0] ?? null;
            if (!is_array($choice)) return;

            if (!empty($choice['finish_reason']) && $callback) {
                $callback('finish', $choice['finish_reason']);
            }

            $delta = $choice['delta'] ?? [];
            if (!is_array($delta)) return;

            if (!empty($delta['reasoning_content']) && is_string($delta['reasoning_content']) && $callback) {
                $callback('thinking', $delta['reasoning_content']);
            }

            if (isset($delta['content']) && $delta['content'] !== '' && $callback) {
                $callback('text', $delta['content']);
            }

            if (!empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    $index = (int)($tc['index'] ?? 0);
                    if (!isset($currentToolCalls[$index])) {
                        $currentToolCalls[$index] = [
                            'id' => $tc['id'] ?? '',
                            'type' => $tc['type'] ?? 'function',
                            'function' => ['name' => '', 'arguments' => '']
                        ];
                    }
                    if (!empty($tc['id'])) {
                        $currentToolCalls[$index]['id'] = $tc['id'];
                    }
                    if (!empty($tc['type'])) {
                        $currentToolCalls[$index]['type'] = $tc['type'];
                    }
                    if (!empty($tc['function']['name'])) {
                        $currentToolCalls[$index]['function']['name'] .= $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments']) && $tc['function']['arguments'] !== '') {
                        $currentToolCalls[$index]['function']['arguments'] .= $tc['function']['arguments'];
                    }
                }
                if ($callback) {
                    $callback('tool_calls_delta', $delta['tool_calls']);
                }
            }
        });

        if (!empty($currentToolCalls) && $callback) {
            ksort($currentToolCalls);
            $callback('tool_calls', array_values($currentToolCalls));
        }
    }

    public static function webSearchTool(): array {
        return [
            'type' => 'builtin_function',
            'function' => ['name' => '$web_search'],
        ];
    }
}