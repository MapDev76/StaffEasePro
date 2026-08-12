<?php

/**
 * AI assistant ("Giulia") for admin and department_manager users.
 *
 * Three interchangeable providers, selected via config/claude.php `provider`:
 * - 'ollama': local, free, no external calls (default).
 * - 'groq': Groq's OpenAI-compatible API, free tier, hosted (works online).
 * - 'anthropic': Claude API, billed per request.
 *
 * Called directly with cURL (same convention as backend/mailer.php for
 * Brevo) since this project has no Composer/vendor setup.
 *
 * assistantChat() is the single entry point the controller's tool-use loop
 * calls. Regardless of provider, it returns a normalized shape:
 *   ['stop_reason' => 'tool_use'|'end_turn', 'content' => [
 *       ['type' => 'text', 'text' => '...'],
 *       ['type' => 'tool_use', 'id' => '...', 'name' => '...', 'input' => [...]],
 *   ]]
 * The message history the controller builds/appends to is always kept in
 * this same Anthropic-shaped format; assistantCallOllama()/assistantCallGroq()
 * convert it to OpenAI-style chat format on the way in (both providers speak
 * the same dialect) and normalize the response on the way out, so the
 * controller never needs to know which provider is active.
 */

/**
 * Loads config/claude.php once, falling back to a disabled configuration
 * when the file is missing (fresh checkout without a key).
 */
function assistantConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'enabled' => false,
        'provider' => 'ollama',
        'ollama_base_url' => 'http://localhost:11434',
        'ollama_model' => 'gemma4:12b',
        'groq_api_key' => '',
        'groq_model' => 'llama-3.3-70b-versatile',
        'api_key' => '',
        'model' => 'claude-opus-5',
        'timeout' => 60,
        'log_file' => '',
    ];

    $path = __DIR__ . '/../config/claude.php';
    if (!is_file($path)) {
        return $config = $defaults;
    }

    try {
        $loaded = require $path;
    } catch (Throwable $e) {
        return $config = $defaults;
    }

    if (!is_array($loaded)) {
        return $config = $defaults;
    }

    return $config = array_merge($defaults, $loaded);
}

/**
 * True when the assistant is enabled and the active provider has what it
 * needs to be called (an API key for Anthropic; nothing to check upfront
 * for Ollama beyond being enabled — reachability errors surface at call time).
 */
function assistantIsAvailable(): bool
{
    $config = assistantConfig();
    if (empty($config['enabled'])) {
        return false;
    }

    if ($config['provider'] === 'anthropic') {
        return trim((string) $config['api_key']) !== '';
    }

    if ($config['provider'] === 'groq') {
        return trim((string) $config['groq_api_key']) !== '';
    }

    return true;
}

/**
 * Appends a single line to the configured assistant log, if any.
 */
function assistantLog(string $message): void
{
    $logFile = trim((string) (assistantConfig()['log_file'] ?? ''));
    if ($logFile === '') {
        return;
    }

    try {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($logFile, '[' . date('c') . '] ' . $message . "\n", FILE_APPEND);
    } catch (Throwable $e) {
        // Logging must never break the request.
    }
}

/**
 * Single entry point for the tool-use loop: dispatches to the configured
 * provider and always returns the normalized shape described above.
 */
function assistantChat(string $systemPrompt, array $messages, array $tools): array
{
    $config = assistantConfig();

    if ($config['provider'] === 'anthropic') {
        return assistantCallClaude($systemPrompt, $messages, $tools);
    }

    if ($config['provider'] === 'groq') {
        return assistantCallGroq($systemPrompt, $messages, $tools);
    }

    return assistantCallOllama($systemPrompt, $messages, $tools);
}

/**
 * Calls the Anthropic Messages API. Anthropic's response already carries
 * top-level `stop_reason` and `content` in the normalized shape, so it is
 * returned as-is.
 */
function assistantCallClaude(string $systemPrompt, array $messages, array $tools): array
{
    $config = assistantConfig();

    $body = [
        'model' => $config['model'],
        'max_tokens' => 4096,
        'system' => $systemPrompt,
        'messages' => $messages,
        'thinking' => ['type' => 'adaptive'],
    ];

    if (!empty($tools)) {
        $body['tools'] = $tools;
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $config['api_key'],
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => (int) $config['timeout'],
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        assistantLog('[anthropic] Transport error: ' . $curlError);
        throw new RuntimeException('assistant_transport_error');
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        assistantLog('[anthropic] Invalid JSON response (HTTP ' . $statusCode . '): ' . substr($responseBody, 0, 500));
        throw new RuntimeException('assistant_invalid_response');
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $errorMessage = $decoded['error']['message'] ?? ('HTTP ' . $statusCode);
        assistantLog('[anthropic] API error (HTTP ' . $statusCode . '): ' . $errorMessage);
        throw new RuntimeException('assistant_api_error: ' . $errorMessage);
    }

    return $decoded;
}

/**
 * Calls a local Ollama server's /api/chat endpoint and normalizes the
 * response into the same shape assistantCallClaude() returns.
 */
function assistantCallOllama(string $systemPrompt, array $messages, array $tools): array
{
    $config = assistantConfig();
    $baseUrl = rtrim((string) $config['ollama_base_url'], '/');

    $body = [
        'model' => $config['ollama_model'],
        'messages' => assistantMessagesToOpenAIChat($systemPrompt, $messages),
        'stream' => false,
        // Keep the model resident in memory between requests so a follow-up
        // message (or the next tool-loop iteration) doesn't pay Ollama's
        // cold-load cost again — local inference is already slow enough
        // relative to the web server's request timeout.
        'keep_alive' => '30m',
    ];

    if (!empty($tools)) {
        $body['tools'] = assistantToolsToOpenAIFunctions($tools);
    }

    $ch = curl_init($baseUrl . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => (int) $config['timeout'],
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        assistantLog('[ollama] Transport error: ' . $curlError . ' (is Ollama running at ' . $baseUrl . '?)');
        throw new RuntimeException('assistant_transport_error');
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        assistantLog('[ollama] Invalid JSON response (HTTP ' . $statusCode . '): ' . substr($responseBody, 0, 500));
        throw new RuntimeException('assistant_invalid_response');
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $errorMessage = $decoded['error'] ?? ('HTTP ' . $statusCode);
        assistantLog('[ollama] API error (HTTP ' . $statusCode . '): ' . $errorMessage);
        throw new RuntimeException('assistant_api_error: ' . $errorMessage);
    }

    return assistantNormalizeOpenAIMessage($decoded['message'] ?? []);
}

/**
 * Calls Groq's OpenAI-compatible /openai/v1/chat/completions endpoint
 * (free tier, hosted — works from any host, unlike Ollama) and normalizes
 * the response into the same shape assistantCallClaude() returns.
 */
function assistantCallGroq(string $systemPrompt, array $messages, array $tools): array
{
    $config = assistantConfig();

    $body = [
        'model' => $config['groq_model'],
        // Groq follows the strict OpenAI spec: tool_calls[].function.arguments
        // must be a JSON-encoded string. Ollama wants a JSON object instead —
        // hence the provider flag.
        'messages' => assistantMessagesToOpenAIChat($systemPrompt, $messages, true),
    ];

    if (!empty($tools)) {
        $body['tools'] = assistantToolsToOpenAIFunctions($tools);
        $body['tool_choice'] = 'auto';
    }

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['groq_api_key'],
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => (int) $config['timeout'],
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        assistantLog('[groq] Transport error: ' . $curlError);
        throw new RuntimeException('assistant_transport_error');
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        assistantLog('[groq] Invalid JSON response (HTTP ' . $statusCode . '): ' . substr($responseBody, 0, 500));
        throw new RuntimeException('assistant_invalid_response');
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $errorMessage = $decoded['error']['message'] ?? ('HTTP ' . $statusCode);
        assistantLog('[groq] API error (HTTP ' . $statusCode . '): ' . $errorMessage);
        throw new RuntimeException('assistant_api_error: ' . $errorMessage);
    }

    $message = $decoded['choices'][0]['message'] ?? [];

    return assistantNormalizeOpenAIMessage(is_array($message) ? $message : []);
}

/**
 * Converts the Anthropic-shaped conversation history (system prompt kept
 * separately, messages with string or content-block array content) into
 * the OpenAI-style chat messages array both Ollama and Groq expect.
 *
 * $argumentsAsString controls how tool_calls[].function.arguments is
 * encoded: Groq follows the strict OpenAI spec and requires a JSON string,
 * while Ollama expects a JSON object. Default (false) is Ollama's shape.
 */
function assistantMessagesToOpenAIChat(string $systemPrompt, array $messages, bool $argumentsAsString = false): array
{
    $ollamaMessages = [['role' => 'system', 'content' => $systemPrompt]];

    foreach ($messages as $message) {
        $role = $message['role'] ?? 'user';
        $content = $message['content'] ?? '';

        if (is_string($content)) {
            $ollamaMessages[] = ['role' => $role, 'content' => $content];
            continue;
        }

        if (!is_array($content)) {
            continue;
        }

        // Assistant turn: may contain text blocks and tool_use blocks.
        if ($role === 'assistant') {
            $text = '';
            $toolCalls = [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'];
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                    if ($argumentsAsString) {
                        $arguments = json_encode($input, JSON_UNESCAPED_UNICODE);
                    } else {
                        // Ollama expects a JSON *object* here — an empty
                        // PHP array would encode as `[]`, which Ollama's
                        // parser rejects ("looks like object, but can't
                        // find closing '}'").
                        $arguments = empty($input) ? new stdClass() : $input;
                    }
                    $toolCalls[] = [
                        'id' => $block['id'] ?? uniqid('call_', true),
                        // Ollama ignores this; Groq/OpenAI-strict providers
                        // require it on every tool call.
                        'type' => 'function',
                        'function' => [
                            'name' => $block['name'] ?? '',
                            'arguments' => $arguments,
                        ],
                    ];
                }
            }
            $entry = ['role' => 'assistant', 'content' => $text];
            if (!empty($toolCalls)) {
                $entry['tool_calls'] = $toolCalls;
            }
            $ollamaMessages[] = $entry;
            continue;
        }

        // User turn carrying tool_result blocks: each becomes its own
        // 'tool' message. tool_call_id is ignored by Ollama but required by
        // Groq/OpenAI-strict providers to match the result to its call.
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_result') {
                $ollamaMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $block['tool_use_id'] ?? '',
                    'content' => is_string($block['content'] ?? null) ? $block['content'] : json_encode($block['content'] ?? ''),
                ];
            }
        }
    }

    return $ollamaMessages;
}

/**
 * Scans text for the first balanced {...} object (honoring quoted strings
 * and escapes) and returns it decoded, or null if none is found. Unlike a
 * bare json_decode($text), this tolerates prose the model wrote before or
 * after the JSON object.
 */
function assistantExtractLeadingJsonObject(string $text): ?array
{
    $start = strpos($text, '{');
    if ($start === false) {
        return null;
    }

    $depth = 0;
    $inString = false;
    $escaped = false;
    $length = strlen($text);

    for ($i = $start; $i < $length; $i++) {
        $char = $text[$i];

        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $inString = false;
            }
            continue;
        }

        if ($char === '"') {
            $inString = true;
        } elseif ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                $candidate = substr($text, $start, $i - $start + 1);
                $decoded = json_decode($candidate, true);
                return is_array($decoded) ? $decoded : null;
            }
        }
    }

    return null;
}

/**
 * Converts Anthropic-shaped tool definitions ({name, description,
 * input_schema}) into Ollama/OpenAI-style function tools.
 */
function assistantToolsToOpenAIFunctions(array $tools): array
{
    return array_map(static function (array $tool): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['input_schema'],
            ],
        ];
    }, $tools);
}

/**
 * Normalizes an OpenAI-style chat message (Ollama's `message`, or Groq's
 * `choices[0].message`) into the same {stop_reason, content} shape
 * assistantCallClaude() returns. `tool_calls[].function.arguments` may be
 * either a JSON object (Ollama) or a JSON-encoded string (Groq/OpenAI
 * spec) — both are handled below.
 */
function assistantNormalizeOpenAIMessage(array $message): array
{
    $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];

    $content = [];
    $text = trim((string) ($message['content'] ?? ''));

    // Smaller local models sometimes write the tool call as plain-text JSON
    // (e.g. {"name": "list_departments", "arguments": {}}, occasionally with
    // extra prose before/after) instead of using the structured tool_calls
    // field. Detect and treat it as a real call rather than showing raw
    // JSON to the user.
    if (empty($toolCalls) && $text !== '' && str_contains($text, '{')) {
        $maybeCall = assistantExtractLeadingJsonObject($text);
        if (is_array($maybeCall) && !empty($maybeCall['name'])) {
            $toolCalls = [[
                'id' => uniqid('call_', true),
                'function' => [
                    'name' => $maybeCall['name'],
                    'arguments' => is_array($maybeCall['arguments'] ?? null) ? $maybeCall['arguments'] : [],
                ],
            ]];
            $text = '';
        }
    }

    if ($text !== '') {
        $content[] = ['type' => 'text', 'text' => $text];
    }
    foreach ($toolCalls as $toolCall) {
        $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
        $arguments = $function['arguments'] ?? [];
        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true) ?: [];
        }
        $content[] = [
            'type' => 'tool_use',
            'id' => $toolCall['id'] ?? uniqid('call_', true),
            'name' => $function['name'] ?? '',
            'input' => is_array($arguments) ? $arguments : [],
        ];
    }

    return [
        'stop_reason' => !empty($toolCalls) ? 'tool_use' : 'end_turn',
        'content' => $content,
    ];
}
