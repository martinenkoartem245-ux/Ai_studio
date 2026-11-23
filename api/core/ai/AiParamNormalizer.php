<?php

declare(strict_types=1);

class AiParamNormalizer {
    public static function extract(Request $request, array $meta = []): array {
        $model = $request->getString('model', $meta['model'] ?? 'gemini-3.6-flash');
        $temperature = $request->getFloat('temperature', (float)($meta['temperature'] ?? 1.0));
        $maxTokens = $request->getInt('max_tokens', (int)($meta['max_tokens'] ?? 30000));
        
        $systemContext = $request->getString('system_context', '');

        $baseParams = [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'system_context' => $systemContext,
            // Новые флаги инструментов (общие для всех моделей)
            'use_web_search'   => $request->getBool('use_web_search'),
            'telegram_notify'  => $request->getBool('telegram_notify'),
            'time_in_chat'     => $request->getBool('time_in_chat'),
            'set_period'       => $request->getBool('set_period'),
        ];

        $modelLower = strtolower($model);

        if (str_contains($modelLower, 'gemini')) {
            return self::normalizeGemini($request, $baseParams);
        }

        if (str_contains($modelLower, 'claude')) {
            return self::normalizeClaude($request, $baseParams);
        }

        if (str_contains($modelLower, 'kimi') || str_contains($modelLower, 'moonshot')) {
            return self::normalizeKimi($request, $baseParams);
        }

        return self::normalizeGeneric($request, $baseParams);
    }

    private static function normalizeGemini(Request $request, array $base): array {
        unset($base['max_tokens']);

        $enableThinking = $request->getBool('enable_thinking');
        $thinkingLevel = $request->getString('thinking_level', 'high');
        $thinkingBudget = $request->get('thinking_budget');

        $base['enable_thinking'] = $enableThinking;
        $base['enable_google_search'] = $request->getBool('enable_google_search');
        // Поддержка обоих вариантов нейминга с фронта: code_execution и enable_code_execution
        $base['enable_code_execution'] = $request->getBool('enable_code_execution') || $request->getBool('code_execution');
        $base['enable_url_context'] = $request->getBool('enable_url_context');

        if ($enableThinking) {
            $base['thinking_level'] = strtolower($thinkingLevel);
            if ($thinkingBudget !== null && $thinkingBudget !== '') {
                $base['thinking_budget'] = (int)$thinkingBudget;
            }
        } else {
            $base['thinking_budget'] = 0;
        }

        return $base;
    }

    private static function normalizeClaude(Request $request, array $base): array {
        $extendedThinking = $request->getBool('enable_extended_thinking') || $request->getBool('enable_thinking');

        if ($extendedThinking) {
            $budget = $request->getInt('thinking_budget', 2048);
            $base['enable_extended_thinking'] = '1';
            $base['enable_thinking'] = '1';
            $base['thinking_budget'] = $budget;
            $base['temperature'] = 1.0;
        }

        $thinkingLevel = $request->get('thinking_level', $request->get('reasoning_effort'));
        if ($thinkingLevel !== null && $thinkingLevel !== '') {
            $base['thinking_level'] = strtolower(trim((string)$thinkingLevel));
        }

        return $base;
    }

    private static function normalizeKimi(Request $request, array $base): array {
        $enableThinking = $request->getBool('enable_thinking');
        $base['enable_thinking'] = $enableThinking;

        if ($enableThinking) {
            $base['temperature'] = 1.0;

            $thinkingLevel = $request->get('thinking_level', $request->get('reasoning_effort'));
            if ($thinkingLevel !== null && $thinkingLevel !== '') {
                $base['thinking_level'] = strtolower(trim((string)$thinkingLevel));
            }
        }

        return $base;
    }

    private static function normalizeGeneric(Request $request, array $base): array {
        $passthrough = ['top_p', 'presence_penalty', 'frequency_penalty', 'response_format'];
        foreach ($passthrough as $key) {
            $val = $request->get($key);
            if ($val !== null) {
                $base[$key] = $val;
            }
        }
        return $base;
    }
}
