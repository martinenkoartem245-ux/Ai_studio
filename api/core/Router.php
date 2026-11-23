<?php

declare(strict_types=1);

class Router {
    private static array $moduleMap = [
        'polish_chronos'  => 'PolishModule',
        'english_chronos' => 'EnglishModule',
        'deep_analysis'   => 'AnalysisModule',
    ];

    public static function dispatch(Request $request): void {
        $action = $request->getString('action');
        $rawPreset = $request->getString('preset');
        if (empty($rawPreset)) {
            $rawPreset = 'polish_chronos';
        }

        $preset = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $rawPreset);

        $db = self::resolveDatabase();

        if ($action === 'get_structure') {
            $handler = new BaseHandler('', $db);
            $handler->handleAction($action, $request);
            return;
        }

        $handler = self::resolveHandler($preset, $db);
        $handler->handleAction($action, $request);
    }

    private static function resolveHandler(string $preset, ?PDO $db): BaseHandler {
        global $aiService;

        $baseDir = defined('STORAGE_PATH') 
            ? STORAGE_PATH 
            : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'presets_data');

        $presetPath = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $preset . DIRECTORY_SEPARATOR;
        $configFile = $presetPath . 'config.json';

        $config = file_exists($configFile) ? JsonStorage::read($configFile) : [];
        $hasDict = !empty($config['has_dictionary']);

        $className = self::$moduleMap[$preset] ?? null;

        if ($className && class_exists($className)) {
            return new $className($preset, $db, $aiService ?? null);
        }

        if ($hasDict && class_exists('GenericDictionaryModule')) {
            return new GenericDictionaryModule($preset, $db, $aiService ?? null);
        }

        if (class_exists('GenericModule')) {
            return new GenericModule($preset, $db, $aiService ?? null);
        }

        return new BaseHandler($preset, $db, $aiService ?? null);
    }

    private static function resolveDatabase(): ?PDO {
        // Теперь используется единая БД для всех языков и пресетов
        return $GLOBALS['db'] ?? $GLOBALS['pdo'] ?? null;
    }
}