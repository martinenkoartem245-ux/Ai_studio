<?php

declare(strict_types=1);

class SessionService {
public function getStructure(string $basePresetsDir): array {
        $structure = [];
        if (!file_exists($basePresetsDir) || !is_dir($basePresetsDir)) {
            return $structure;
        }
        $basePresetsDir = rtrim($basePresetsDir, '/\\') . DIRECTORY_SEPARATOR;
        $folders = scandir($basePresetsDir) ?: [];

        foreach ($folders as $p) {
            // Игнорируем служебные директории и папку с логами
            if ($p === '.' || $p === '..' || strtolower($p) === 'logs') continue;
            
            $pPath = $basePresetsDir . $p . DIRECTORY_SEPARATOR;
            if (!is_dir($pPath) || is_link($pPath)) continue;

            $cfg = JsonStorage::read($pPath . 'config.json');
            $sessions = [];
            $sessionsDir = $pPath . 'sessions' . DIRECTORY_SEPARATOR;
            
            if (is_dir($sessionsDir)) {
                $sessionFolders = scandir($sessionsDir) ?: [];
                foreach ($sessionFolders as $s) {
                    if ($s === '.' || $s === '..' || !is_dir($sessionsDir . $s) || is_link($sessionsDir . $s)) continue;
                    
                    $meta = JsonStorage::read($sessionsDir . $s . DIRECTORY_SEPARATOR . 'meta.json');
                    $sessionName = !empty($meta['title']) ? (string)$meta['title'] : $s;
                    
                    $sessions[] = [
                        'id' => $s, 
                        'name' => $sessionName,
                        'created_at' => $meta['created_at'] ?? null
                    ];
                }
            }

            $structure[] = [
                'id' => $p,
                'name' => $cfg['preset_name'] ?? ucfirst(str_replace('_', ' ', $p)),
                'model' => $cfg['model'] ?? 'gemini-3.6-flash',
                'has_dictionary' => !empty($cfg['has_dictionary']),
                'sessions' => $sessions
            ];
        }

        return $structure;
    }

    public function createSession(string $presetPath, string $preset, string $title = '', array $extraMeta = []): string {
        $presetPath = rtrim($presetPath, '/\\') . DIRECTORY_SEPARATOR;
        $title = trim($title);
        if ($title === '') {
            $title = 'Сессия ' . date('d.m H:i');
        }

        $sessionId = 'session_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $sessionPath = $presetPath . 'sessions' . DIRECTORY_SEPARATOR . $sessionId . DIRECTORY_SEPARATOR;
        $contextDir = $sessionPath . 'context_data' . DIRECTORY_SEPARATOR;

        if (!is_dir($contextDir)) {
            if (!@mkdir($contextDir, 0755, true) && !is_dir($contextDir)) {
                throw new \RuntimeException("Не удалось создать директорию сессии: {$contextDir}");
            }
        }

        $config = JsonStorage::read($presetPath . 'config.json');
        $defaultModel = $config['model'] ?? 'gemini-3.6-flash';

        $metaData = array_merge([
            'title' => $title,
            'preset' => $preset,
            'model' => $defaultModel,
            'created_at' => date('Y-m-d H:i:s'),
            'is_generating' => false,
            'last_usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
            'last_cost' => 0
        ], $extraMeta);

        JsonStorage::write($sessionPath . 'meta.json', $metaData);
        JsonStorage::write($sessionPath . 'chat_history.json', []);
        JsonStorage::write($sessionPath . 'context_memory.json', [
            'core_timeline' => '', 
            'blocks' => [], 
            'processed_count' => 0
        ]);

        return $sessionId;
    }

    public function deleteSession(string $sessionPath): bool {
        return JsonStorage::deleteDir($sessionPath);
    }

    public function isGenerating(string $sessionPath): bool {
        $meta = JsonStorage::read(rtrim($sessionPath, '/\\') . DIRECTORY_SEPARATOR . 'meta.json');
        return !empty($meta['is_generating']);
    }

    public function setGeneratingStatus(string $sessionPath, bool $isGenerating): void {
        $metaFile = rtrim($sessionPath, '/\\') . DIRECTORY_SEPARATOR . 'meta.json';
        $meta = JsonStorage::read($metaFile);
        $meta['is_generating'] = $isGenerating;
        JsonStorage::write($metaFile, $meta);
    }

    public function updateStats(string $sessionPath, array $usage, float $cost): void {
        $metaFile = rtrim($sessionPath, '/\\') . DIRECTORY_SEPARATOR . 'meta.json';
        $meta = JsonStorage::read($metaFile);
        $meta['last_usage'] = $usage;
        $meta['last_cost'] = $cost;
        JsonStorage::write($metaFile, $meta);
    }
    // SessionService.php
public function createPreset(
    string $basePresetsDir,
    string $presetName,
    string $model,
    bool $hasDictionary,
    string $systemPrompt = "Ты — полезный ИИ-помощник."
): array {
    $basePresetsDir = rtrim($basePresetsDir, '/\\') . DIRECTORY_SEPARATOR;

    $presetId = 'preset_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2));
    $presetPath = $basePresetsDir . $presetId . DIRECTORY_SEPARATOR;

    if (!is_dir($presetPath)) {
        if (!@mkdir($presetPath, 0755, true) && !is_dir($presetPath)) {
            throw new \RuntimeException("Не удалось создать директорию пресета: {$presetPath}");
        }
    }

    $configData = [
        'preset_name'    => $presetName,
        'model'          => $model,
        'has_dictionary' => $hasDictionary,
        'system_prompt'  => $systemPrompt,   // ← сохраняем инструкции
    ];

    JsonStorage::write($presetPath . 'config.json', $configData);

    $sessionId = $this->createSession($presetPath, $presetId, 'Главная сессия');

    return [
        'preset_id'  => $presetId,
        'session_id' => $sessionId
    ];
}

public function deletePreset(string $basePresetsDir, string $presetId): bool {
        $basePresetsDir = rtrim($basePresetsDir, '/\\') . DIRECTORY_SEPARATOR;
        $presetPath = $basePresetsDir . preg_replace('/[^\p{L}\p{N}_\-]/u', '', $presetId);
        
        if (!is_dir($presetPath)) {
            return false;
        }
        return JsonStorage::deleteDir($presetPath);
    }
}