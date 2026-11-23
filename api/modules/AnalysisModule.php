<?php

declare(strict_types=1);

class AnalysisModule extends BaseHandler 
{
    public function getSystemPrompt(string $sessionPath): string 
    {
        $sessionDir = $this->normalizeDir($sessionPath);
        $meta = $this->readJson($sessionDir . 'meta.json');
        $config = $this->readJson($this->presetPath . 'config.json');

        $baseRole = (is_string($config['system_prompt'] ?? null) && !empty($config['system_prompt']))
            ? $config['system_prompt']
            : "Ты — профессиональный ИИ-ассистент для глубокого анализа данных, кода и документов.";

        $goal = is_string($meta['goal'] ?? null) ? trim($meta['goal']) : null;
        $format = is_string($meta['format'] ?? null) ? trim($meta['format']) : null;

        $prompt = $baseRole;

        if (!empty($goal) || !empty($format)) {
            $prompt .= "\n\nПАРАМЕТРЫ ТЕКУЩЕГО АНАЛИЗА:";
            if (!empty($goal)) {
                $prompt .= "\n- Цель анализа: " . htmlspecialchars($goal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            if (!empty($format)) {
                $prompt .= "\n- Формат вывода: " . htmlspecialchars($format, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return $prompt;
    }
}