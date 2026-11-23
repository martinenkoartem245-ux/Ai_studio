<?php
declare(strict_types=1);

class FirecrawlClient {
    private string $apiKey;
    private string $endpoint = 'https://api.firecrawl.dev/v1/scrape';

    public function __construct(?string $apiKey = null) {
        $this->apiKey = $apiKey ?? (defined('FIRECRAWL_API_KEY') ? FIRECRAWL_API_KEY : '');
        if ($this->apiKey === '') {
            throw new \RuntimeException('FIRECRAWL_API_KEY не задан в constants.php');
        }
    }

    public function scrape(string $url, int $maxChars = 8000): array {
        $resp = HttpClient::postJson($this->endpoint, [
            'url'     => $url,
            'formats' => ['markdown'],
        ], ["Authorization: Bearer {$this->apiKey}"], 60);

        $data     = $resp['data'] ?? [];
        $markdown = (string)($data['markdown'] ?? '');

        if (mb_strlen($markdown) > $maxChars) {
            $markdown = mb_substr($markdown, 0, $maxChars) . "\n...[контент обрезан]";
        }

        return [
            'url'     => $url,
            'title'   => (string)($data['metadata']['title'] ?? ''),
            'content' => $markdown !== '' ? $markdown : '(пустая страница или контент недоступен)',
        ];
    }
}
