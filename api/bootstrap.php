<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../data';

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function read_json_file(string $path, array $default = []): array
{
    if (!file_exists($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : $default;
}

function atomic_write(string $path, string $contents): void
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $contents);
    rename($tmp, $path);
}

function with_file_lock(string $path, callable $callback)
{
    $fp = fopen($path, 'c+');
    if (!$fp) {
        throw new RuntimeException('Nie można otworzyć pliku lock: ' . $path);
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Nie można założyć locka: ' . $path);
        }
        return $callback($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function settings(): array
{
    return read_json_file(DATA_DIR . '/settings.json', []);
}

function topics_data(): array
{
    return read_json_file(DATA_DIR . '/topics.json', ['stages' => []]);
}

function get_first_topic(array $topics): array
{
    $stage = $topics['stages'][0] ?? null;
    $group = $stage['groups'][0] ?? null;
    $topic = $group['topics'][0] ?? null;

    if (!$stage || !$group || !$topic) {
        throw new RuntimeException('Niepoprawny topics.json - brak startowego topicu.');
    }

    return [$stage, $group, $topic];
}

function user_file_path(string $userId): string
{
    return DATA_DIR . '/user_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) . '.json';
}

function chatlog_file_path(string $userId): string
{
    return DATA_DIR . '/chatlog_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) . '.jsonl';
}

function find_topic(array $topics, string $topicId): ?array
{
    foreach ($topics['stages'] as $stage) {
        foreach ($stage['groups'] as $group) {
            foreach ($group['topics'] as $topic) {
                if (($topic['topic_id'] ?? '') === $topicId) {
                    return ['stage' => $stage, 'group' => $group, 'topic' => $topic];
                }
            }
        }
    }
    return null;
}

function flatten_topics(array $topics): array
{
    $all = [];
    foreach ($topics['stages'] as $stage) {
        foreach ($stage['groups'] as $group) {
            foreach ($group['topics'] as $topic) {
                $topic['stage_id'] = $stage['stage_id'];
                $topic['group_id'] = $group['group_id'];
                $all[] = $topic;
            }
        }
    }
    return $all;
}

function init_user_state(string $userId, array $topics): array
{
    [$stage, $group, $topic] = get_first_topic($topics);
    $now = time();

    return [
        'user_id' => $userId,
        'first_seen_ts' => $now,
        'last_active_ts' => null,
        'last_session_start_ts' => $now,
        'stage_state' => [
            'stage_id' => $stage['stage_id'],
            'group_id' => $group['group_id']
        ],
        'active_topic' => [
            'topic_id' => $topic['topic_id'],
            'since' => $now,
            'attempts' => 0,
            'mode' => 'normal',
            'pending_confirmation' => null,
            'missing_info_hint' => null
        ],
        'topic_state' => [],
        'profile_facts' => [],
        'closed_topics_log' => [],
        'conversation_window' => []
    ];
}

function append_chatlog(string $userId, array $entries): void
{
    $path = chatlog_file_path($userId);
    with_file_lock($path, function ($fp) use ($entries) {
        fseek($fp, 0, SEEK_END);
        foreach ($entries as $entry) {
            fwrite($fp, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        fflush($fp);
        return true;
    });
}

function read_chatlog_lines(string $userId): array
{
    $path = chatlog_file_path($userId);
    if (!file_exists($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $items = [];
    foreach ($lines as $line) {
        $obj = json_decode($line, true);
        if (is_array($obj)) {
            $items[] = $obj;
        }
    }
    return $items;
}
