<?php

declare(strict_types=1);

class ContextManager {
    private string $sessionPath;
    private int $chunkSize;
    private int $tailLimit;

    public function __construct(string $sessionPath, int $chunkSize = 6, int $tailLimit = 4) {
        $this->sessionPath = rtrim($sessionPath, '/\\') . DIRECTORY_SEPARATOR;
        $this->chunkSize = max(2, $chunkSize);
        $this->tailLimit = max(1, $tailLimit);
    }

    public function getOptimizedContext(array $fullHistory): array {
        $memory = $this->loadMemory();
        $totalMessages = count($fullHistory);

        $summaryText = "";
        if (!empty($memory['blocks'])) {
            foreach ($memory['blocks'] as $block) {
                if (!empty($block['text'])) {
                    $summaryText .= "- " . $block['text'] . "\n";
                }
            }
        }

        $tailHistory = array_slice($fullHistory, max(0, $totalMessages - $this->tailLimit));

        return [
            'summary' => trim($summaryText),
            'recent_history' => $tailHistory
        ];
    }

    public function compressBackgroundChunks(array $fullHistory, string $model, callable $aiCallback): void {
        $memory = $this->loadMemory();
        $processedCount = (int)($memory['processed_count'] ?? 0);
        $totalMessages = count($fullHistory);

        while (($totalMessages - $processedCount) >= $this->chunkSize) {
            $chunkToCompress = array_slice($fullHistory, $processedCount, $this->chunkSize);

            $textToSummarize = "";
            foreach ($chunkToCompress as $msg) {
                $role = (($msg['role'] ?? '') === 'user') ? 'Пользователь' : 'ИИ';
                $content = strip_tags((string)($msg['content'] ?? ''));
                $textToSummarize .= "{$role}: {$content}\n";
            }

            $prompt = "Выдели ключевую суть и важные факты из этого фрагмента диалога (3-5 предложений):\n\n" . $textToSummarize;
            
            $summary = $aiCallback(
                $model, 
                "Ты — краткий ассистент архивариус. Выдавай ТОЛЬКО итоговое саммари. Категорически запрещено использовать теги <think> и блоки ```thinking```.", 
                [['role' => 'user', 'content' => $prompt]]
            );

            if (!empty($summary) && is_string($summary)) {
                $cleanSummary = preg_replace('/```thinking\n.*?\n```\n\n?/s', '', $summary);
                $cleanSummary = preg_replace('/<think>.*?<\/think>\n\n?/s', '', $cleanSummary);
                $cleanSummary = preg_replace('/<think>.*?$/s', '', $cleanSummary);
                $cleanSummary = trim($cleanSummary);

                if ($cleanSummary !== '') {
                    $memory['blocks'][] = [
                        'range' => ($processedCount + 1) . '-' . ($processedCount + $this->chunkSize),
                        'text' => $cleanSummary,
                        'created_at' => date('Y-m-d H:i:s')
                    ];

                    $processedCount += $this->chunkSize;
                    $memory['processed_count'] = $processedCount;
                    $this->saveMemory($memory);
                } else {
                    break;
                }
            } else {
                break;
            }
        }
    }

    private function loadMemory(): array {
        return JsonStorage::read($this->sessionPath . 'context_memory.json', [
            'processed_count' => 0, 
            'blocks' => []
        ]);
    }

    private function saveMemory(array $memory): void {
        JsonStorage::write($this->sessionPath . 'context_memory.json', $memory);
    }
}