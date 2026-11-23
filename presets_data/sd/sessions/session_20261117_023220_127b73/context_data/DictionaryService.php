<?php

declare(strict_types=1);

class DictionaryService 
{
    private PDO $db;
    private string $languageCode;
    private ?object $aiService;
    private string $supervisorLogFile;

    public function __construct(
        PDO $db, 
        string $languageCode = 'en',
        ?object $aiService = null, 
        string $supervisorLogFile = __DIR__ . '/latest_supervisor_response.json'
    ) {
        $this->db = $db;
        $this->languageCode = in_array(strtolower($languageCode), ['en', 'pl'], true) ? strtolower($languageCode) : 'en';
        $this->aiService = $aiService ?? (class_exists('AiService') ? new AiService() : null);
        $this->supervisorLogFile = $supervisorLogFile;
    }

    public function setLanguageCode(string $languageCode): self 
    {
        $this->languageCode = in_array(strtolower($languageCode), ['en', 'pl'], true) ? strtolower($languageCode) : 'en';
        return $this;
    }

    public function getDictionary(): array 
    {
        try {
            $stmt = $this->db->prepare("
                SELECT dm.id, dm.term, dm.category, dm.item_type, ds.score 
                FROM dictionary_small ds
                JOIN dictionary_master dm ON ds.master_id = dm.id
                WHERE dm.language_code = :lang
                ORDER BY ds.id ASC
            ");
            $stmt->execute([':lang' => $this->languageCode]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->logError('getDictionary Error', $e);
            return [];
        }
    }

    public function handleInteraction(string $userMessage, string $lightModelResponse, array $recentHistory = []): array 
    {
        try {
            // Атомарный Upsert счетчика сообщений под конкретный язык
            $stmtMetaUpdate = $this->db->prepare("
                INSERT INTO user_meta (language_code, user_message_count, supervisor_run_count)
                VALUES (:lang, 1, 0)
                ON DUPLICATE KEY UPDATE user_message_count = user_message_count + 1
            ");
            $stmtMetaUpdate->execute([':lang' => $this->languageCode]);

            $stmtMetaSelect = $this->db->prepare("
                SELECT user_message_count, supervisor_run_count 
                FROM user_meta 
                WHERE language_code = :lang
            ");
            $stmtMetaSelect->execute([':lang' => $this->languageCode]);
            $meta = $stmtMetaSelect->fetch(PDO::FETCH_ASSOC);
            
            $userMsgCount = (int)($meta['user_message_count'] ?? 0);
            $supervisorRunCount = (int)($meta['supervisor_run_count'] ?? 0);

            // 1. Проверяем опечатки из карантина Active Recall
            $this->processTypoQueue($userMsgCount, $userMessage);

            $supervisorTriggered = false;

            // 2. Каждые 10 сообщений запускаем супервайзер
            if ($userMsgCount > 0 && $userMsgCount % 10 === 0) {
                $supervisorRunCount++;
                $stmt = $this->db->prepare("
                    UPDATE user_meta 
                    SET supervisor_run_count = :cnt 
                    WHERE language_code = :lang
                ");
                $stmt->execute([':cnt' => $supervisorRunCount, ':lang' => $this->languageCode]);
                
                $this->runSupervisorCycle($supervisorRunCount, $recentHistory);
                $supervisorTriggered = true;
            }

            $this->rebuildSmallDictionary();

            return [
                'ai_response' => $lightModelResponse,
                'supervisor_run' => $supervisorTriggered,
                'message_count' => $userMsgCount
            ];
        } catch (\Throwable $e) {
            $this->logError('handleInteraction Error', $e);
            return ['ai_response' => $lightModelResponse, 'supervisor_run' => false, 'message_count' => 0];
        }
    }

    public function getPendingTyposForPrompt(): array 
    {
        try {
            $stmtCount = $this->db->prepare("SELECT user_message_count FROM user_meta WHERE language_code = :lang");
            $stmtCount->execute([':lang' => $this->languageCode]);
            $currentMsgCount = (int)$stmtCount->fetchColumn();
            
            $stmt = $this->db->prepare("
                SELECT expected_word 
                FROM typo_queue 
                WHERE language_code = :lang AND check_at_message <= :next_msg
            ");
            $stmt->execute([
                ':lang'     => $this->languageCode,
                ':next_msg' => $currentMsgCount + 1
            ]);
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->logError('getPendingTyposForPrompt failed', $e);
            return [];
        }
    }

    public function rebuildSmallDictionary(): void 
    {
        try {
            $stmtCount = $this->db->prepare("
                SELECT COUNT(*) 
                FROM dictionary_small ds
                JOIN dictionary_master dm ON ds.master_id = dm.id
                WHERE dm.language_code = :lang
            ");
            $stmtCount->execute([':lang' => $this->languageCode]);
            $currentCount = (int)$stmtCount->fetchColumn();

            if ($currentCount >= 10) {
                return;
            }

            $needed = 10 - $currentCount;

            // 1. Извлекаем слова со статусом 'new', которых еще нет в dictionary_small
            $stmt = $this->db->prepare("
                SELECT dm.id 
                FROM dictionary_master dm
                LEFT JOIN dictionary_small ds ON dm.id = ds.master_id
                WHERE dm.language_code = :lang 
                  AND dm.status = 'new' 
                  AND ds.master_id IS NULL
                ORDER BY dm.id ASC 
                LIMIT :limit
            ");
            $stmt->bindValue(':lang', $this->languageCode);
            $stmt->bindValue(':limit', $needed, PDO::PARAM_INT);
            $stmt->execute();
            $newWords = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($newWords)) {
                $idsToAdd = array_map('intval', $newWords);
                $this->addToSmallDict($idsToAdd);
                $needed -= count($idsToAdd);
            }

            // 2. Если все еще нужен добор, извлекаем раннее практиковавшиеся слова (status != 'mastered')
            if ($needed > 0) {
                $stmt = $this->db->prepare("
                    SELECT dm.id, dm.term, dm.last_practiced_at 
                    FROM dictionary_master dm
                    LEFT JOIN dictionary_small ds ON dm.id = ds.master_id
                    WHERE dm.language_code = :lang 
                      AND dm.status != 'mastered' 
                      AND ds.master_id IS NULL
                    ORDER BY dm.last_practiced_at IS NULL DESC, dm.last_practiced_at ASC
                    LIMIT 20
                ");
                $stmt->execute([':lang' => $this->languageCode]);
                $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($candidates)) {
                    $selectedIds = $this->selectOldestPracticedViaAi($candidates, $needed);
                    if (!empty($selectedIds)) {
                        $this->addToSmallDict($selectedIds);
                        $needed -= count($selectedIds);
                    }
                }
            }

            // 3. Если в master не хватило словаря — генерируем бизнес-лексику
            if ($needed > 0) {
                $this->generateAndInjectVocabulary($needed);
            }
        } catch (\Throwable $e) {
            $this->logError('rebuildSmallDictionary Error', $e);
        }
    }

    public function addWord(string $word, string $category = 'B1_B2'): bool 
    {
        try {
            $allowedCategories = ['B1_B2', 'C1_PLUS', 'GRAMMAR', 'BUSINESS'];
            if (!in_array($category, $allowedCategories, true)) {
                $category = 'B1_B2';
            }

            $itemType = ($category === 'GRAMMAR' || str_contains($word, ':') || str_contains($word, '(')) 
                ? 'grammar_rule' 
                : 'word';

            // ИСПРАВЛЕНИЕ: Не сбрасываем статус mastered на new
            $stmt = $this->db->prepare("
                INSERT INTO dictionary_master (language_code, item_type, term, category, status) 
                VALUES (:lang, :item_type, :term, :category, 'new')
                ON DUPLICATE KEY UPDATE status = IF(status = 'mastered', status, 'new')
            ");
            $stmt->execute([
                ':lang'      => $this->languageCode,
                ':item_type' => $itemType,
                ':term'      => trim($word),
                ':category'  => $category
            ]);
            $this->rebuildSmallDictionary();
            return true;
        } catch (\Throwable $e) {
            $this->logError('addWord Error', $e);
            return false;
        }
    }

    private function addToSmallDict(array $masterIds): void 
    {
        if (empty($masterIds)) return;
        
        $wasInTransaction = $this->db->inTransaction();
        if (!$wasInTransaction) {
            $this->db->beginTransaction();
        }
        
        try {
            $stmtSmall = $this->db->prepare("INSERT IGNORE INTO dictionary_small (master_id, score) VALUES (:master_id, 0)");
            $stmtMaster = $this->db->prepare("UPDATE dictionary_master SET status = 'learning', last_practiced_at = NOW() WHERE id = :id");

            foreach ($masterIds as $id) {
                $idInt = (int)$id;
                $stmtSmall->execute([':master_id' => $idInt]);
                $stmtMaster->execute([':id' => $idInt]);
            }
            
            if (!$wasInTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if (!$wasInTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logError('addToSmallDict failed', $e);
        }
    }

    private function selectOldestPracticedViaAi(array $candidates, int $needed): array 
    {
        $validCandidateIds = array_map('intval', array_column($candidates, 'id'));

        if (!$this->aiService) {
            return array_slice($validCandidateIds, 0, $needed);
        }

        $prompt = "From the following list, select exactly {$needed} terms/rules most appropriate for review in " . strtoupper($this->languageCode) . " language study (return array of IDs):\n" 
                  . json_encode($candidates, JSON_UNESCAPED_UNICODE) . "\n"
                  . "Respond ONLY with a strict JSON array of IDs: [1, 5, 12]";

        try {
            $response = $this->callClaudeSonnetThinkingModel("You are a language learning selector.", $prompt);
            $clean = $this->cleanJsonOutput($response);
            $decoded = json_decode($clean, true);

            if (is_array($decoded)) {
                $extractedIds = array_filter(array_map('intval', $decoded), fn($id) => $id > 0);
                $filteredIds = array_values(array_intersect($extractedIds, $validCandidateIds));
                
                if (!empty($filteredIds)) {
                    return array_slice($filteredIds, 0, $needed);
                }
            }
        } catch (\Throwable $e) {
            $this->logError('selectOldestPracticedViaAi failed', $e);
        }

        return array_slice($validCandidateIds, 0, $needed);
    }

    private function generateAndInjectVocabulary(int $count): void 
    {
        if ($count <= 0) return;

        // 1. Извлекаем все имеющиеся термины
        $stmtExisting = $this->db->prepare("SELECT LOWER(term) FROM dictionary_master WHERE language_code = :lang");
        $stmtExisting->execute([':lang' => $this->languageCode]);
        $existingTerms = array_fill_keys($stmtExisting->fetchAll(PDO::FETCH_COLUMN) ?: [], true);

        $candidateWords = [];
        $langUpper = strtoupper($this->languageCode);

        // 2. Генерация терминов ИИ
        if ($this->aiService) {
            $prompt = "Generate {$count} unique actual {$langUpper} language expressions or business/work vocabulary (B2/C1 level). " .
                      "Return a STRICT JSON array of strings: [\"term1\", \"term2\"]";
            try {
                $response = $this->callClaudeSonnetThinkingModel("You are a professional vocabulary generator.", $prompt);
                $clean = $this->cleanJsonOutput($response);
                $decoded = json_decode($clean, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $word) {
                        $term = trim((string)$word);
                        if ($term !== '' && !isset($existingTerms[mb_strtolower($term)])) {
                            $candidateWords[] = $term;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logError('Vocabulary generation failed', $e);
            }
        }

        // 3. Фолбэк список при необходимости
        if (count($candidateWords) < $count) {
            $fallbackEn = [
                'work schedule', 'business meeting', 'action item', 'time management', 
                'project scope', 'weekly report', 'deadline extension', 'meeting summary', 
                'terms of agreement', 'budget allocation', 'performance metrics', 'key deliverables', 
                'stakeholder update', 'risk mitigation', 'process optimization', 'resource planning'
            ];

            $fallbackPl = [
                'harmonogram pracy', 'spotkanie biznesowe', 'potwierdzenie odbioru', 'zgodnie z umową', 
                'zakres projektu', 'raport tygodniowy', 'termin realizacji', 'podsumowanie spotkania', 
                'warunki współpracy', 'akceptacja budżetu', 'przełożony', 'zgłoszenie problemu', 
                'weryfikacja danych', 'optymalizacja procesów', 'kluczowe wskaźniki', 'porządek obrad', 
                'zarządzanie czasem', 'odpowiedzialność za zadanie', 'planowanie strategiczne'
            ];

            $fallbackList = $this->languageCode === 'pl' ? $fallbackPl : $fallbackEn;

            foreach ($fallbackList as $fbTerm) {
                if (count($candidateWords) >= $count) {
                    break;
                }
                $cleanFb = trim($fbTerm);
                if (!isset($existingTerms[mb_strtolower($cleanFb)]) && !in_array($cleanFb, $candidateWords, true)) {
                    $candidateWords[] = $cleanFb;
                }
            }
        }

        // ИСПРАВЛЕНИЕ: Ограничиваем точное количество нужных слов
        $candidateWords = array_slice($candidateWords, 0, $count);

        if (empty($candidateWords)) {
            return;
        }

        // 4. Вставка с сохраннием mastered
        try {
            $stmtMaster = $this->db->prepare("
                INSERT INTO dictionary_master (language_code, item_type, term, category, status) 
                VALUES (:lang, 'word', :term, 'BUSINESS', 'learning') 
                ON DUPLICATE KEY UPDATE status = IF(status = 'mastered', status, 'learning')
            ");
            $stmtGetId = $this->db->prepare("SELECT id FROM dictionary_master WHERE language_code = :lang AND term = :term");
            $stmtSmall = $this->db->prepare("INSERT IGNORE INTO dictionary_small (master_id, score) VALUES (:master_id, 0)");

            foreach ($candidateWords as $word) {
                $cleanTerm = trim((string)$word);
                if ($cleanTerm === '') continue;

                $stmtMaster->execute([':lang' => $this->languageCode, ':term' => $cleanTerm]);
                
                $stmtGetId->execute([':lang' => $this->languageCode, ':term' => $cleanTerm]);
                $masterId = (int)$stmtGetId->fetchColumn();

                if ($masterId > 0) {
                    $stmtSmall->execute([':master_id' => $masterId]);
                }
            }
        } catch (\Throwable $e) {
            $this->logError('Vocabulary injection failed', $e);
        }
    }

    private function runSupervisorCycle(int $supervisorRunCount, array $recentHistory = []): void 
    {
        $dialogRounds = [];
        $totalMessages = count($recentHistory);

        for ($i = 0; $i < $totalMessages; $i++) {
            if (($recentHistory[$i]['role'] ?? '') === 'user') {
                $prevAiPrompt = ($i > 0 && ($recentHistory[$i - 1]['role'] ?? '') === 'assistant') 
                    ? $recentHistory[$i - 1]['content'] 
                    : 'No context';

                $dialogRounds[] = [
                    'ai_prompt'     => $prevAiPrompt,
                    'user_response' => $recentHistory[$i]['content'] ?? ''
                ];
            }
        }

        $last10Rounds = array_slice($dialogRounds, -10);
        if (empty($last10Rounds)) return;

        $stmtSmall = $this->db->prepare("
            SELECT ds.id AS small_id, dm.id AS master_id, dm.term, dm.item_type, ds.score 
            FROM dictionary_small ds 
            JOIN dictionary_master dm ON ds.master_id = dm.id
            WHERE dm.language_code = :lang
        ");
        $stmtSmall->execute([':lang' => $this->languageCode]);
        $smallDict = $stmtSmall->fetchAll(PDO::FETCH_ASSOC);

        $supervisorPrompt = "Analyze the last 10 dialog rounds of the student learning " . strtoupper($this->languageCode) . ".\n" .
            "Each round contains 'ai_prompt' and 'user_response'.\n\n" .
            "TASKS:\n" .
            "1. Evaluate terms/rules from the Small Dictionary used by the student in 'user_response'. " .
            "Return the 'small_id', 'term', 'correct_count' (+1 point each), and 'incorrect_count' (-1 point each).\n" .
            "2. Extract real grammar, syntax, and lexical errors. KEEP 'term' STRICTLY AS THE ERROR PATTERN/PHRASE WITHOUT EXPLANATIONS OR COMMENTS.\n" .
            "3. DETECT UNKNOWN WORDS: Find user requests to add words or statements indicating they don't know a word. Extract these as clean terms.\n\n" .
            "Current Small Dictionary: " . json_encode($smallDict, JSON_UNESCAPED_UNICODE) . "\n\n" .
            "Return STRICT JSON with structure:\n" .
            "{\n" .
            "  \"evaluations\": [{\"small_id\": 1, \"term\": \"word\", \"correct_count\": 2, \"incorrect_count\": 0}],\n" .
            "  \"errors\": [{\"term\": \"clean error term/pattern\", \"sentence\": \"context sentence\"}],\n" .
            "  \"explicit_unknown_words\": [\"word1\", \"word2\"]\n" .
            "}";

        try {
            $responseRaw = $this->callClaudeSonnetThinkingModel(
                "You are a strict language supervisor for " . strtoupper($this->languageCode) . " language.", 
                json_encode($last10Rounds, JSON_UNESCAPED_UNICODE) . "\n\n" . $supervisorPrompt
            );

            $cleanedJson = $this->cleanJsonOutput($responseRaw);
            $result = json_decode($cleanedJson, true);

            $logPayload = [
                'updated_at'    => date('Y-m-d H:i:s'),
                'language_code' => $this->languageCode,
                'run_count'     => $supervisorRunCount,
                'is_valid_json' => is_array($result),
                'raw_response'  => $responseRaw,
                'parsed_data'   => $result
            ];
            @file_put_contents(
                $this->supervisorLogFile, 
                json_encode($logPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 
                LOCK_EX
            );

            if (is_array($result)) {
                if (!empty($result['evaluations']) && is_array($result['evaluations'])) {
                    $this->applyScoresAndManageMastery($result['evaluations']);
                }

                if (!empty($result['errors']) && is_array($result['errors'])) {
                    $stmtError = $this->db->prepare("
                        INSERT INTO error_candidates (language_code, term, context_sentence, occurrence_count, last_seen_at) 
                        VALUES (:lang, :term, :sentence, 1, NOW())
                        ON DUPLICATE KEY UPDATE 
                            occurrence_count = occurrence_count + 1, 
                            context_sentence = VALUES(context_sentence),
                            last_seen_at = NOW()
                    ");

                    foreach ($result['errors'] as $err) {
                        $term = '';
                        $sentence = '';

                        if (is_array($err)) {
                            $term = $err['term'] ?? $err['error'] ?? $err['word'] ?? '';
                            $sentence = $err['sentence'] ?? $err['context'] ?? $err['context_sentence'] ?? $term;
                        } elseif (is_string($err)) {
                            $term = $err;
                            $sentence = $err;
                        }

                        $term = trim((string)$term);
                        $sentence = trim((string)$sentence);

                        if ($term !== '') {
                            $stmtError->execute([
                                ':lang'     => $this->languageCode,
                                ':term'     => $term, 
                                ':sentence' => $sentence !== '' ? $sentence : $term
                            ]);
                        }
                    }
                }

                if (!empty($result['explicit_unknown_words']) && is_array($result['explicit_unknown_words'])) {
                    foreach ($result['explicit_unknown_words'] as $word) {
                        if (is_string($word) && trim($word) !== '') {
                            $this->addWord(trim($word), 'B1_B2');
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logError('Supervisor error', $e);
        }

        if ($supervisorRunCount % 3 === 0) {
            $this->analyzePersistentErrorsAndRules();
        }
    }

    private function applyScoresAndManageMastery(array $evaluations): void 
    {
        $stmtUpdateScore = $this->db->prepare("UPDATE dictionary_small SET score = :score WHERE id = :id");
        $stmtSelectByTerm = $this->db->prepare("
            SELECT ds.id, ds.score, ds.master_id 
            FROM dictionary_small ds
            JOIN dictionary_master dm ON ds.master_id = dm.id
            WHERE dm.language_code = :lang AND LOWER(dm.term) = LOWER(:term)
        ");

        foreach ($evaluations as $eval) {
            if (!is_array($eval)) continue;

            $smallId = (int)($eval['small_id'] ?? 0);
            $term = mb_strtolower(trim((string)($eval['term'] ?? '')), 'UTF-8');

            $correctCount = max(0, (int)($eval['correct_count'] ?? 0));
            $incorrectCount = max(0, (int)($eval['incorrect_count'] ?? 0));
            $delta = $correctCount - $incorrectCount;

            if ($delta === 0) continue;

            $item = null;
            if ($smallId > 0) {
                $stmtCheck = $this->db->prepare("SELECT id, score, master_id FROM dictionary_small WHERE id = :id");
                $stmtCheck->execute([':id' => $smallId]);
                $item = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            }

            if (!$item && $term !== '') {
                $stmtSelectByTerm->execute([':lang' => $this->languageCode, ':term' => $term]);
                $item = $stmtSelectByTerm->fetch(PDO::FETCH_ASSOC);
            }

            if ($item) {
                $newScore = max(0, (int)$item['score'] + $delta);
                $stmtUpdateScore->execute([':score' => $newScore, ':id' => $item['id']]);
            }
        }

        // 🟢 ИСПРАВЛЕНИЕ: Мгновенно архивируем слова со score >= 10 по одному, не дожидаясь кучи из 8 штук
        try {
            $stmtMasteredItems = $this->db->prepare("
                SELECT ds.id, ds.master_id 
                FROM dictionary_small ds
                JOIN dictionary_master dm ON ds.master_id = dm.id
                WHERE dm.language_code = :lang AND ds.score >= 10
            ");
            $stmtMasteredItems->execute([':lang' => $this->languageCode]);
            $masteredItems = $stmtMasteredItems->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($masteredItems)) {
                $wasInTransaction = $this->db->inTransaction();
                if (!$wasInTransaction) {
                    $this->db->beginTransaction();
                }

                $stmtMaster = $this->db->prepare("UPDATE dictionary_master SET status = 'mastered', mastered_at = NOW() WHERE id = :master_id");
                $stmtDelete = $this->db->prepare("DELETE FROM dictionary_small WHERE id = :id");

                foreach ($masteredItems as $mItem) {
                    $stmtMaster->execute([':master_id' => $mItem['master_id']]);
                    $stmtDelete->execute([':id' => $mItem['id']]);
                }

                if (!$wasInTransaction && $this->db->inTransaction()) {
                    $this->db->commit();
                }

                $this->rebuildSmallDictionary();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logError('Instant mastery update failed', $e);
        }
    }


    private function analyzePersistentErrorsAndRules(): void 
    {
        try {
            $stmtCand = $this->db->prepare("
                SELECT id, term, context_sentence 
                FROM error_candidates 
                WHERE language_code = :lang
                ORDER BY id ASC 
                LIMIT 50
            ");
            $stmtCand->execute([':lang' => $this->languageCode]);
            $candidates = $stmtCand->fetchAll(PDO::FETCH_ASSOC);

            if (count($candidates) < 3) {
                return;
            }

            $prompt = "Analyze the following list of student errors in " . strtoupper($this->languageCode) . " (with IDs):\n" . 
                      json_encode($candidates, JSON_UNESCAPED_UNICODE) . "\n\n" .
                      "TASKS:\n" .
                      "1. Identify repeating or systemic GRAMMAR patterns.\n" .
                      "2. Identify clear VOCABULARY/IDIOM mistakes.\n" .
                      "3. Identify suspected TYPOS.\n" .
                      "4. Return ALL IDs of errors that were successfully categorized or identified as typos so they can be deleted.\n\n" .
                      "Return STRICT JSON format:\n" .
                      "{\n" .
                      "  \"processed_ids\": [38, 39, 41],\n" .
                      "  \"grammar_rules\": [\"Rule description\"],\n" .
                      "  \"vocabulary\": [\"correct phrase\"],\n" .
                      "  \"typos\": [\"word\"]\n" .
                      "}";

            $responseRaw = $this->callClaudeSonnetThinkingModel("You are an expert linguistic error analyst.", $prompt);
            $cleanJson = $this->cleanJsonOutput($responseRaw);
            $result = json_decode($cleanJson, true);

            if (!is_array($result)) return;

            // ИСПРАВЛЕНИЕ: Предотвращаем сброс mastered
            $stmtInsertMaster = $this->db->prepare("
                INSERT INTO dictionary_master (language_code, item_type, term, category, status) 
                VALUES (:lang, :item_type, :term, :category, 'new')
                ON DUPLICATE KEY UPDATE status = IF(status = 'mastered', status, 'new')
            ");

            if (!empty($result['grammar_rules']) && is_array($result['grammar_rules'])) {
                foreach ($result['grammar_rules'] as $rule) {
                    $ruleStr = trim((string)$rule);
                    if ($ruleStr !== '') {
                        $stmtInsertMaster->execute([
                            ':lang'      => $this->languageCode,
                            ':item_type' => 'grammar_rule',
                            ':term'      => $ruleStr, 
                            ':category'  => 'GRAMMAR'
                        ]);
                    }
                }
            }

            if (!empty($result['vocabulary']) && is_array($result['vocabulary'])) {
                foreach ($result['vocabulary'] as $word) {
                    $wordStr = trim((string)$word);
                    if ($wordStr !== '') {
                        $stmtInsertMaster->execute([
                            ':lang'      => $this->languageCode,
                            ':item_type' => 'word',
                            ':term'      => $wordStr, 
                            ':category'  => 'BUSINESS'
                        ]);
                    }
                }
            }

            if (!empty($result['typos']) && is_array($result['typos'])) {
                $stmtCount = $this->db->prepare("SELECT user_message_count FROM user_meta WHERE language_code = :lang");
                $stmtCount->execute([':lang' => $this->languageCode]);
                $currentMsgCount = (int)$stmtCount->fetchColumn();
                
                $stmtTypo = $this->db->prepare("
                    INSERT INTO typo_queue (language_code, expected_word, check_at_message) 
                    VALUES (:lang, :word, :check_at)
                    ON DUPLICATE KEY UPDATE check_at_message = VALUES(check_at_message)
                ");

                foreach ($result['typos'] as $typo) {
                    $typoStr = trim((string)$typo);
                    if ($typoStr !== '') {
                        $randomOffset = rand(4, 8);
                        $stmtTypo->execute([
                            ':lang'     => $this->languageCode,
                            ':word'     => $typoStr, 
                            ':check_at' => $currentMsgCount + $randomOffset
                        ]);
                    }
                }
            }

               if (!empty($result['processed_ids']) && is_array($result['processed_ids'])) {
                $cleanIds = array_values(array_filter(array_map('intval', $result['processed_ids']), fn($id) => $id > 0));
                if (!empty($cleanIds)) {
                    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                    $stmtDelete = $this->db->prepare("DELETE FROM error_candidates WHERE language_code = ? AND id IN ($placeholders)");
                    $stmtDelete->execute(array_merge([$this->languageCode], $cleanIds));
                }
            }


            $this->rebuildSmallDictionary();

        } catch (\Throwable $e) {
            $this->logError('Rules analysis failed', $e);
        }
    }

    private function processTypoQueue(int $currentMsgCount, string $userMessage): void 
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, expected_word 
                FROM typo_queue 
                WHERE language_code = :lang AND check_at_message <= :msg
            ");
            $stmt->execute([':lang' => $this->languageCode, ':msg' => $currentMsgCount]);
            $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($pending)) return;

            $stmtDelete = $this->db->prepare("DELETE FROM typo_queue WHERE id = :id");

            foreach ($pending as $item) {
                $word = trim((string)$item['expected_word']);
                $id = (int)$item['id'];

                if ($word === '') {
                    $stmtDelete->execute([':id' => $id]);
                    continue;
                }

                $quotedWord = preg_quote($word, '/');
                $pattern = '/(?<=^|[^\p{L}\p{N}])' . $quotedWord . '(?=$|[^\p{L}\p{N}])/ui';

                if (preg_match($pattern, $userMessage) === 1) {
                    $stmtDelete->execute([':id' => $id]);
                } else {
                    $this->addWord($word, 'B1_B2');
                    $stmtDelete->execute([':id' => $id]);
                }
            }
        } catch (\Throwable $e) {
            $this->logError('processTypoQueue failed', $e);
        }
    }

    private function callClaudeSonnetThinkingModel(string $systemPrompt, string $userPayload): string 
    {
        if (!$this->aiService || !method_exists($this->aiService, 'streamModelResponse')) {
            return '';
        }

        $accumulator = '';
        $this->aiService->streamModelResponse(
            'claude-sonnet-5-5',
            $systemPrompt,
            [['role' => 'user', 'content' => $userPayload]],
            [
                'enable_thinking' => '1',
                'thinking_level' => 'high',
            ],
            function($type, $chunk) use (&$accumulator) {
                if ($type === 'text' && is_string($chunk)) {
                    $accumulator .= $chunk;
                }
            },
            '' // $sessionPath параметр
        );

        return $accumulator;
    }

    private function cleanJsonOutput(string $text): string 
    {
        $clean = preg_replace('/<think>.*?<\/think>/s', '', $text);
        $clean = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/', '$1', $clean);
        
        $jsonStart = strpos($clean, '[');
        $jsonAssocStart = strpos($clean, '{');
        
        if ($jsonStart !== false && ($jsonAssocStart === false || $jsonStart < $jsonAssocStart)) {
            $lastPos = strrpos($clean, ']');
            if ($lastPos !== false) {
                return substr($clean, $jsonStart, $lastPos - $jsonStart + 1);
            }
        } elseif ($jsonAssocStart !== false) {
            $lastPos = strrpos($clean, '}');
            if ($lastPos !== false) {
                return substr($clean, $jsonAssocStart, $lastPos - $jsonAssocStart + 1);
            }
        }
        return trim($clean);
    }

    private function logError(string $message, \Throwable $e): void
    {
        if (class_exists('AppLogger')) {
            AppLogger::error('background', "DictionaryService: {$message} - " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        } else {
            error_log("[DictionaryService {$message}] " . $e->getMessage());
        }
    }

    public function getDictionaryData(): array 
    {
        try {
            $stmtMaster = $this->db->prepare("
                SELECT id, item_type, term, category, status, mastered_at, last_practiced_at, created_at 
                FROM dictionary_master 
                WHERE language_code = :lang
                ORDER BY id ASC
            ");
            $stmtMaster->execute([':lang' => $this->languageCode]);
            $master = $stmtMaster->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmtBuffer = $this->db->prepare("
                SELECT 
                    ds.id, 
                    ds.master_id, 
                    dm.term, 
                    dm.item_type,
                    dm.category, 
                    dm.status, 
                    ds.score, 
                    ds.added_at
                FROM dictionary_small ds
                JOIN dictionary_master dm ON ds.master_id = dm.id
                WHERE dm.language_code = :lang
                ORDER BY ds.id ASC
            ");
            $stmtBuffer->execute([':lang' => $this->languageCode]);
            $activeBuffer = $stmtBuffer->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return [
                'master'        => $master,
                'active_buffer' => $activeBuffer
            ];
        } catch (\Throwable $e) {
            $this->logError('getDictionaryData Error', $e);
            return [
                'master'        => [],
                'active_buffer' => []
            ];
        }
    }
}
