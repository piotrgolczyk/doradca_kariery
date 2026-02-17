<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$userId = trim((string)($input['user_id'] ?? ''));
$userMessage = trim((string)($input['user_message'] ?? ''));

if ($userId === '' || $userMessage === '') {
    json_response(['error' => 'Brak user_id albo user_message'], 422);
}

$settings = settings();
$topics = topics_data();
$userPath = user_file_path($userId);
if (!file_exists($userPath)) {
    json_response(['error' => 'Nieznany user_id'], 404);
}

$state = read_json_file($userPath, []);
$mainPrompt = trim((string)@file_get_contents(DATA_DIR . '/prompt_main.txt'));

function pick_candidate_topics(array $topics, array $state): array {
    $all = flatten_topics($topics);
    $topicState = $state['topic_state'] ?? [];
    $currentGroup = (string)($state['stage_state']['group_id'] ?? '');

    $current = array_values(array_filter($all, function ($t) use ($currentGroup, $topicState) {
        $status = $topicState[$t['topic_id']]['status'] ?? 'not_started';
        return ($t['group_id'] ?? '') === $currentGroup && in_array($status, ['not_started', 'in_progress'], true);
    }));
    usort($current, fn($a, $b) => ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0));

    $previous = array_values(array_filter($all, function ($t) use ($currentGroup, $topicState) {
        $status = $topicState[$t['topic_id']]['status'] ?? 'not_started';
        return ($t['group_id'] ?? '') !== $currentGroup && in_array($status, ['not_started', 'in_progress'], true);
    }));
    shuffle($previous);

    $wild = $all;
    shuffle($wild);

    $out = array_slice($current, 0, 7);
    $out = array_merge($out, array_slice($previous, 0, 2), array_slice($wild, 0, 1));

    $seen = [];
    return array_values(array_filter($out, function ($t) use (&$seen) {
        $id = $t['topic_id'];
        if (isset($seen[$id])) return false;
        $seen[$id] = true;
        return true;
    }));
}

function points_progress(array $topics, array $state): array {
    $groupId = (string)($state['stage_state']['group_id'] ?? '');
    $topicState = $state['topic_state'] ?? [];
    $required = 0;
    $collected = 0;
    foreach ($topics['stages'] as $stage) {
        foreach ($stage['groups'] as $group) {
            if (($group['group_id'] ?? '') !== $groupId) continue;
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

$activeTopicId = (string)($state['active_topic']['topic_id'] ?? '');
$activeRef = find_topic($topics, $activeTopicId) ?? find_topic($topics, get_first_topic($topics)[2]['topic_id']);
$activeTopic = $activeRef['topic'];
$topicStatus = $state['topic_state'][$activeTopic['topic_id']]['status'] ?? 'not_started';
$candidateTopics = pick_candidate_topics($topics, $state);
$progress = points_progress($topics, $state);

$closedLimit = (int)($settings['closed_topics_prompt_limit'] ?? 25);
$factsLimit = (int)($settings['profile_facts_limit'] ?? 30);
$convPairs = (int)($settings['conversation_window_pairs'] ?? 5);

$profileFacts = array_slice($state['profile_facts'] ?? [], -$factsLimit);
$closed = array_slice($state['closed_topics_log'] ?? [], -$closedLimit);
$window = array_slice($state['conversation_window'] ?? [], -$convPairs);

$turnContext = [];
$turnContext[] = "ACTIVE_TOPIC:";
$turnContext[] = "topic_id: {$activeTopic['topic_id']}";
$turnContext[] = "title: {$activeTopic['title']}";
$turnContext[] = "goal: " . ($activeTopic['goal'] ?? '');
$turnContext[] = "micro_prompt: " . ($activeTopic['micro_prompt'] ?? '');
$turnContext[] = "status: {$topicStatus}";
$turnContext[] = "attempts: " . (int)($state['active_topic']['attempts'] ?? 0);
$turnContext[] = "missing_info_hint: " . (string)($state['active_topic']['missing_info_hint'] ?? '');
$turnContext[] = "pending_confirmation: " . json_encode($state['active_topic']['pending_confirmation'] ?? null, JSON_UNESCAPED_UNICODE);
$turnContext[] = "";
$turnContext[] = "PROGRESS:";
$turnContext[] = "stage_id: " . ($state['stage_state']['stage_id'] ?? '');
$turnContext[] = "group_id: " . ($state['stage_state']['group_id'] ?? '');
$turnContext[] = "points_collected: {$progress['collected']}";
$turnContext[] = "points_required: {$progress['required']}";
$turnContext[] = "points_missing: {$progress['missing']}";
$turnContext[] = "";
$turnContext[] = "PROFILE_FACTS:";
foreach ($profileFacts as $fact) {
    $turnContext[] = ($fact['fact_key'] ?? 'fact') . ': ' . ($fact['value'] ?? '');
}
$turnContext[] = "";
$turnContext[] = "CLOSED_TOPICS:";
foreach ($closed as $entry) {
    $turnContext[] = ($entry['topic_id'] ?? '-') . ' | ' . ($entry['created_at'] ?? '') . ' | ' . ($entry['text'] ?? '');
}
$turnContext[] = "";
$turnContext[] = "CONVERSATION_WINDOW:";
foreach ($window as $pair) {
    $turnContext[] = 'U: ' . ($pair['user'] ?? '');
    $turnContext[] = 'A: ' . ($pair['assistant'] ?? '');
    $turnContext[] = '---';
}
$turnContext[] = "";
$turnContext[] = "CANDIDATE_TOPICS:";
foreach ($candidateTopics as $ct) {
    $turnContext[] = "topic_id: {$ct['topic_id']}";
    $turnContext[] = "title: {$ct['title']}";
    $turnContext[] = "weight: {$ct['weight']}";
    $turnContext[] = "required: " . (($ct['required'] ?? false) ? 'true' : 'false');
    $turnContext[] = "type: {$ct['type']}";
    $turnContext[] = "goal: " . ($ct['goal'] ?? '');
    $turnContext[] = "micro_prompt: " . ($ct['micro_prompt'] ?? '');
    $turnContext[] = "";
}
$turnContext[] = "USER_MESSAGE:";
$turnContext[] = $userMessage;

$turnText = implode("\n", $turnContext);
$fullPromptForPreview = "SYSTEM PROMPT:\n{$mainPrompt}\n\nTURN CONTEXT:\n{$turnText}";

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$apiKey = trim((string)($settings['openai_api_key'] ?? ''));
if ($apiKey === '') {
    echo "event: error\n";
    echo 'data: ' . json_encode(['message' => 'Brak OPENAI API key w ustawieniach admina.']) . "\n\n";
    flush();
    exit;
}

$apiBase = rtrim((string)($settings['openai_api_base'] ?? 'https://api.openai.com/v1'), '/');
$model = (string)($settings['openai_model'] ?? 'gpt-4.1-mini');

$payload = [
    'model' => $model,
    'stream' => true,
    'messages' => [
        ['role' => 'system', 'content' => $mainPrompt],
        ['role' => 'user', 'content' => $turnText]
    ]
];

$assistantRaw = '';
$buffer = '';
$ch = curl_init($apiBase . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, &$assistantRaw) {
        $buffer .= $data;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);
            if ($line === '' || !str_starts_with($line, 'data:')) continue;
            $json = trim(substr($line, 5));
            if ($json === '[DONE]') {
                return strlen($data);
            }
            $evt = json_decode($json, true);
            $delta = $evt['choices'][0]['delta']['content'] ?? '';
            if ($delta !== '') {
                $assistantRaw .= $delta;
                echo 'data: ' . json_encode(['token' => $delta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                flush();
            }
        }
        return strlen($data);
    },
    CURLOPT_TIMEOUT => 120,
    CURLOPT_RETURNTRANSFER => false,
]);

curl_exec($ch);
$curlErr = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
    echo "event: error\n";
    echo 'data: ' . json_encode(['message' => 'Błąd połączenia z OpenAI: ' . $curlErr]) . "\n\n";
    flush();
    exit;
}
if ($status >= 400) {
    echo "event: error\n";
    echo 'data: ' . json_encode(['message' => 'OpenAI HTTP ' . $status]) . "\n\n";
    flush();
    exit;
}

$assistantText = $assistantRaw;
$control = [
    'next_active_topic_id' => null,
    'active_topic_action' => 'continue',
    'events' => [],
    'notes_to_add' => []
];
if (str_contains($assistantRaw, '<<<CONTROL_JSON>>>')) {
    [$assistantTextPart, $controlPart] = explode('<<<CONTROL_JSON>>>', $assistantRaw, 2);
    $assistantText = trim($assistantTextPart);
    $parsed = json_decode(trim($controlPart), true);
    if (is_array($parsed)) {
        $control = array_merge($control, $parsed);
    }
}

$now = time();
$state['conversation_window'][] = ['ts' => $now, 'user' => $userMessage, 'assistant' => $assistantText];
$state['conversation_window'] = array_slice($state['conversation_window'], -$convPairs);
$state['last_active_ts'] = $now;
$state['active_topic']['attempts'] = (int)($state['active_topic']['attempts'] ?? 0) + 1;

foreach (($control['events'] ?? []) as $event) {
    if (($event['type'] ?? '') === 'topic_achieved' && !empty($event['topic_id'])) {
        $tid = $event['topic_id'];
        $state['topic_state'][$tid] = [
            'status' => 'achieved',
            'attempts' => ($state['topic_state'][$tid]['attempts'] ?? 0) + 1,
            'achieved_at' => $now
        ];
        $topicRef = find_topic($topics, $tid);
        if ($topicRef && ($topicRef['topic']['record_on_close'] ?? false)) {
            $line = 'Domknięto temat: ' . ($topicRef['topic']['title'] ?? $tid);
            $state['closed_topics_log'][] = ['topic_id' => $tid, 'created_at' => $now, 'text' => $line];
        }
    }
    if (($event['type'] ?? '') === 'fact_change_proposed') {
        $state['active_topic']['mode'] = 'confirmation_pending';
        $state['active_topic']['pending_confirmation'] = [
            'fact_key' => $event['fact_key'] ?? '',
            'old_value' => $event['old_value'] ?? null,
            'new_value' => $event['new_value'] ?? '',
            'question' => $event['question'] ?? 'Czy potwierdzasz zmianę?'
        ];
    }
}

$nextId = $control['next_active_topic_id'] ?? null;
if (is_string($nextId) && $nextId !== '') {
    $state['active_topic']['topic_id'] = $nextId;
    $state['active_topic']['since'] = $now;
}

atomic_write($userPath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
append_chatlog($userId, [
    ['ts' => $now, 'role' => 'user', 'text' => $userMessage],
    ['ts' => $now, 'role' => 'assistant', 'text' => $assistantText]
]);

echo "event: done\n";
echo 'data: ' . json_encode([
    'assistant_text' => $assistantText,
    'control_json' => $control,
    'state' => $state,
    'prompt_preview' => $fullPromptForPreview,
    'progress' => points_progress($topics, $state),
    'candidate_topics' => array_slice($candidateTopics, 0, 3)
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
flush();
