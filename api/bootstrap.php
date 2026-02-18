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

function default_settings(): array
{
    return [
        'openai_api_key' => '',
        'openai_api_base' => 'https://api.openai.com/v1',
        'openai_endpoint' => 'responses',
        'openai_model' => 'gpt-5-mini',
        'openai_reasoning_effort' => 'low',
        'conversation_window_pairs' => 5,
        'show_chatlog_lines_default' => 100,
        'closed_topics_prompt_limit' => 25,
        'profile_facts_limit' => 30,
        'max_attempts_before_switch' => 2,
        'diagnostics_enabled' => true,
        'greeting_first_visit_enabled' => true,
        'greeting_return_enabled' => true,
        'greeting_return_threshold_seconds' => 300,
    ];
}

function settings(): array
{
    return array_replace(default_settings(), read_json_file(DATA_DIR . '/settings.json', []));
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
            'group_id' => $group['group_id'],
        ],
        'active_topic' => [
            'topic_id' => $topic['topic_id'],
            'since' => $now,
            'attempts' => 0,
            'mode' => 'normal',
            'pending_confirmation' => null,
            'missing_info_hint' => null,
        ],
        'topic_state' => [],
        'profile_facts' => [],
        'closed_topics_log' => [],
        'conversation_window' => [],
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

function points_progress(array $topics, array $state): array
{
    $groupId = (string)($state['stage_state']['group_id'] ?? '');
    $topicState = $state['topic_state'] ?? [];
    $required = 0;
    $collected = 0;

    foreach ($topics['stages'] as $stage) {
        foreach ($stage['groups'] as $group) {
            if (($group['group_id'] ?? '') !== $groupId) {
                continue;
            }
            $required = (int)($group['min_points_to_advance'] ?? 0);
            foreach ($group['topics'] as $topic) {
                if (($topicState[$topic['topic_id']]['status'] ?? '') === 'achieved') {
                    $collected += (int)($topic['weight'] ?? 0);
                }
            }
        }
    }

    return ['collected' => $collected, 'required' => $required, 'missing' => max(0, $required - $collected)];
}

function pick_candidate_topics(array $topics, array $state): array
{
    $all = flatten_topics($topics);
    $topicState = $state['topic_state'] ?? [];
    $currentGroup = (string)($state['stage_state']['group_id'] ?? '');

    $current = array_values(array_filter($all, static function ($t) use ($currentGroup, $topicState) {
        $status = $topicState[$t['topic_id']]['status'] ?? 'not_started';
        return ($t['group_id'] ?? '') === $currentGroup && in_array($status, ['not_started', 'in_progress'], true);
    }));
    usort($current, static fn($a, $b) => (int)($b['weight'] ?? 0) <=> (int)($a['weight'] ?? 0));

    $previous = array_values(array_filter($all, static function ($t) use ($currentGroup, $topicState) {
        $status = $topicState[$t['topic_id']]['status'] ?? 'not_started';
        return ($t['group_id'] ?? '') !== $currentGroup && in_array($status, ['not_started', 'in_progress'], true);
    }));
    usort($previous, static fn($a, $b) => (int)($b['weight'] ?? 0) <=> (int)($a['weight'] ?? 0));

    $wild = $all;
    shuffle($wild);

    $out = array_slice($current, 0, 7);
    $out = array_merge($out, array_slice($previous, 0, 2), array_slice($wild, 0, 1));

    $seen = [];
    $out = array_values(array_filter($out, static function ($t) use (&$seen) {
        $id = (string)$t['topic_id'];
        if (isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;
        return true;
    }));

    while (count($out) < 3 && count($all) > count($out)) {
        foreach ($all as $topic) {
            if (!isset($seen[$topic['topic_id']])) {
                $seen[$topic['topic_id']] = true;
                $out[] = $topic;
                if (count($out) >= 3) {
                    break;
                }
            }
        }
    }

    return array_slice($out, 0, 10);
}

function build_turn_prompt(array $settings, array $topics, array $state, string $userMessage): array
{
    [$stage, $group, $firstTopic] = get_first_topic($topics);
    $activeTopicId = (string)($state['active_topic']['topic_id'] ?? $firstTopic['topic_id']);
    $activeRef = find_topic($topics, $activeTopicId) ?? ['stage' => $stage, 'group' => $group, 'topic' => $firstTopic];
    $activeTopic = $activeRef['topic'];

    $topicStatus = $state['topic_state'][$activeTopic['topic_id']]['status'] ?? 'not_started';
    $progress = points_progress($topics, $state);
    $candidateTopics = pick_candidate_topics($topics, $state);

    $closedLimit = (int)($settings['closed_topics_prompt_limit'] ?? 25);
    $factsLimit = (int)($settings['profile_facts_limit'] ?? 30);
    $convPairs = (int)($settings['conversation_window_pairs'] ?? 5);

    $profileFacts = array_slice($state['profile_facts'] ?? [], -$factsLimit);
    $closed = array_slice($state['closed_topics_log'] ?? [], -$closedLimit);
    $window = array_slice($state['conversation_window'] ?? [], -$convPairs);

    $turnContext = [];
    $turnContext[] = 'ACTIVE_TOPIC:';
    $turnContext[] = 'topic_id: ' . $activeTopic['topic_id'];
    $turnContext[] = 'title: ' . $activeTopic['title'];
    $turnContext[] = 'goal: ' . ($activeTopic['goal'] ?? '');
    $turnContext[] = 'micro_prompt: ' . ($activeTopic['micro_prompt'] ?? '');
    $turnContext[] = 'status: ' . $topicStatus;
    $turnContext[] = 'attempts: ' . (int)($state['active_topic']['attempts'] ?? 0);
    $turnContext[] = 'missing_info_hint: ' . (string)($state['active_topic']['missing_info_hint'] ?? '');
    $turnContext[] = 'pending_confirmation: ' . json_encode($state['active_topic']['pending_confirmation'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $turnContext[] = '';
    $turnContext[] = 'PROGRESS:';
    $turnContext[] = 'stage_id: ' . ($state['stage_state']['stage_id'] ?? $stage['stage_id']);
    $turnContext[] = 'group_id: ' . ($state['stage_state']['group_id'] ?? $group['group_id']);
    $turnContext[] = 'points_collected: ' . $progress['collected'];
    $turnContext[] = 'points_required: ' . $progress['required'];
    $turnContext[] = 'points_missing: ' . $progress['missing'];
    $turnContext[] = '';
    $turnContext[] = 'PROFILE_FACTS:';
    foreach ($profileFacts as $fact) {
        $turnContext[] = ($fact['fact_key'] ?? 'fact') . ': ' . ($fact['value'] ?? '');
    }
    $turnContext[] = '';
    $turnContext[] = 'CLOSED_TOPICS:';
    foreach ($closed as $entry) {
        $turnContext[] = ($entry['topic_id'] ?? '-') . ' | ' . ($entry['created_at'] ?? '') . ' | ' . ($entry['text'] ?? '');
    }
    $turnContext[] = '';
    $turnContext[] = 'CONVERSATION_WINDOW:';
    foreach ($window as $pair) {
        $turnContext[] = 'U: ' . ($pair['user'] ?? '');
        $turnContext[] = 'A: ' . ($pair['assistant'] ?? '');
        $turnContext[] = '---';
    }
    $turnContext[] = '';
    $turnContext[] = 'CANDIDATE_TOPICS:';
    foreach ($candidateTopics as $ct) {
        $turnContext[] = 'topic_id: ' . $ct['topic_id'];
        $turnContext[] = 'title: ' . $ct['title'];
        $turnContext[] = 'weight: ' . (int)($ct['weight'] ?? 0);
        $turnContext[] = 'required: ' . (!empty($ct['required']) ? 'true' : 'false');
        $turnContext[] = 'type: ' . ($ct['type'] ?? 'question_goal');
        $turnContext[] = 'goal: ' . ($ct['goal'] ?? '');
        $turnContext[] = 'micro_prompt: ' . ($ct['micro_prompt'] ?? '');
        $turnContext[] = '';
    }
    $turnContext[] = 'USER_MESSAGE:';
    $turnContext[] = $userMessage;

    $mainPrompt = trim((string)file_get_contents(DATA_DIR . '/prompt_main.txt'));
    $turnText = implode("\n", $turnContext);
    $fullPromptForPreview = "SYSTEM PROMPT:\n{$mainPrompt}\n\nTURN CONTEXT:\n{$turnText}";

    return [
        'system_prompt' => $mainPrompt,
        'turn_context' => $turnText,
        'prompt_debug_text' => $fullPromptForPreview,
        'active_topic' => $activeTopic,
        'candidate_topics' => $candidateTopics,
        'progress' => $progress,
    ];
}

function ui_payload(array $topics, array $state, string $promptDebugText, array $settings, string $userId): array
{
    [$stage, $group, $firstTopic] = get_first_topic($topics);
    $activeId = (string)($state['active_topic']['topic_id'] ?? $firstTopic['topic_id']);
    $activeRef = find_topic($topics, $activeId) ?? ['topic' => $firstTopic, 'stage' => $stage, 'group' => $group];
    $candidates = pick_candidate_topics($topics, $state);
    $chatlog = read_chatlog_lines($userId);
    $limit = (int)($settings['show_chatlog_lines_default'] ?? 100);
    $progress = points_progress($topics, $state);

    return [
        'sidebar' => [
            'active_topic_title' => (string)($activeRef['topic']['title'] ?? ''),
            'active_topic_id' => $activeId,
            'upcoming_topics' => array_map(static fn($t) => [
                'topic_id' => $t['topic_id'],
                'title' => $t['title'],
                'weight' => (int)($t['weight'] ?? 0),
            ], array_slice($candidates, 0, 3)),
            'oneliners' => array_slice(array_values(array_map(static fn($entry) => [
                'topic_id' => $entry['topic_id'] ?? '',
                'text' => $entry['text'] ?? '',
                'created_at' => $entry['created_at'] ?? null,
            ], $state['closed_topics_log'] ?? [])), -10),
        ],
        'progress' => [
            'points_collected' => $progress['collected'],
            'points_required' => $progress['required'],
            'points_missing' => $progress['missing'],
            'stage_id' => (string)($state['stage_state']['stage_id'] ?? $stage['stage_id']),
            'group_id' => (string)($state['stage_state']['group_id'] ?? $group['group_id']),
        ],
        'prompt_debug_text' => $promptDebugText,
        'chatlog_tail' => array_slice($chatlog, -$limit),
    ];
}
