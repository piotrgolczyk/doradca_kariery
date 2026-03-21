<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../data';
const USERS_FILE = DATA_DIR . '/users.json';
const TOPICS_FILE = DATA_DIR . '/topics.json';
const PROMPT_MAIN_FILE = DATA_DIR . '/prompt_main.txt';
const PROMPTS_FILE = DATA_DIR . '/prompts.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
const ERRORS_LOG = DATA_DIR . '/errors.log';

function nowIso(): string
{
    return gmdate('c');
}

function sendJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function readJsonFile(string $path, mixed $default = null): mixed
{
    if (!file_exists($path)) {
        return $default;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
}

function atomicWriteText(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $content);
    rename($tmp, $path);
}

function atomicWriteJson(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    atomicWriteText($path, $json . PHP_EOL);
}

function withLockedJsonFile(string $path, callable $callback): mixed
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $fh = fopen($path, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Cannot open file: ' . $path);
    }

    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Cannot lock file: ' . $path);
        }

        rewind($fh);
        $raw = stream_get_contents($fh);
        $decoded = null;
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
        }
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $result = $callback($decoded);

        if (is_array($result) && array_key_exists('data', $result)) {
            $newData = $result['data'];
            $fhPath = stream_get_meta_data($fh)['uri'];
            atomicWriteJson($fhPath, $newData);
            return $result['return'] ?? null;
        }

        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function getDefaultSummarizerPrompt(): string
{
    return 'Jesteś modułem streszczającym. Dla danego topic_id tworzysz JEDNĄ krótką notatkę (oneliner) do pamięci użytkownika. Maksymalnie 140 znaków. Bez ozdobników. Jeśli temat ma fact_key, zwróć także value dla tego fact_key. Zwróć wyłącznie JSON: {"one_liner":"...","fact_key":"string|null","fact_value":"string|null","confidence":0.0-1.0}';
}

function getPrompts(): array
{
    $main = file_exists(PROMPT_MAIN_FILE) ? (string)file_get_contents(PROMPT_MAIN_FILE) : '';
    $prompts = readJsonFile(PROMPTS_FILE, []);

    if (!is_array($prompts)) {
        $prompts = [];
    }

    return [
        'prompt_main' => (string)($prompts['prompt_main'] ?? $main),
        'summarizer_system' => (string)($prompts['summarizer_system'] ?? getDefaultSummarizerPrompt()),
    ];
}

function savePrompts(array $prompts): void
{
    atomicWriteJson(PROMPTS_FILE, [
        'prompt_main' => (string)($prompts['prompt_main'] ?? ''),
        'summarizer_system' => (string)($prompts['summarizer_system'] ?? getDefaultSummarizerPrompt()),
    ]);

    if (isset($prompts['prompt_main'])) {
        atomicWriteText(PROMPT_MAIN_FILE, (string)$prompts['prompt_main']);
    }
}

function getSettings(): array
{
    $settings = readJsonFile(SETTINGS_FILE, []);
    if (!is_array($settings)) {
        $settings = [];
    }
    return [
        'openai_api_key' => (string)($settings['openai_api_key'] ?? ''),
    ];
}

function saveSettings(array $settings): void
{
    atomicWriteJson(SETTINGS_FILE, [
        'openai_api_key' => (string)($settings['openai_api_key'] ?? ''),
    ]);
}

function resolveApiKey(): string
{
    $fromEnv = getenv('OPENAI_API_KEY') ?: '';
    if ($fromEnv !== '') {
        return $fromEnv;
    }

    $settings = getSettings();
    return (string)($settings['openai_api_key'] ?? '');
}


function historyFilePath(string $userId): string
{
    return DATA_DIR . '/history_' . $userId . '.json';
}

function appendConversationEntry(string $userId, string $role, string $text): void
{
    $path = historyFilePath($userId);
    withLockedJsonFile($path, function (array $history) use ($role, $text): array {
        $items = $history['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $items[] = [
            'ts' => nowIso(),
            'role' => $role,
            'text' => $text,
        ];

        return ['data' => ['items' => $items]];
    });
}

function getConversationHistory(string $userId): array
{
    $path = historyFilePath($userId);
    $history = readJsonFile($path, ['items' => []]);
    if (!is_array($history)) {
        return [];
    }
    $items = $history['items'] ?? [];
    return is_array($items) ? $items : [];
}

function flattenTopics(array $topicsData): array
{
    $flat = [];
    foreach ($topicsData['stages'] ?? [] as $stageIndex => $stage) {
        foreach ($stage['groups'] ?? [] as $groupIndex => $group) {
            foreach ($group['topics'] ?? [] as $topic) {
                $topicId = $topic['topic_id'] ?? null;
                if (!$topicId) {
                    continue;
                }
                $flat[$topicId] = [
                    'topic' => $topic,
                    'stage_id' => $stage['stage_id'],
                    'group_id' => $group['group_id'],
                    'stage_index' => $stageIndex,
                    'group_index' => $groupIndex,
                    'group_threshold' => $group['min_points_to_advance'] ?? PHP_INT_MAX,
                ];
            }
        }
    }
    return $flat;
}

function getFirstTopicId(array $topicsData): ?array
{
    $stage = $topicsData['stages'][0] ?? null;
    $group = $stage['groups'][0] ?? null;
    $topic = $group['topics'][0] ?? null;
    if (!$stage || !$group || !$topic) {
        return null;
    }
    return [
        'stage_id' => $stage['stage_id'],
        'group_id' => $group['group_id'],
        'topic_id' => $topic['topic_id'],
    ];
}

function findNextGroup(array $topicsData, string $stageId, string $groupId): ?array
{
    $stages = $topicsData['stages'] ?? [];
    foreach ($stages as $sIdx => $stage) {
        foreach (($stage['groups'] ?? []) as $gIdx => $group) {
            if (($stage['stage_id'] ?? '') === $stageId && ($group['group_id'] ?? '') === $groupId) {
                if (isset($stage['groups'][$gIdx + 1])) {
                    $nextGroup = $stage['groups'][$gIdx + 1];
                    return ['stage_id' => $stage['stage_id'], 'group_id' => $nextGroup['group_id']];
                }
                if (isset($stages[$sIdx + 1])) {
                    $nextStage = $stages[$sIdx + 1];
                    $firstGroup = $nextStage['groups'][0] ?? null;
                    if ($firstGroup) {
                        return ['stage_id' => $nextStage['stage_id'], 'group_id' => $firstGroup['group_id']];
                    }
                }
                return null;
            }
        }
    }
    return null;
}

function userFilePath(string $userId): string
{
    return DATA_DIR . '/user_' . $userId . '.json';
}

function defaultUserState(string $userId, array $topicsData): array
{
    $flat = flattenTopics($topicsData);
    $first = getFirstTopicId($topicsData);
    $topicState = [];

    foreach ($flat as $topicId => $entry) {
        $topicState[$topicId] = ['status' => 'not_started', 'attempts' => 0, 'achieved_at' => null];
    }

    return [
        'user_id' => $userId,
        'stage_state' => [
            'stage_id' => $first['stage_id'] ?? null,
            'group_id' => $first['group_id'] ?? null,
        ],
        'active_topic' => [
            'topic_id' => $first['topic_id'] ?? null,
            'since' => nowIso(),
            'attempts' => 0,
            'mode' => 'normal',
            'pending_confirmation' => null,
        ],
        'topic_state' => $topicState,
        'profile_facts' => [],
        'closed_topics_log' => [],
        'conversation_window' => [],
        'rate_limit' => ['last_request_at' => null],
    ];
}

function parseControlJson(string $responseText): array
{
    $delimiter = "<<<CONTROL_JSON>>>";
    $parts = explode($delimiter, $responseText, 2);
    $assistantText = trim($parts[0]);
    $control = [
        'next_active_topic_id' => null,
        'active_topic_action' => 'continue',
        'events' => [],
        'notes_to_add' => [],
    ];

    if (count($parts) === 2) {
        $jsonRaw = trim($parts[1]);
        $decoded = json_decode($jsonRaw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $control = array_merge($control, $decoded);
        } else {
            error_log(nowIso() . " invalid control json\n", 3, ERRORS_LOG);
        }
    }

    return ['assistant_text' => $assistantText, 'control_json' => $control];
}
