<?php

declare(strict_types=1);

class GenericModule extends BaseHandler 
{
    public function getSystemPrompt(string $sessionPath): string 
    {
        // ПОПРАВЛЕНО: Безопасное чтение через родительский метод readJson
        $config = $this->readJson($this->presetPath . 'config.json', []);

        if (is_string($config['system_prompt'] ?? null) && !empty($config['system_prompt'])) {
            return $config['system_prompt'];
        }

        return parent::getSystemPrompt($sessionPath);
    }
}