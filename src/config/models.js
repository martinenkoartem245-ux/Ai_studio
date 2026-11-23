export const AI_MODELS = [
  { 
    id: 'gemini-4-hydrogen', 
    name: 'Gemini 4 Hydrogen', 
    provider: 'gemini', 
    supportsThinking: true, 
    maxThinking: 65536, 
    maxOutputTokens: 128000, 
    supportsWebSearch: true 
  },
  { 
    id: 'gemini-3.6-flash', 
    name: 'Gemini 3.6 Flash', 
    provider: 'gemini', 
    supportsThinking: true, 
    maxThinking: 32768, 
    maxOutputTokens: 65536, 
    supportsWebSearch: true 
  },
  { 
    id: 'gemini-3.5-flash-lite', 
    name: 'Gemini 3.5 Flash-Lite', 
    provider: 'gemini', 
    supportsThinking: true, 
    maxThinking: 16384, 
    maxOutputTokens: 32768, 
    supportsWebSearch: true 
  },
  { 
    id: 'kimi-k3.1-pro', 
    name: 'Kimi K3.1 (Флагман)', 
    provider: 'kimi', 
    supportsThinking: true, 
    maxThinking: 64000, 
    maxOutputTokens: 128000, 
    supportsWebSearch: true 
  },
  { 
    id: 'kimi-k2.6', 
    name: 'Kimi K2.6 (Рабочая лошадка, 256K)', 
    provider: 'kimi', 
    supportsThinking: true, 
    maxThinking: 32768, 
    maxOutputTokens: 32768, 
    supportsWebSearch: true 
  },
  { 
    id: 'kimi-k2.7-code', 
    name: 'Kimi K2.7 Code (Для кода)', 
    provider: 'kimi', 
    supportsThinking: true, 
    maxThinking: 65536, 
    maxOutputTokens: 65536, 
    supportsWebSearch: true 
  },
  { 
    id: 'claude-opus-5-5', 
    name: 'Claude Opus 5.5 (Флагман)', 
    provider: 'claude', 
    supportsThinking: true, 
    maxThinking: 64000, 
    maxOutputTokens: 128000, 
    supportsWebSearch: true 
  },
  { 
    id: 'claude-sonnet-5-5', 
    name: 'Claude Sonnet 5.5 (Почти флагман)', 
    provider: 'claude', 
    supportsThinking: true, 
    maxThinking: 64000, 
    maxOutputTokens: 128000, 
    supportsWebSearch: true 
  },
  { 
    id: 'claude-haiku-5-5', 
    name: 'Claude Haiku 5.5 (Рабочая лошадка 1!)', 
    provider: 'claude', 
    supportsThinking: true, 
    maxThinking: 32768, 
    maxOutputTokens: 64000, 
    supportsWebSearch: true 
  }
  
];
