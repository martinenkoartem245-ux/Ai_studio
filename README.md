AI Chat Client
A multi-provider client for AI models (Gemini, Claude, Kimi). Users work with presets, each with its own system prompt, context files, and model settings. Within a preset, users run separate sessions (dialogues). The project includes a vocabulary module for language learning, model tools (web search, page fetching, Telegram notifications, scheduled tasks), and a background worker.

Status: the project is in development. Don't publish it openly until the issues in Known Issues are resolved, especially the security ones.

Contents
Features
Architecture
Tech Stack
Repository Structure
Requirements
Installation and Setup
Configuration
Presets and Sessions
API
Streaming Events (SSE)
Model Tools
Vocabulary
Known Issues
Features

Multiple AI providers. Gemini, Claude (Anthropic), and Kimi (Moonshot) through a single interface. Each session selects its own model.
Presets and sessions. A preset defines a scenario and system prompt. It contains a set of independent session dialogues.
Streaming responses. Responses arrive via Server-Sent Events, displaying both text and reasoning blocks (thinking).
Generation controls. Configure temperature, thinking budget and level, token limit, web search, and built-in Gemini tools.
Context files. Files uploaded to a session are included in the system prompt. Plain text formats and .docx are supported.
Vocabulary. Presets with the dictionary enabled maintain an active buffer of 10 words, a master dictionary, and usage scores. A supervisor model performs the evaluation every 10 messages.
Scheduled tasks. The model can schedule a task itself (set_period). A background worker executes the tasks, and the result is added to the dialogue history.
Notes. A global list of notes with types "Bug", "Feature", and "Note", plus a done/not done status.
Response rendering. Markdown, syntax highlighting, copy buttons for code and tables (tables are copied in TSV format for Excel and Google Sheets).
Adaptive interface. On mobile, the side panels work as slide-out drawers.
Architecture
The project has three parts: the frontend (SPA), the HTTP backend, and a background worker. They communicate over HTTP and SSE and share a file-based storage.

Frontend (Vue 3 + Pinia)
  client.js  →  chatStore.js  →  components
        │  HTTP / SSE (/api proxy)
        ▼
Backend (PHP)
  index.php           entry point, CORS, tunnel token authorization
    └─ Router          selects the handler by preset
         └─ BaseHandler / preset module
              ├─ SessionService, FileService   sessions, files, JSON storage
              ├─ DictionaryService             vocabulary (MySQL)
              └─ AiService                     model facade
                   ├─ ClaudeProvider / GeminiProvider / KimiProvider
                   └─ ToolLoopRunner → WebToolRegistry → Tavily, Firecrawl, Telegram, TaskStorage

worker.php (CLI, separate process)
  └─ TaskStorageService::getDueTasks() → AiService → chat_history.json

The frontend handles the interface and state. The backend contains all business logic and calls to external APIs. The worker runs scheduled tasks outside HTTP requests.

Tech Stack
Frontend

Vue 3, Pinia
Tailwind CSS v4 (via @tailwindcss/vite)
marked for Markdown, highlight.js for code highlighting
Vite
Backend

PHP (cURL, ZipArchive, PDO)
MySQL (vocabulary)
File storage in JSON for sessions, presets, and history
External services

Google Gemini API, Anthropic Claude API, Moonshot Kimi API
Tavily Search API (web search)
Firecrawl API (page fetching as markdown)
Telegram Bot API (notifications)
Repository Structure
.
├── frontend/
│   ├── src/
│   │   ├── api/client.js          HTTP and SSE client, token and session handling
│   │   ├── stores/chatStore.js    state and business logic (Pinia)
│   │   ├── utils/
│   │   │   ├── formatters.js      escapeHTML, renderMarkdown, highlightCode
│   │   │   └── models.js          AI_MODELS model registry
│   │   ├── components/
│   │   │   ├── App.vue, Header.vue
│   │   │   ├── ChatContainer.vue, ChatInput.vue, ChatMessage.vue
│   │   │   ├── SidebarLeft.vue, SidebarRight.vue
│   │   │   ├── MaxTokensControl.vue, ModelParamsManager.vue
│   │   │   └── modals/            AuthModal, DictionaryModal, NewPresetModal,
│   │   │                          NewSessionModal, NotesModal
│   │   ├── main.js
│   │   └── style.css
│   ├── vite.config.js
│   └── package.json
│
└── backend/
    ├── index.php                  API entry point
    ├── worker.php                 background worker for scheduled tasks
    ├── db.php                     MySQL connection
    ├── constants.php              API keys and settings (not in the repository)
    ├── core/
    │   ├── Autoloader.php, Request.php, Response.php, SseResponse.php
    │   ├── AppLogger.php, InstructionLogger.php, JsonStorage.php
    │   ├── Router.php, BaseHandler.php
    │   ├── services/              SessionService, FileService, DictionaryService
    │   ├── ai/                    AiService, providers, AiParamNormalizer, CostCalculator
    │   └── WebSearch/             ToolLoopRunner, ToolSchemaAdapter, WebToolRegistry,
    │                              TavilyClient, FirecrawlClient, HttpClient,
    │                              TelegramClient, TimeParser, TaskStorageService
    ├── modules/                   PolishModule, EnglishModule, AnalysisModule,
    │                              GenericModule, GenericDictionaryModule
    ├── config/auth_token.secret   hash of the access token (created on registration)
    ├── data/scheduled_tasks.json  scheduled tasks
    ├── presets/                   presets and sessions
    └── storage/logs/              logs

The exact structure may differ. Paths were reconstructed from the documentation. The preset module files and constants.php are not described in it.

Requirements
Node.js 18+ and npm
PHP 8.1+ with the curl, json, pdo_mysql, zip, and mbstring extensions
MySQL 8.0+ (or MariaDB) for the vocabulary
A web server (Apache or Nginx) with PHP-FPM
API keys for the providers: Gemini, Claude, Kimi. Optional: Tavily, Firecrawl, a Telegram bot
Installation and Setup
1. Backend
Create a MySQL database learning_db with the tables dictionary_master, dictionary_small, user_meta, error_candidates, and typo_queue. The schema is not described in the documentation, so restore it from the DictionaryService code.
Set the connection parameters in backend/db.php. They are currently hardcoded (see Configuration).
Create backend/constants.php with the required constants (see below).
Point the web server at backend/ so that API requests reach index.php.
Make sure PHP can write to storage/logs, presets/, and data/.
2. Frontend
cd frontend
npm install
npm run dev

In vite.config.js, configure the /api proxy to point to your backend. The dev server currently opens the browser automatically (server.open).

3. Background worker
The worker runs as a separate CLI process and must run continuously, or scheduled tasks won't execute:

php backend/worker.php

For production, use systemd, supervisor, or a similar tool. The worker polls the queue every 10 seconds.

4. First login
On first launch, the authentication window opens. If config/auth_token.secret does not exist, the entered token is saved as the secret access token. Subsequent logins are verified against its hash.

Configuration
constants.php
The file must define the following constants:

Constant	Purpose
STORAGE_PATH	base storage path (optional; otherwise directories relative to the code are used)
DEBUG_MODE	debug mode; error responses include the file and line
ALLOWED_EXTENSIONS	allowed extensions for context files
GEMINI_API_KEY, CLAUDE_API_KEY, KIMI_API_KEY	provider API keys
TAVILY_API_KEY	web search key (optional)
FIRECRAWL_API_KEY	page fetching key (optional)
TELEGRAM_BOT_TOKEN	bot token (optional)
TELEGRAM_CHAT_ID_1, TELEGRAM_CHAT_ID_2	notification recipients (optional)

Preset config.json
Each preset is stored in presets/{preset_id}/config.json. The documentation mentions these fields:

name: display name;
model: default model for new sessions;
has_dictionary: enables the vocabulary;
language: vocabulary language, en or pl (default en);
system_prompt: system prompt (if not set, a default phrase is used);
history_limit: number of messages in context when the vocabulary is enabled (default 10).
Models
The model registry is in frontend/src/utils/models.js (AI_MODELS). Each model specifies id, name, provider, supportsThinking, supportsWebSearch, and maxThinking. The provider is selected by a substring in the model name: gemini, claude, otherwise Kimi.

Limits
Token limit in the interface: from 256 to 65,536, default 8192. When manual mode is off, 15,000 is used.
Default thinking budget: 15,000.
Presets and Sessions
presets/
└── {preset_id}/
    ├── config.json
    └── sessions/
        └── {session_id}/
            ├── meta.json            model, title, generation flag, statistics
            ├── chat_history.json    message history
            ├── context_memory.json
            └── context_data/        uploaded files

A preset defines a scenario and its settings. It is created with create_preset, which also automatically creates a session called "Main session".
A session is a separate dialogue with its own history, files, and model. Its ID has the form session_{date}_{hex}.
The session's model is also stored in the cookie session_model_<preset>_<session>.
The presets polish_chronos, english_chronos, and deep_analysis have dedicated modules with their own logic. Other presets use GenericModule and GenericDictionaryModule.

API
All requests go to /api with the action parameter and, where needed, preset and session. Requests that return JSON use GET or POST. Sending messages and watching a session use SSE. All requests except auth_status and auth_register require the header Authorization: Bearer <token>.

Action	Method	Description
auth_status	GET	checks whether an access token is set
auth_register	POST	registers the token or logs in
get_structure	GET	tree of presets and sessions
create_preset	POST	creates a preset
delete_preset	POST	deletes a preset
create_session	POST	creates a session
delete_session	POST	deletes a session
get_session_data	GET	history, files, session configuration
set_model	GET	changes the session's model
send_message	POST, SSE	sends a message and streams the response
watch_session	GET, SSE	watches for changes in the history
upload_file	POST	uploads context files
delete_file	POST	deletes a file
get_dictionary	GET	active buffer and master dictionary
add_word	POST	adds a word to the dictionary
get_notes	GET	list of notes
save_notes	POST	saves the entire list of notes
📊
Скопировать таблицу
Error responses have the form { "error": true, "message": "..." }. On authorization errors, the server returns status 401 with the field is_auth_error.

Streaming Events (SSE)
Event	Meaning
text	fragment of the response text
thinking	fragment of the model's reasoning
tool_status	tool status: running, done, max_iterations_reached
tool_calls_delta	raw deltas of tool calls (Kimi)
usage	token usage
finish	finish reason (Kimi)
done	end of the response
reset_stream	partial response discarded, fallback model starts
system_notice	service notice
history_updated	full history after a file change
reconnect	client should reconnect (watch_session timeout)
error	generation error
ping	keeps the connection alive (comment :)
📊
Скопировать таблицу
If a model is unavailable, the backend switches to a fallback model from the fallback chain. The client receives reset_stream, and a new response begins.

Model Tools
Tools are enabled by flags in the request parameters. They are executed by ToolLoopRunner, which repeats the model request until the model replies with text. The number of iterations is limited. On the last iteration, tools are disabled so the model produces a final answer.

Tool	What it does
web_search	search via Tavily, up to 10 results
web_fetch	fetches a page via Firecrawl as markdown, up to 8000 characters
telegram_notify	sends a message to one of two configured chats
set_period	creates a scheduled task (executed by the worker)
list_scheduled_tasks	lists the session's tasks
cancel_scheduled_task	cancels a task
📊
Скопировать таблицу
The time for set_period accepts intervals such as 15m, 2h, 1d, 1w, or any string that strtotime() understands. The default time zone is Europe/Warsaw.

Vocabulary
The vocabulary works for presets with has_dictionary: true and language en or pl.

Master dictionary (dictionary_master) contains all terms of the language, with a category (B1_B2, C1_PLUS, GRAMMAR, BUSINESS) and a status (new, learning, mastered).
Small buffer (dictionary_small) holds 10 active words with scores. The buffer is filled first with new words, then with words that haven't been practiced for a long time, and if still short, with words generated by the model.
Supervisor: every 10 messages, the model is asked to evaluate word usage. Correct usage gives +1, an error gives −1. A word that reaches 10 points moves to mastered.
Errors accumulate in a queue. Every three supervisor runs, they are classified into rules, vocabulary, and typos.
The latest raw supervisor response is saved to latest_supervisor_response.json.

Known Issues
Below are the most important issues. The full list is in the project documentation.

Critical

Preset deletion: with an empty or special identifier (.., .), the path may point to the storage root. A realpath check is needed.
File uploads don't check the extension. .php and .htaccess files are saved, and the context_data folder may be reachable over HTTP.
Concurrent worker and chat: both write to chat_history.json without locking, so messages can be lost.
Security

While auth_token.secret doesn't exist, auth_register accepts any token. Hash comparison uses !== instead of hash_equals(). The hash is unsalted.
The access token is passed as a URL parameter at login and can end up in web server logs.
Model responses are rendered via v-html without sanitization. Malicious HTML in a response can execute in the browser. DOMPurify or a similar library is needed.
Logs contain the full $_POST and $_GET, which may include passwords and tokens.
The Gemini key is passed in the query string, and the Telegram token is part of the URL.
Database parameters are hardcoded, and the root password is empty.
Reliability

Corrupted JSON is read as an empty array, and the next write erases the data.
A task stuck in processing stays there forever if the worker crashes.
On the last iteration, the tool loop may execute tools without a model response to them.
Other

Some Tailwind v4 classes are written incorrectly and have no effect (py-0.2, hover:bg-gray-850, some animations).
The Inter and JetBrains Mono fonts are not loaded.
The .markdown-body class needs to be checked in style.css.

