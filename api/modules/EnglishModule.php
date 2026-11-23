<?php

declare(strict_types=1);

class EnglishModule extends BaseHandler 
{
    public function __construct(string $preset, ?PDO $db = null, ?object $aiService = null) 
    {
        parent::__construct($preset, $db, $aiService);
        
        if ($db !== null && $this->dictionaryService !== null) {
            $this->dictionaryService->setLanguageCode('en');
        }
    }
}
