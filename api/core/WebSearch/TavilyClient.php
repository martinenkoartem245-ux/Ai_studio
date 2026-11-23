<?php
declare(strict_types=1);

class TavilyClient {
    private string $apiKey;
    private string $endpoint = 'https://api.tavily.com/search';

    public function __construct(?string $apiKey = null) {
        $this->apiKey = $apiKey ?? (defined('TAVILY_API_KEY') ? TAVILY_API_KEY : '');
        if ($this->apiKey === '') {
            throw new \RuntimeException('TAVILY_API_KEY не задан в constants.php');
        }
    }

    public function search(string $query, int $maxResults = 5): array {
        $resp = HttpClient::postJson($this->endpoint, [
            'api_key'         => $this->apiKey,
            'query'           => $query,
            'max_results'     => max(1, min($maxResults, 10)),
            'include_answer'  => true,
        ], [], 30);

        return [
            'answer'  => $resp['answer'] ?? null,
            'results' => array_map(fn($r) => [
                'title'   => (string)($r['title'] ?? ''),
                'url'     => (string)($r['url'] ?? ''),
                'snippet' => mb_substr((string)($r['content'] ?? ''), 0, 600),
            ], $resp['results'] ?? []),
        ];
    }
}
