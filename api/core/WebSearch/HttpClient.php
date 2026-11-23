<?php
declare(strict_types=1);

class HttpClient {
        public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 30): array {
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ];

        $cert = __DIR__ . '/../cacert.pem'; // тот же путь, что в AbstractProvider (поправьте, если файлы в разных папках)
        if (file_exists($cert)) {
            $opts[CURLOPT_CAINFO] = $cert;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);

        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("HTTP запрос не удался: {$err}");
        }
        if ($status >= 400) {
            throw new \RuntimeException("HTTP {$status}: " . mb_substr((string)$body, 0, 500));
        }
        $data = json_decode((string)$body, true);
        return is_array($data) ? $data : [];
    }
}