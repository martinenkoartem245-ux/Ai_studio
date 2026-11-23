<?php

declare(strict_types=1);

class Request {
    private array $data;
    private array $files;

    public function __construct(array $data = [], array $files = []) {
        $this->data = $data;
        $this->files = $files;
    }

    public static function capture(): self {
        $data = array_merge($_GET, $_POST);
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

        if (str_contains(strtolower($contentType), 'application/json')) {
            $rawJson = file_get_contents('php://input');
            if (!empty($rawJson)) {
                $decoded = json_decode($rawJson, true);
                if (is_array($decoded)) {
                    $data = array_merge($data, $decoded);
                }
            }
        }

        return new self($data, $_FILES);
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->data[$key] ?? $default;
    }

    public function getString(string $key, string $default = ''): string {
        $val = $this->get($key, null);
        if ($val === null) {
            return $default;
        }
        $str = is_string($val) ? trim($val) : (string)$val;
        return $str !== '' ? $str : $default;
    }

    public function getBool(string $key, bool $default = false): bool {
        $val = $this->get($key, null);
        if ($val === null) {
            return $default;
        }
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    public function getInt(string $key, int $default = 0): int {
        $val = $this->get($key, $default);
        return is_numeric($val) ? (int)$val : $default;
    }

    public function getFloat(string $key, float $default = 0.0): float {
        $val = $this->get($key, $default);
        return is_numeric($val) ? (float)$val : $default;
    }

    public function all(): array {
        return $this->data;
    }

    public function files(): array {
        return $this->files;
    }

    public function getFile(string $key): ?array {
        return $this->files[$key] ?? null;
    }
}