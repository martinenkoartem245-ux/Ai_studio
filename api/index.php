<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

if (function_exists('set_time_limit')) {
    @set_time_limit(3000);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/core/Autoloader.php';
require_once __DIR__ . '/db.php';

// === CORS ЗАГОЛОВКИ ===
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// === ФАЙЛ ХРАНЕНИЯ ТОКЕНА ТУННЕЛЯ ===
define('AUTH_SECRET_FILE', __DIR__ . '/../config/auth_token.secret');

/**
 * Проверка и извлечение Bearer-токена из заголовков запроса
 */
function extractBearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (empty($header) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? $headers['X-Auth-Token'] ?? '';
    }
    
    if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
        return $matches[1];
    }
    return !empty($header) ? trim($header) : null;
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $errorMessage = 'Fatal PHP Error: ' . $error['message'];
        $action = $_REQUEST['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';
        $isSse = ($action === 'send_message');

        if ($isSse && class_exists('SseResponse')) {
            SseResponse::error($errorMessage, 500);
        } elseif (class_exists('Response')) {
            $extra = (defined('DEBUG_MODE') && DEBUG_MODE) ? [
                'file' => $error['file'],
                'line' => $error['line']
            ] : [];
            Response::error($errorMessage, 500, $extra);
        } else {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['error' => true, 'message' => $errorMessage], JSON_UNESCAPED_UNICODE);
        }
    }
});

try {
    $request = Request::capture();
    $action = $request->getString('action');

    // Логируем ВСЕ входящие HTTP-запросы в самом начале
    if (class_exists('AppLogger')) {
        AppLogger::info('request', 'Входящий запрос', [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'uri'    => $_SERVER['REQUEST_URI'] ?? '',
            'action' => $action,
            'params' => method_exists($request, 'all') ? $request->all() : array_merge($_GET, $_POST),
            'files'  => array_keys($_FILES)
        ]);
    }

    // ==========================================
    // ЛОГИКА АУТЕНТИФИКАЦИИ ДЛЯ ТУННЕЛЯ
    // ==========================================
    $isTokenConfigured = file_exists(AUTH_SECRET_FILE);

    // 1. Проверка статуса (настроен ли пароль в системе)
    if ($action === 'auth_status') {
        echo json_encode([
            'is_configured' => $isTokenConfigured,
            'status' => 'success'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Регистрация первого токена / Вход
    if ($action === 'auth_register') {
        $token = trim($request->getString('token'));
        if (empty($token) || strlen($token) < 4) {
            http_response_code(400);
            echo json_encode(['error' => true, 'message' => 'Токен должен содержать минимум 4 символа'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$isTokenConfigured) {
            @file_put_contents(AUTH_SECRET_FILE, hash('sha256', $token));
            if (class_exists('AppLogger')) {
                AppLogger::info('auth', 'Первичный токен туннеля успешно установлен');
            }
            echo json_encode(['status' => 'success', 'message' => 'Токен успешно установлен!', 'token' => $token], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            $savedHash = trim((string)@file_get_contents(AUTH_SECRET_FILE));
            if (hash('sha256', $token) === $savedHash) {
                if (class_exists('AppLogger')) {
                    AppLogger::info('auth', 'Успешная авторизация по токену туннеля');
                }
                echo json_encode(['status' => 'success', 'message' => 'Авторизация успешна', 'token' => $token], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                if (class_exists('AppLogger')) {
                    AppLogger::warning('auth', 'Неверная попытка ввода токена туннеля');
                }
                http_response_code(401);
                echo json_encode(['error' => true, 'message' => 'Неверный токен доступа!'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    // 3. Защита всех остальных экшенов: проверяем заголовок
    if ($isTokenConfigured) {
        $clientToken = extractBearerToken();
        $savedHash = trim((string)@file_get_contents(AUTH_SECRET_FILE));

        if (!$clientToken || hash('sha256', $clientToken) !== $savedHash) {
            if (class_exists('AppLogger')) {
                AppLogger::warning('auth', 'Отклонен неавторизованный запрос', ['action' => $action]);
            }
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => true,
                'is_auth_error' => true,
                'message' => 'Доступ запрещен: требуется авторизация через токен туннеля'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (empty($action)) {
        Response::error('Параметр action не указан', 400);
    }

    Router::dispatch($request);

} catch (Throwable $e) {
    if (class_exists('AppLogger')) {
        AppLogger::error('request', 'Ошибка выполнения: ' . $e->getMessage(), [
            'code'  => $e->getCode(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    $rawCode = (int)$e->getCode();
    $code = ($rawCode >= 400 && $rawCode < 600) ? $rawCode : 500;
    $isSse = (isset($action) && $action === 'send_message');

    if ($isSse && class_exists('SseResponse')) {
        SseResponse::error($e->getMessage(), $code);
    } elseif (class_exists('Response')) {
        $extra = (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ] : [];
        Response::error($e->getMessage(), $code, $extra);
    } else {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => true, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
