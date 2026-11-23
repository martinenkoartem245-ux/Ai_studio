<?php

declare(strict_types=1);

abstract class AbstractProvider {
    /**
     * Возвращает путь к SSL-сертификату CA, если файл существует.
     */
    protected function getCertPath(): ?string {
        $certPath = __DIR__ . '/../cacert.pem';
        return file_exists($certPath) ? $certPath : null;
    }

    /**
     * Извлекает API-ключ из константы или генерирует исключение.
     */
    protected function getApiKey(string $constantName): string {
        $apiKey = defined($constantName) ? trim((string)constant($constantName)) : '';
        if (empty($apiKey)) {
            throw new Exception("{$constantName} не задан в конфигурации (config/constants.php)", 401);
        }
        return $apiKey;
    }

    /**
     * Выполняет синхронный POST-запрос cURL и возвращает декодированный JSON.
     */
    protected function requestJson(string $url, array $headers, array $payload, int $timeout = 120): array {
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
        ];

        $certPath = $this->getCertPath();
        if ($certPath !== null) {
            $curlOpts[CURLOPT_CAINFO] = $certPath;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOpts);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new Exception(static::class . " cURL Error: " . $err);
        }

        $data = json_decode((string)$response, true);

        if ($httpCode >= 400 || !is_array($data)) {
            $errorMsg = $data['error']['message'] ?? static::class . " API Error (HTTP Status {$httpCode})";
            throw new Exception($errorMsg, $httpCode);
        }

        return $data;
    }

    /**
     * Выполняет потоковый SSE-запрос, разбирая входящие строки data: <JSON>.
     * Совместим с OpenAI, Kimi и Anthropic Claude.
     */
   /**
     * Выполняет потоковый SSE-запрос, разбирая входящие строки data: <JSON> и сырой JSON.
     */
    protected function requestStream(string $url, array $headers, array $payload, callable $lineHandler, int $timeout = 300): void {
        $buffer = '';
        $errorResponseBody = '';

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($lineHandler, &$buffer, &$errorResponseBody) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode >= 400) {
                    $errorResponseBody .= $data;
                    return strlen($data);
                }

                $buffer .= $data;
                $buffer = str_replace("\r", "", $buffer);

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if ($line === '') {
                        continue;
                    }

                    // Если есть префикс 'data:', отрезаем его
                    if (str_starts_with($line, 'data:')) {
                        $jsonStr = ltrim(substr($line, 5));
                    } else {
                        $jsonStr = $line;
                    }

                    // Окончание потока OpenAI / Kimi
                    if ($jsonStr === '[DONE]') {
                        $lineHandler(null, '[DONE]');
                        continue;
                    }

                    $json = json_decode($jsonStr, true);
                    if (is_array($json)) {
                        $lineHandler($json, $jsonStr);
                    }
                }
                return strlen($data);
            }
        ];

        $certPath = $this->getCertPath();
        if ($certPath !== null) {
            $curlOpts[CURLOPT_CAINFO] = $certPath;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOpts);

        curl_exec($ch);
        if (!empty(trim($buffer))) {
         $line = trim($buffer);
             $jsonStr = str_starts_with($line, 'data:') ? ltrim(substr($line, 5)) : $line;
        if ($jsonStr === '[DONE]') {
         $lineHandler(null, '[DONE]');
        } else {
            $json = json_decode($jsonStr, true);
        if (is_array($json)) {
            $lineHandler($json, $jsonStr);
        }
        }
    }
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new Exception(static::class . " Stream cURL Error: " . $err);
        }

        if ($httpCode >= 400) {
            $jsonErr = json_decode($errorResponseBody, true);
            $errorMsg = $jsonErr['error']['message'] 
                ?? (isset($jsonErr[0]['error']['message']) ? $jsonErr[0]['error']['message'] : null)
                ?? static::class . " Stream HTTP Error: Status {$httpCode}";
            throw new Exception($errorMsg, $httpCode);
        }
    }
}