<?php
declare(strict_types=1);

class ToolSchemaAdapter {
    public static function forGemini(array $schemas, string $sessionPath = ''): array {
        if (class_exists('InstructionLogger')) {
            InstructionLogger::logDebug($sessionPath, "ВХОД ToolSchemaAdapter::forGemini | Схем: " . count($schemas));
        }

        $result = ['function_declarations' => array_map(fn($t) => [
            'name'        => $t['name'],
            'description' => $t['description'],
            'parameters'  => $t['parameters'],
        ], $schemas)];

        if (class_exists('InstructionLogger')) {
            InstructionLogger::logDebug($sessionPath, "ВЫХОД ToolSchemaAdapter::forGemini | Адаптировано элементов: " . count($result['function_declarations']));
        }

        return $result;
    }

    public static function forClaude(array $schemas, string $sessionPath = ''): array {
        if (class_exists('InstructionLogger')) {
            InstructionLogger::logDebug($sessionPath, "ВХОД ToolSchemaAdapter::forClaude | Схем: " . count($schemas));
        }

        $result = array_map(fn($t) => [
            'name'         => $t['name'],
            'description'  => $t['description'],
            'input_schema' => $t['parameters'],
        ], $schemas);

        if (class_exists('InstructionLogger')) {
            InstructionLogger::logDebug($sessionPath, "ВЫХОД ToolSchemaAdapter::forClaude | Адаптировано элементов: " . count($result));
        }

        return $result;
    }

    // OpenAI-совместимый формат (Kimi)
    public static function forOpenAi(array $schemas, string $sessionPath = ''): array {
        if (class_exists('InstructionLogger')) {
            InstructionLogger::logDebug($sessionPath, "ВХОД ToolSchemaAdapter::forOpenAi | Схем: " . count($schemas));
        }

        $result = array_map(fn($t) => [
            'type'     => 'function',
            'function' => [
                'name'        => $t['name'],
                'description' => $t['description'],
                'parameters'  => $t['parameters'],
            ],
        ], $schemas);

        if (class_exists('InstructionLogger')) {
            InstructionLogger::logDebug($sessionPath, "ВЫХОД ToolSchemaAdapter::forOpenAi | Адаптировано элементов: " . count($result));
        }

        return $result;
    }
}
