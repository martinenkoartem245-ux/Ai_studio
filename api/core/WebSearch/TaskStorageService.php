<?php
declare(strict_types=1);

class TaskStorageService {
    private static function getStoragePath(): string {
        return __DIR__ . '/data/scheduled_tasks.json';
    }

    /**
     * Создание новой отложенной задачи
     */
    public static function createTask(
        string $sessionPath, 
        string $preset, 
        string $prompt, 
        int $executeAt, 
        string $priority = 'fast'
    ): array {
        $filePath = self::getStoragePath();
        $tasks = JsonStorage::read($filePath, []);

        $taskId = 'task_' . uniqid('', true);
        
        $allowedPriorities = ['strategic', 'important', 'minor', 'fast'];
        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'fast';
        }

        $newTask = [
            'id'           => $taskId,
            'session_path' => $sessionPath,
            'preset'       => $preset,
            'prompt'       => $prompt,
            'priority'     => $priority,
            'execute_at'   => $executeAt,
            'created_at'   => time(),
            'status'       => 'pending', // pending, processing, completed, failed, cancelled
        ];

        $tasks[$taskId] = $newTask;
        JsonStorage::write($filePath, $tasks);

        return $newTask;
    }

    /**
     * Получить список задач для конкретной сессии
     */
    public static function listTasks(string $sessionPath, ?string $status = null): array {
        $tasks = JsonStorage::read(self::getStoragePath(), []);
        $result = [];

        foreach ($tasks as $task) {
            if (($task['session_path'] ?? '') === $sessionPath) {
                if ($status === null || ($task['status'] ?? '') === $status) {
                    $result[] = [
                        'id'         => $task['id'],
                        'prompt'     => $task['prompt'],
                        'priority'   => $task['priority'],
                        'status'     => $task['status'],
                        'execute_at' => date('Y-m-d H:i:s', $task['execute_at']),
                        'created_at' => date('Y-m-d H:i:s', $task['created_at']),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Отмена задачи по ее ID в рамках конкретной сессии
     */
    public static function cancelTask(string $sessionPath, string $taskId): bool {
        $filePath = self::getStoragePath();
        $tasks = JsonStorage::read($filePath, []);

        if (!isset($tasks[$taskId])) {
            return false;
        }

        // Проверяем принадлежность задачи текущей сессии
        if (($tasks[$taskId]['session_path'] ?? '') !== $sessionPath) {
            return false;
        }

        $tasks[$taskId]['status'] = 'cancelled';
        return JsonStorage::write($filePath, $tasks);
    }

    /**
     * Получить все готовые к выполнению задачи (для Воркера)
     */
    public static function getDueTasks(): array {
        $tasks = JsonStorage::read(self::getStoragePath(), []);
        $now = time();
        $due = [];

        foreach ($tasks as $task) {
            if (($task['status'] ?? '') === 'pending' && ($task['execute_at'] ?? 0) <= $now) {
                $due[] = $task;
            }
        }

        return $due;
    }

    /**
     * Обновить статус задачи
     */
    public static function updateTaskStatus(string $taskId, string $status, ?string $error = null): bool {
        $filePath = self::getStoragePath();
        $tasks = JsonStorage::read($filePath, []);

        if (!isset($tasks[$taskId])) {
            return false;
        }

        $tasks[$taskId]['status'] = $status;
        $tasks[$taskId]['updated_at'] = time();
        if ($error !== null) {
            $tasks[$taskId]['error'] = $error;
        }

        return JsonStorage::write($filePath, $tasks);
    }
}
