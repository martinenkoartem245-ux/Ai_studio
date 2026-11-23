<?php

class CostCalculator {
    private static array $rates = [
        'kimi-k3.1-pro'               => ['input' => 0.002, 'output' => 0.010],
        'kimi-k2.6'             => ['input' => 0.00095, 'output' => 0.004],
        'kimi-k2.7-code'        => ['input' => 0.00095, 'output' => 0.004],
        'claude-sonnet-5-5'     => ['input' => 0.002, 'output' => 0.01],
        'claude-opus-5-5'       => ['input' => 0.004, 'output' => 0.02],
        'claude-haiku-5-5'      => ['input' => 0.0001, 'output' => 0.0005],
        'gemini-3.6-flash'      => ['input' => 0.000075, 'output' => 0.00015],
        'gemini-3.5-flash-lite' => ['input' => 0.0003, 'output' => 0.0025],
    ];

    public static function extractTokens(array $usage): array {
        $prompt = $usage['prompt_tokens'] 
            ?? $usage['input_tokens'] 
            ?? $usage['promptTokenCount'] 
            ?? 0;

        $completion = $usage['completion_tokens'] 
            ?? $usage['output_tokens'] 
            ?? $usage['candidatesTokenCount'] 
            ?? 0;

        return [(int)$prompt, (int)$completion];
    }

    public static function calculate(string $model, int $promptTokens, int $completionTokens): float {
        $rate = self::$rates[$model] ?? ['input' => 0.0001, 'output' => 0.0004];
        $cost = (($promptTokens / 1000) * $rate['input']) + (($completionTokens / 1000) * $rate['output']);
        
        return round($cost, 6);
    }
}