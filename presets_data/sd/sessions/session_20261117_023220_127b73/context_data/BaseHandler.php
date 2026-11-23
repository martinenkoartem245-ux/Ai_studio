<?php

declare(strict_types=1);

class BaseHandler
{
    protected string $preset;
    protected string $basePresetsDir;
    protected string $presetPath;
    protected string $languageCode = 'en';
    protected const DICTIONARY_HISTORY_LIMIT = 10;

    protected SessionService $sessionService;
    protected FileService $fileService;
    protected ?DictionaryService $dictionaryService = null;
    protected ?object $aiService = null;

    public function __construct(string $preset = '', ?PDO $db = null, ?object $aiService = null)
    {
        AppLogger::init();

        $this->preset = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $preset);

        $baseDir = defined('STORAGE_PATH')
            ? STORAGE_PATH
            : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'presets_data');

        $this->basePresetsDir = $this->normalizeDir($baseDir);
        $this->presetPath = $this->normalizeDir($this->basePresetsDir . ($this->preset !== '' ? $this->preset : ''));

        $this->languageCode = $this->resolveLanguageFromConfig($this->presetPath);

        $this->sessionService = new SessionService();
        $this->fileService = new FileService();

        $this->aiService = ($aiService !== null && method_exists($aiService, 'streamModelResponse'))
            ? $aiService
            : (class_exists('AiService') ? new AiService() : null);

        if ($db !== null) {
            $this->dictionaryService = new DictionaryService($db, $this->languageCode, $this->aiService);
        }
    }

    protected function resolveLanguageFromConfig(string $presetPath): string
    {
        $configFile = $presetPath . 'config.json';
        $config = $this->readJson($configFile, []);
        $lang = strtolower((string)($config['language'] ?? 'en'));
        return in_array($lang, ['en', 'pl'], true) ? $lang : 'en';
    }

    protected function normalizeDir(string $path): string
    {
        return rtrim($path, '\\/') . DIRECTORY_SEPARATOR;
    }

    protected function readJson(string $filePath, array $default = []): array
    {
        if (!file_exists($filePath)) {
            return $default;
        }
        $data = JsonStorage::read($filePath, $default);
        return is_array($data) ? $data : $default;
    }

    protected function writeJson(string $filePath, array $data): bool
    {
        return JsonStorage::write($filePath, $data);
    }

    public function getSystemPrompt(string $sessionPath): string
    {
        $config = $this->readJson($this->presetPath . 'config.json');

        if (is_string($config['system_prompt'] ?? null) && !empty($config['system_prompt'])) {
            return $config['system_prompt'];
        }

        return "Ты — полезный ИИ-помощник.";
    }

    public function afterMessageSent(string $sessionPath): void {}

    public function handleAction(string $action, Request $request): void
    {
        // 1. Подтягиваем preset ДО роутинга экшенов и актуализируем язык словаря
        $reqPreset = $request->getString('preset');
        if (!empty($reqPreset)) {
            $this->preset = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $reqPreset);
            $this->presetPath = $this->normalizeDir($this->basePresetsDir . $this->preset);
            
            $this->languageCode = $this->resolveLanguageFromConfig($this->presetPath);
            if ($this->dictionaryService !== null) {
                $this->dictionaryService->setLanguageCode($this->languageCode);
            }
        }

        // Логирование HTTP-запроса с актуальным presetPath
        AppLogger::info('http_requests', "Action: {$action}", [
            'action' => $action,
            'preset' => $this->preset,
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'get'    => $_GET,
            'post'   => $_POST
        ]);

        if ($action === 'send_message') {
            $this->sendMessage($request);
            return;
        }

        if ($action === 'get_structure') {
            Response::json(['presets' => $this->sessionService->getStructure($this->basePresetsDir)]);
            return;
        }

        if ($action === 'create_session' || $action === 'create') {
            try {
                $title = $request->getString('title', $request->getString('name', ''));
                $sessionId = $this->sessionService->createSession($this->presetPath, $this->preset, $title);

                Response::json([
                    'status'  => 'success',
                    'session' => $sessionId,
                    'preset'  => $this->preset
                ]);
            } catch (\Throwable $e) {
                AppLogger::error('system', 'Ошибка создания сессии', ['error' => $e->getMessage()]);
                Response::error('Ошибка создания сессии: ' . $e->getMessage(), 500);
            }
            return;
        }
        if ($action === 'get_notes') {
            try {
                $notesFile = $this->basePresetsDir . 'global_notes.json';
                $data = $this->readJson($notesFile, ['notes' => []]);
                Response::json([
                    'status' => 'success',
                    'notes'  => $data['notes'] ?? []
                ]);
            } catch (\Throwable $e) {
                AppLogger::error('system', 'Ошибка чтения заметок', ['error' => $e->getMessage()]);
                Response::error('Ошибка чтения заметок: ' . $e->getMessage(), 500);
            }
            return;
        }

        if ($action === 'save_notes') {
            try {
                $notesFile = $this->basePresetsDir . 'global_notes.json';
                
                // Читаем тело запроса (JSON или POST)
                $rawBody = file_get_contents('php://input');
                $payload = json_decode($rawBody, true);
                
                $notes = null;
                if (is_array($payload) && isset($payload['notes'])) {
                    $notes = $payload['notes'];
                } else {
                    $notesRaw = $request->getString('notes', '');
                    $notes = json_decode($notesRaw, true);
                }

                if (!is_array($notes)) {
                    Response::error('Неверный формат данных заметок', 400);
                    return;
                }

                $this->writeJson($notesFile, [
                    'notes'      => $notes,
                    'updated_at' => date('c')
                ]);

                Response::json(['status' => 'success', 'notes' => $notes]);
            } catch (\Throwable $e) {
                AppLogger::error('system', 'Ошибка сохранения заметок', ['error' => $e->getMessage()]);
                Response::error('Ошибка сохранения заметок: ' . $e->getMessage(), 500);
            }
            return;
        }


        if ($action === 'create_preset') {
            try {
                $presetName = $request->getString('preset_name');
                $model = $request->getString('model', 'kimi-k3');
                $hasDict = $request->getString('has_dictionary') === '1';

                if (empty(trim($presetName))) {
                    Response::error('Название пресета не может быть пустым', 400);
                    return;
                }

                $result = $this->sessionService->createPreset($this->basePresetsDir, $presetName, $model, $hasDict);

                Response::json([
                    'status'     => 'success',
                    'preset_id'  => $result['preset_id'],
                    'session_id' => $result['session_id']
                ]);
            } catch (\Throwable $e) {
                AppLogger::error('system', 'Ошибка создания пресета', ['error' => $e->getMessage()]);
                Response::error('Ошибка создания пресета: ' . $e->getMessage(), 500);
            }
            return;
        }

        if ($action === 'delete_preset') {
            try {
                $presetToDelete = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $request->getString('preset'));
                if (empty($presetToDelete)) {
                    Response::error('Параметр preset не указан', 400);
                    return;
                }
                $res = $this->sessionService->deletePreset($this->basePresetsDir, $presetToDelete);
                Response::json(['status' => $res ? 'success' : 'error']);
            } catch (\Throwable $e) {
                AppLogger::error('system', 'Ошибка удаления пресета', ['error' => $e->getMessage()]);
                Response::error('Ошибка удаления пресета: ' . $e->getMessage(), 500);
            }
            return;
        }

        $rawSession = $request->getString('session',
                        $request->getString('session_id',
                        $request->getString('sessionId',
                        $request->getString('id'))));

        $session = preg_replace('/[^\p{L}\p{N}_\-]/u', '', (string)$rawSession);

        if (empty($session)) {
            Response::error('Параметр session не указан или имеет неверный формат', 400);
            return;
        }

        $path = $this->normalizeDir($this->presetPath . 'sessions' . DIRECTORY_SEPARATOR . $session);

        switch ($action) {
            case 'update_session_model':
            case 'set_model':
            case 'update_session':
            case 'update_meta':
                $meta = $this->readJson($path . 'meta.json', []);
                $model = $request->getString('model', $request->getString('model_id'));

                if (!empty($model)) {
                    $meta['model'] = $model;
                    $this->writeJson($path . 'meta.json', $meta);
                }

                Response::json([
                    'status'  => 'success',
                    'session' => $session,
                    'model'   => $model,
                    'meta'    => $meta
                ]);
                break;

            case 'delete_session':
                $res = $this->sessionService->deleteSession(rtrim($path, DIRECTORY_SEPARATOR));
                Response::json(['status' => $res ? 'success' : 'error']);
                break;

            case 'get_session_data':
                $meta = $this->readJson($path . 'meta.json', []);
                $config = $this->readJson($this->presetPath . 'config.json', []);
                $rawHistory = $this->readJson($path . 'chat_history.json', []);
                
                $files = $this->fileService->getSessionFiles($path);

                Response::json([
                    'config'  => $config,
                    'meta'    => $meta,
                    'history' => array_values($rawHistory),
                    'files'   => $files
                ]);
                break;

            case 'upload_file':
            case 'upload_files':
                if (empty($_FILES['files'] ?? $_FILES['file'] ?? null)) {
                    Response::error('Файлы не переданы в запросе', 400);
                    return;
                }
                
                $filesData = $_FILES['files'] ?? $_FILES['file'];
                $result = $this->fileService->uploadFiles($path, $filesData);
                
                if ($result['status'] === 'error') {
                    Response::error($result['message'], 400);
                } else {
                    Response::json($result);
                }
                break;

            case 'delete_file':
                $fileName = $request->getString('file', $request->getString('filename', ''));
                if (empty($fileName)) {
                    Response::error('Не указано имя файла для удаления', 400);
                    return;
                }

                $deleted = $this->fileService->deleteFile($path, $fileName);
                Response::json([
                    'status'  => $deleted ? 'success' : 'error',
                    'message' => $deleted ? 'Файл успешно удалён' : 'Не удалось удалить файл'
                ]);
                break;

            case 'watch_session':
                $this->watchSession($request);
                return;

            case 'get_dictionary':
                $dictData = ($this->dictionaryService !== null)
                    ? $this->dictionaryService->getDictionaryData()
                    : ['master' => [], 'active_buffer' => []];

                Response::json([
                    'status'     => 'success',
                    'dictionary' => $dictData
                ]);
                break;

            case 'add_word':
                $word = $request->getString('word', $request->getString('term', ''));
                $category = $request->getString('category', 'B1_B2');

                if (empty(trim($word))) {
                    Response::error('Слово или термин не может быть пустым', 400);
                    return;
                }

                if ($this->dictionaryService === null) {
                    Response::error('Служба словаря недоступна (нет подключения к БД)', 500);
                    return;
                }

                $success = $this->dictionaryService->addWord($word, $category);
                
                if (!$success) {
                    Response::error('Не удалось добавить слово в словарь', 500);
                    return;
                }

                $dictData = $this->dictionaryService->getDictionaryData();

                Response::json([
                    'status'     => 'success',
                    'dictionary' => $dictData
                ]);
                break;

            default:
                Response::error('Unknown action: ' . $action, 404);
                break;
        }
    }

public function sendMessage(Request $request): void {
    // Скрипт не прерывается при отключении клиента
    ignore_user_abort(true);
    set_time_limit(300);

    SseResponse::init();

    $rawSession = $request->getString('session');
    $message    = $request->getString('message');

    if ($rawSession === '') {
        SseResponse::error('Параметр session отсутствует или пуст.');
        return;
    }

    if (trim($message) === '') {
        SseResponse::error('Параметр message отсутствует или пуст.');
        return;
    }

    $session = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $rawSession);
    if ($session === '') {
        SseResponse::error('Параметр session содержит недопустимые символы.');
        return;
    }

    $sessionPath = $this->normalizeDir($this->presetPath . 'sessions' . DIRECTORY_SEPARATOR . $session);
    if (!is_dir($sessionPath)) {
        SseResponse::error('Сессия не найдена: ' . $sessionPath);
        return;
    }

    if ($this->sessionService->isGenerating($sessionPath)) {
        SseResponse::error('Сессия уже обрабатывает запрос.');
        return;
    }

    $this->sessionService->setGeneratingStatus($sessionPath, true);

    // Буферы для накопления ответа
    $textAccumulator      = '';
    $thinkingAccumulator  = '';
    $lastUsage            = [];
    $activeModel          = '';
    $isAssistantCommitted = false;

    $historyFile = $sessionPath . 'chat_history.json';

    // ЭТАП 1: сразу записываем сообщение пользователя на диск
    $fullHistory   = $this->readJson($historyFile, []);
    $fullHistory[] = [
        'role'      => 'user',
        'content'   => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    $fullHistory = array_values($fullHistory);
    $this->writeJson($historyFile, $fullHistory);

    // Аварийный предохранитель на случай фатальной ошибки или таймаута
    register_shutdown_function(function () use (
        &$isAssistantCommitted,
        $sessionPath,
        $historyFile,
        &$fullHistory,
        &$textAccumulator,
        &$thinkingAccumulator,
        &$lastUsage,
        &$activeModel
    ) {
        if (!$isAssistantCommitted && (!empty($textAccumulator) || !empty($thinkingAccumulator))) {
            $this->commitAssistantMessage(
                $sessionPath,
                $historyFile,
                $fullHistory,
                $textAccumulator,
                $thinkingAccumulator,
                $lastUsage,
                $activeModel
            );
            $isAssistantCommitted = true;
        }
        $this->sessionService->setGeneratingStatus($sessionPath, false);
    });

    try {
        $meta   = $this->readJson($sessionPath . 'meta.json', []);
        $config = $this->readJson($this->presetPath . 'config.json', []);

        $mergedDefaults = array_merge($config, $meta);
        $params = AiParamNormalizer::extract($request, $mergedDefaults);

        $requestedModel     = !empty($params['model']) ? $params['model'] : ($config['model'] ?? 'gemini-3.6-flash');
        $modelFallbackChain = $this->getModelFallbackChain($requestedModel);

        $systemContext = $this->buildSystemContext($sessionPath, $params['system_context'] ?? '');
        $apiHistory    = $this->buildApiHistory(  $this->limitHistoryForModel($fullHistory, $config));


        if (class_exists('InstructionLogger')) {
            InstructionLogger::log($sessionPath, $systemContext, $apiHistory, $params);
        }

        $generationSuccess  = false;
        $lastException      = null;
        $chunksSentToClient = false;

        if ($this->aiService !== null) {
            foreach ($modelFallbackChain as $attempt => $currentModel) {
                try {
                    AppLogger::info('ai_service', 'Запрос к ИИ (попытка ' . ($attempt + 1) . ')', [
                        'session' => $session,
                        'model'   => $currentModel
                    ]);

                    if ($attempt > 0) {
                        if ($chunksSentToClient && !connection_aborted()) {
                            SseResponse::send('reset_stream', [
                                'reason'  => 'model_fallback',
                                'message' => "Предыдущая модель сбоила. Начинаем заново на {$currentModel}..."
                            ]);
                        }

                        if (!connection_aborted()) {
                            SseResponse::send('system_notice', [
                                'message' => "Основная модель недоступна. Переключаемся на {$currentModel}..."
                            ]);
                        }

                        $textAccumulator     = '';
                        $thinkingAccumulator = '';
                        $chunksSentToClient  = false;
                    }

                    $params['model'] = $currentModel;

                    $this->aiService->streamModelResponse(
                        $currentModel,
                        $systemContext,
                        $apiHistory,
                        $params,
                        function ($type, $chunk) use (
                            &$textAccumulator,
                            &$thinkingAccumulator,
                            &$lastUsage,
                            &$chunksSentToClient,
                            $currentModel
                        ){
                            // Накопление на сервере происходит всегда
                            if ($type === 'text' && is_string($chunk)) {
                                $textAccumulator .= $chunk;
                                $chunksSentToClient = true;
                            } elseif ($type === 'thinking' && is_string($chunk)) {
                                $thinkingAccumulator .= $chunk;
                                $chunksSentToClient = true;
                            } elseif ($type === 'usage' && is_array($chunk)) {
                                [$pTokens, $cTokens] = class_exists('CostCalculator')
                                    ? CostCalculator::extractTokens($chunk)
                                    : [(int)($chunk['prompt_tokens'] ?? 0), (int)($chunk['completion_tokens'] ?? 0)];

                                $cost = class_exists('CostCalculator')
                                    ? CostCalculator::calculate($currentModel, $pTokens, $cTokens)
                                    : 0;

                                $lastUsage = [
                                    'prompt_tokens'     => $pTokens,
                                    'completion_tokens' => $cTokens,
                                    'cost'              => $cost
                                ];
                                $chunk = $lastUsage;
                            }

                            // Отправляем клиенту только если он ещё на связи
                            if (!connection_aborted()) {
                                SseResponse::send($type, is_array($chunk) ? $chunk : ['chunk' => $chunk]);
                            }
                        },
                        $sessionPath
                    );

                    $generationSuccess = true;
                    $activeModel       = $currentModel;
                    break;

                } catch (\Throwable $aiErr) {
                    $lastException = $aiErr;
                    AppLogger::error('ai_service', "Сбой модели {$currentModel}", [
                        'session' => $session,
                        'attempt' => $attempt + 1,
                        'error'   => $aiErr->getMessage()
                    ]);

                    usleep((int)(pow(2, $attempt) * 300000));
                }
            }

            if (!$generationSuccess) {
                throw new \RuntimeException(
                    'Все модели из цепочки fallback недоступны. Последняя ошибка: ' .
                    ($lastException ? $lastException->getMessage() : 'Unknown error')
                );
            }
        }

        // ЭТАП 2: фиксация ответа ассистента
        $fullHistory = $this->commitAssistantMessage(
            $sessionPath,
            $historyFile,
            $fullHistory,
            $textAccumulator,
            $thinkingAccumulator,
            $lastUsage,
            $activeModel
        );
        $isAssistantCommitted = true;

        if (!connection_aborted()) {
            SseResponse::send('done', [
                'status'  => 'completed',
                'history' => $fullHistory
            ]);
        }

        // Фоновые задачи (словарь и т.п.)
        $cleanText = preg_replace('/(```thinking\r?\n.*?\r?\n```\r?\n*|<think>.*?<\/think>)/s', '', $textAccumulator);
        $cleanText = trim((string)$cleanText);

        if ($cleanText !== '') {
            $this->runPostResponseTasks($sessionPath, $message, $cleanText, $fullHistory);
        }

    } catch (\Throwable $e) {
        AppLogger::error('sse_stream', 'Ошибка при генерации', [
            'session' => $session,
            'error'   => $e->getMessage()
        ]);

        // Если модель успела что-то сгенерировать до ошибки, сохраняем это
        if (!$isAssistantCommitted && (!empty($textAccumulator) || !empty($thinkingAccumulator))) {
            $fullHistory = $this->commitAssistantMessage(
                $sessionPath,
                $historyFile,
                $fullHistory,
                $textAccumulator,
                $thinkingAccumulator,
                $lastUsage,
                $activeModel
            );
            $isAssistantCommitted = true;
        }

        if (!connection_aborted()) {
            SseResponse::send('error', [
                'message' => 'Ошибка при генерации: ' . $e->getMessage()
            ]);
        }
    } finally {
        $this->sessionService->setGeneratingStatus($sessionPath, false);
    }
}

    /**
     * Изолированный атомарный метод записи ответа ассистента в файл
     */
    protected function commitAssistantMessage(
        string $sessionPath,
        string $historyFile,
        array $fullHistory,
        string $textAccumulator,
        string $thinkingAccumulator,
        array $lastUsage,
        string $activeModel
    ): array {
        $cleanText = preg_replace('/(```thinking\r?\n.*?\r?\n```\r?\n*|<think>.*?<\/think>)/s', '', $textAccumulator);
        $cleanText = trim((string)$cleanText);

        // Если текста нет, но было мышление (или была ошибка)
        if ($cleanText === '' && empty($thinkingAccumulator)) {
            return $fullHistory;
        }

        $assistantMessage = [
            'role'      => 'assistant',
            'content'   => $cleanText,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if (!empty($thinkingAccumulator)) {
            $assistantMessage['reasoning_content'] = trim($thinkingAccumulator);
        }

        $fullHistory[] = $assistantMessage;
        $fullHistory = array_values($fullHistory);

        // Запись истории на диск
        $this->writeJson($historyFile, $fullHistory);

        // Обновление метаданных сессии
        $metaFile = $sessionPath . 'meta.json';
        $meta = $this->readJson($metaFile, []);
        
        if (!empty($activeModel)) {
            $meta['model'] = $activeModel;
        }
        if (!empty($lastUsage)) {
            $meta['last_usage'] = $lastUsage;
            $meta['last_cost'] = $lastUsage['cost'] ?? 0;
        }
        $meta['is_generating'] = false;
        $this->writeJson($metaFile, $meta);

        return $fullHistory;
    }

    protected function getModelFallbackChain(string $requestedModel): array
    {
        $chain = [$requestedModel];

        $fallbackMap = [
            'gemini-3.6-flash'   => ['gemini-3.7-flash', 'gemini-3.8-flash'],
            'claude-sonnet-5-5'  => ['kimi-k3'],
            'claude-haiku-5-5'   => ['kimi-k2.6'],
            'kimi-k3.1-pro'            => [],
            'kimi-k2.6'          => ['claude-haiku-5-5'],
            'claude-opus-5-5'    => [],
        ];

        if (isset($fallbackMap[$requestedModel])) {
            foreach ($fallbackMap[$requestedModel] as $fallback) {
                if (!in_array($fallback, $chain, true)) {
                    $chain[] = $fallback;
                }
            }
        } else {
            if (!in_array('gemini-3.5-flash-lite', $chain, true)) {
                $chain[] = 'gemini-3.5-flash-lite';
            }
        }

        return array_unique($chain);
    }

protected function runPostResponseTasks(
    string $sessionPath,
    string $message,
    string $cleanText,
    array $fullHistory
): void {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    ignore_user_abort(true);
    set_time_limit(180);

    // 🟢 ИСПРАВЛЕНИЕ: Проверяем, включен ли словарь для этого пресета
    $config = $this->readJson($this->presetPath . 'config.json', []);
    $hasDictionary = (bool)($config['has_dictionary'] ?? false);

    if ($hasDictionary && $this->dictionaryService !== null) {
        try {
            $this->dictionaryService->handleInteraction($message, $cleanText, $fullHistory);
        } catch (\Throwable $dictEx) {
            AppLogger::error('background', 'DictionaryService error: ' . $dictEx->getMessage(), [
                'session' => $sessionPath
            ]);
        }
    }

    try {
        $this->afterMessageSent($sessionPath);
    } catch (\Throwable $subEx) {
        AppLogger::error('background', 'Supervisor Error: ' . $subEx->getMessage(), [
            'file' => $subEx->getFile(),
            'line' => $subEx->getLine()
        ]);
    }
}



    protected function buildSystemContext(string $sessionPath, string $customSystemPrompt = '', string $summary = ''): string
{
    $config = $this->readJson($this->presetPath . 'config.json', []);
    $hasDictionary = (bool)($config['has_dictionary'] ?? false);

    $systemContext = !empty($customSystemPrompt)
        ? $customSystemPrompt
        : $this->getSystemPrompt($sessionPath);

    if ($hasDictionary && $this->dictionaryService !== null) {
        $smallDict = $this->dictionaryService->getDictionary();
        if (!empty($smallDict)) {
            $systemContext .= "\n\nАКТИВНЫЙ МАЛЫЙ СЛОВАРЬ (используй эти слова, правила и выражения в диалоге, проверяй их употребление. Можешь подталкивать ученика к их использованию):\n" .
                              json_encode($smallDict, JSON_UNESCAPED_UNICODE);
        }

        // 🟢 ИСПРАВЛЕНИЕ: Подтягиваем опечатки/целевые слова из typo_queue для подсказки модели
        $pendingTypos = $this->dictionaryService->getPendingTyposForPrompt();
        if (!empty($pendingTypos)) {
            $systemContext .= "\n\nЦЕЛЕВЫЕ СЛОВА/ОПЕЧАТКИ ДЛЯ ПРОВЕРКИ (мягко и естественно подталкивай ученика использовать их в диалоге, если уместно):\n" .
                              json_encode($pendingTypos, JSON_UNESCAPED_UNICODE);
        }
    }

    if (!empty($summary)) {
        $systemContext = "КРАТКАЯ ПРЕДЫСТОРИЯ ДИАЛОГА:\n" . $summary . "\n\n" . $systemContext;
    }

    if ($filesContext = $this->fileService->getSessionFilesContext($sessionPath)) {
        $systemContext .= "\n\nПрикреплённые файлы:\n" . $filesContext;
    }

    return $systemContext;
}

   protected function buildApiHistory(array $recentHistory): array
{
    return array_map(function ($m) {
        $item = [
            'role'    => (($m['role'] ?? '') === 'user') ? 'user' : 'assistant',
            'content' => (string)($m['content'] ?? '')
        ];

        // 🟢 ОЧЕНЬ ВАЖНО: протаскиваем timestamp дальше!
        if (!empty($m['timestamp'])) {
            $item['timestamp'] = $m['timestamp'];
        }

        if (!empty($m['reasoning_content']) && is_string($m['reasoning_content'])) {
            $item['reasoning_content'] = $m['reasoning_content'];
        }
        return $item;
    }, $recentHistory);
}


    public function watchSession(Request $request): void
    {
        SseResponse::init();

        $session = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $request->getString('session'));
        $sessionPath = $this->normalizeDir($this->presetPath . 'sessions' . DIRECTORY_SEPARATOR . $session);
        $historyFile = $sessionPath . 'chat_history.json';

        if (empty($session) || !is_dir($sessionPath)) {
            SseResponse::error('Сессия не найдена');
            return;
        }

        $lastMtime = file_exists($historyFile) ? filemtime($historyFile) : 0;
        $start = time();
        $maxDuration = 50; 

        while ((time() - $start) < $maxDuration) {
            if (connection_aborted()) {
                break;
            }

            clearstatcache(true, $historyFile);
            $currentMtime = file_exists($historyFile) ? filemtime($historyFile) : 0;

            if ($currentMtime > $lastMtime) {
                $lastMtime = $currentMtime;
                $history = $this->readJson($historyFile, []);
                
                SseResponse::send('history_updated', [
                    'history' => array_values($history)
                ]);
            }

            SseResponse::ping();
            sleep(1);
        }

        SseResponse::send('reconnect', ['status' => 'timeout']);
    }
    protected function limitHistoryForModel(array $history, array $config): array
{
    $hasDictionary = (bool)($config['has_dictionary'] ?? false);
    if (!$hasDictionary) {
        return $history;
    }

    $limit = (int)($config['history_limit'] ?? self::DICTIONARY_HISTORY_LIMIT);
    if ($limit <= 0 || count($history) <= $limit) {
        return $history;
    }

    $limited = array_slice($history, -$limit);

    // Диалог должен начинаться с сообщения пользователя
    if (($limited[0]['role'] ?? '') !== 'user') {
        array_shift($limited);
    }

    return array_values($limited);
}

}