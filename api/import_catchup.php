<?php

declare(strict_types=1);

// ─── ДОБАВЬТЕ ЭТУ СТРОКУ (укажите путь к вашему файлу подключения к БД) ───
require_once __DIR__ . '/db.php'; // или config.php / bootstrap.php

require_once __DIR__ . '/core/BaseHandler.php';
require_once __DIR__ . '/core/services/DictionaryService.php';
$db = $GLOBALS['db'] ?? $GLOBALS['pdo'] ?? null;
if (!$db) {
    die("Ошибка: Нет подключения к БД (PDO is null)\n");
}
$jsonFile = __DIR__ . '/catchup_result.json';
if (!file_exists($jsonFile)) {
    die(" Ошибка: Файл catchup_result.json не найден в корне проекта!\n");
}

$raw = file_get_contents($jsonFile);
$data = json_decode($raw, true);

if (!is_array($data)) {
    die(" Ошибка: Невалидный JSON в файле catchup_result.json\n");
}

// ─── Укажите язык результатов: 'pl' или 'en' ───
$lang = 'pl';

$dictService = new DictionaryService($db, $lang);

echo "=== НАЧАЛО ИМПОРТА В БАЗУ ДАННЫХ ({$lang}) ===\n\n";

// 1. Импорт грамматических правил
if (!empty($data['grammar_rules']) && is_array($data['grammar_rules'])) {
    $count = 0;
    foreach ($data['grammar_rules'] as $rule) {
        $ruleStr = trim((string)$rule);
        if ($ruleStr !== '') {
            $dictService->addWord($ruleStr, 'GRAMMAR');
            $count++;
        }
    }
    echo "✔ Добавлено грамматических правил: {$count}\n";
}

// 2. Импорт лексики (BUSINESS)
if (!empty($data['business_vocabulary']) && is_array($data['business_vocabulary'])) {
    $count = 0;
    foreach ($data['business_vocabulary'] as $word) {
        $wordStr = trim((string)$word);
        if ($wordStr !== '') {
            $dictService->addWord($wordStr, 'BUSINESS');
            $count++;
        }
    }
    echo "✔ Добавлено бизнес-лексики: {$count}\n";
}

// 3. Неизвестные слова
if (!empty($data['explicit_unknown_words']) && is_array($data['explicit_unknown_words'])) {
    $count = 0;
    foreach ($data['explicit_unknown_words'] as $word) {
        $wordStr = trim((string)$word);
        if ($wordStr !== '') {
            $dictService->addWord($wordStr, 'B1_B2');
            $count++;
        }
    }
    echo "✔ Добавлено неизвестных слов: {$count}\n";
}

// 4. Опечатки -> в очередь typo_queue
if (!empty($data['typos']) && is_array($data['typos'])) {
    $stmtCount = $db->prepare("SELECT user_message_count FROM user_meta WHERE language_code = :lang");
    $stmtCount->execute([':lang' => $lang]);
    $currentMsgCount = (int)$stmtCount->fetchColumn();

    $stmtTypo = $db->prepare("
        INSERT INTO typo_queue (language_code, expected_word, check_at_message) 
        VALUES (:lang, :word, :check_at)
        ON DUPLICATE KEY UPDATE check_at_message = VALUES(check_at_message)
    ");

    $count = 0;
    foreach ($data['typos'] as $typo) {
        $typoStr = trim((string)$typo);
        if ($typoStr !== '') {
            $stmtTypo->execute([
                ':lang'     => $lang,
                ':word'     => $typoStr,
                ':check_at' => $currentMsgCount + rand(3, 7)
            ]);
            $count++;
        }
    }
    echo "✔ Запушено опечаток в карантин: {$count}\n";
}

// 5. Ошибки в error_candidates
if (!empty($data['error_candidates']) && is_array($data['error_candidates'])) {
    $stmtError = $db->prepare("
        INSERT INTO error_candidates (language_code, term, context_sentence, occurrence_count, last_seen_at) 
        VALUES (:lang, :term, :sentence, 1, NOW())
        ON DUPLICATE KEY UPDATE 
            occurrence_count = occurrence_count + 1, 
            context_sentence = VALUES(context_sentence),
            last_seen_at = NOW()
    ");

    $count = 0;
    foreach ($data['error_candidates'] as $err) {
        $term = trim((string)($err['term'] ?? ''));
        $sentence = trim((string)($err['sentence'] ?? $term));

        if ($term !== '') {
            $stmtError->execute([
                ':lang'     => $lang,
                ':term'     => $term,
                ':sentence' => $sentence !== '' ? $sentence : $term
            ]);
            $count++;
        }
    }
    echo "✔ Записано кандидатов в ошибки: {$count}\n";
}

// 6. Актуализируем счетчик сообщений в user_meta (ИСПРАВЛЕНО ДЛЯ PDO)
$totalUserMessages = (int)($data['total_user_messages'] ?? 0);
if ($totalUserMessages > 0) {
    $supervisorRuns = (int)floor($totalUserMessages / 10);
    $stmtMeta = $db->prepare("
        INSERT INTO user_meta (language_code, user_message_count, supervisor_run_count)
        VALUES (:lang, :cnt, :runs)
        ON DUPLICATE KEY UPDATE 
            user_message_count = VALUES(user_message_count), 
            supervisor_run_count = VALUES(supervisor_run_count)
    ");
    $stmtMeta->execute([
        ':lang' => $lang,
        ':cnt'  => $totalUserMessages,
        ':runs' => $supervisorRuns
    ]);
    echo "✔ Счётчик сообщений актуализирован: {$totalUserMessages} сообщений ({$supervisorRuns} запусков супервайзера)\n";
}

// 7. Перестраиваем малый словарь
$dictService->rebuildSmallDictionary();
echo "✔ Малый словарь пересобран под новые данные.\n";

echo "\n=== ИМПОРТ УСПЕШНО ЗАВЕРШЕН! ===\n";
