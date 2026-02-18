<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'ok' => true,
        'endpoint' => 'chat',
        'reachable' => true,
        'allowed_method' => 'POST',
        'note' => 'Endpoint służy do streamingu SSE i wymaga metody POST.'
    ], 200);
}

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
$prompt = build_turn_prompt($settings, $topics, $state, $userMessage);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) { ob_end_flush(); }
ob_implicit_flush(true);

$apiKey = trim((string)($settings['openai_api_key'] ?? ''));
if ($apiKey === '') {
    echo "event: error\n";
    echo 'data: ' . json_encode(['message' => 'Brak OPENAI API key w ustawieniach admina.']) . "\n\n";
    flush();
    exit;
}

$apiBase = rtrim((string)($settings['openai_api_base'] ?? 'https://api.openai.com/v1'), '/');
$model = (string)($settings['openai_model'] ?? 'gpt-5-mini');
$effort = (string)($settings['openai_reasoning_effort'] ?? 'low');

$payload = [
    'model' => $model,
    'reasoning' => ['effort' => $effort],
    'stream' => true,
    'input' => [
        ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $prompt['system_prompt']]]],
        ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $prompt['turn_context']]]],
    ],
];




function request_repair_assistant_text(string $apiBase, string $apiKey, string $model, string $effort, string $systemPrompt, string $turnContext): ?string
{
    $repairPayload = [
        'model' => $model,
        'reasoning' => ['effort' => $effort],
        'stream' => false,
        'input' => [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $systemPrompt]]],
            ['role' => 'user', 'content' => [[
                'type' => 'input_text',
                'text' => "W poprzedniej odpowiedzi zabrakło treści [ASSISTANT_TEXT]. Wygeneruj wyłącznie brakującą naturalną odpowiedź asystenta (1-3 zdania, bez CONTROL_JSON), bazując na tym kontekście:

" . $turnContext,
            ]]],
        ],
    ];

    $chRepair = curl_init($apiBase . '/responses');
    curl_setopt_array($chRepair, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($repairPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $raw = curl_exec($chRepair);
    $err = curl_error($chRepair);
    $status = curl_getinfo($chRepair, CURLINFO_HTTP_CODE);
    curl_close($chRepair);

    if ($err || !is_string($raw) || $status >= 400) {
        return null;
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return null;
    }

    $text = trim((string)($parsed['output_text'] ?? ''));
    if ($text !== '') {
        return $text;
    }

    // Fallback dla struktur output/content.
    foreach (($parsed['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $content) {
            $candidate = trim((string)($content['text'] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }

    return null;
}



function detect_topic_achievement_with_llm(
    string $apiBase,
    string $apiKey,
    string $model,
    string $effort,
    array $activeTopic,
    string $userMessage,
    string $assistantText
): ?array {
    if (trim($apiKey) === '') {
        return null;
    }

    $topicId = (string)($activeTopic['topic_id'] ?? '');
    if ($topicId === '') {
        return null;
    }

    $title = (string)($activeTopic['title'] ?? $topicId);
    $goal = (string)($activeTopic['goal'] ?? '');
    $micro = (string)($activeTopic['micro_prompt'] ?? '');

    $judgePrompt = "Jesteś walidatorem domknięcia tematu rozmowy. Zwróć WYŁĄCZNIE JSON: {\"achieved\": true|false, \"confidence\": 0..1}.\n"
        . "Uznaj achieved=true tylko gdy odpowiedź użytkownika rzeczywiście spełnia cel aktywnego tematu.\n"
        . "Brak dodatkowego tekstu poza JSON.";

    $judgeInput = "TOPIC_ID: {$topicId}
TOPIC_TITLE: {$title}
TOPIC_GOAL: {$goal}
TOPIC_MICRO_PROMPT: {$micro}

USER_MESSAGE:
{$userMessage}

ASSISTANT_MESSAGE:
{$assistantText}";

    $payload = [
        'model' => $model,
        'reasoning' => ['effort' => $effort],
        'stream' => false,
        'input' => [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $judgePrompt]]],
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $judgeInput]]],
        ],
    ];

    $ch = curl_init($apiBase . '/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || !is_string($raw) || $status >= 400) {
        return null;
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return null;
    }

    $text = trim((string)($parsed['output_text'] ?? ''));
    if ($text === '') {
        foreach (($parsed['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                $candidate = trim((string)($content['text'] ?? ''));
                if ($candidate !== '') {
                    $text = $candidate;
                    break 2;
                }
            }
        }
    }
    if ($text === '') {
        return null;
    }

    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end >= $start) {
        $text = substr($text, $start, $end - $start + 1);
    }

    $json = json_decode($text, true);
    if (!is_array($json)) {
        return null;
    }

    $achieved = (bool)($json['achieved'] ?? false);
    $confidence = (float)($json['confidence'] ?? 0.0);
    return [
        'achieved' => $achieved,
        'confidence' => max(0.0, min(1.0, $confidence)),
    ];
}

function run_topic_summarizer(
    string $apiBase,
    string $apiKey,
    string $model,
    string $effort,
    string $summarizerPrompt,
    array $topic,
    string $userMessage,
    string $assistantText
): ?array {
    if (trim($summarizerPrompt) === '' || trim($apiKey) === '') {
        return null;
    }

    $topicTitle = (string)($topic['title'] ?? $topic['topic_id'] ?? 'temat');
    $topicGoal = (string)($topic['goal'] ?? '');
    $payload = [
        'model' => $model,
        'reasoning' => ['effort' => $effort],
        'stream' => false,
        'input' => [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $summarizerPrompt]]],
            ['role' => 'user', 'content' => [[
                'type' => 'input_text',
                'text' => "TOPIC_ID: " . (string)($topic['topic_id'] ?? '') . "
TOPIC_TITLE: {$topicTitle}
TOPIC_GOAL: {$topicGoal}

USER_MESSAGE:
{$userMessage}

ASSISTANT_MESSAGE:
{$assistantText}",
            ]]],
        ],
    ];

    $ch = curl_init($apiBase . '/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || !is_string($raw) || $status >= 400) {
        return null;
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return null;
    }

    $text = trim((string)($parsed['output_text'] ?? ''));
    if ($text === '') {
        foreach (($parsed['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                $candidate = trim((string)($content['text'] ?? ''));
                if ($candidate !== '') {
                    $text = $candidate;
                    break 2;
                }
            }
        }
    }

    if ($text === '') {
        return null;
    }

    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end >= $start) {
        $text = substr($text, $start, $end - $start + 1);
    }

    $json = json_decode($text, true);
    if (!is_array($json)) {
        return null;
    }

    $oneLiner = mb_substr(trim((string)($json['one_liner'] ?? '')), 0, 140);
    $factValue = isset($json['fact_value']) ? trim((string)$json['fact_value']) : '';
    return [
        'one_liner' => $oneLiner,
        'fact_value' => $factValue,
    ];
}


function compute_group_progress(array $groupMeta, array $topicState): array
{
    $required = (int)($groupMeta['min_points_to_advance'] ?? 0);
    $collected = 0;
    foreach (($groupMeta['topics'] ?? []) as $topic) {
        $tid = (string)($topic['topic_id'] ?? '');
        if ($tid !== '' && (($topicState[$tid]['status'] ?? '') === 'achieved')) {
            $collected += (int)($topic['weight'] ?? 0);
        }
    }
    return ['required' => $required, 'collected' => $collected];
}

function maybe_advance_group(array $topics, array &$state, int $now): ?array
{
    $stageId = (string)($state['stage_state']['stage_id'] ?? '');
    $groupId = (string)($state['stage_state']['group_id'] ?? '');
    if ($stageId === '' || $groupId === '') {
        return null;
    }

    $currentGroup = find_group_meta($topics, $stageId, $groupId);
    if (!$currentGroup) {
        return null;
    }

    $progress = compute_group_progress($currentGroup, $state['topic_state'] ?? []);
    if ($progress['collected'] < $progress['required']) {
        return null;
    }

    $groups = ordered_groups($topics);
    $currentIndex = null;
    foreach ($groups as $idx => $g) {
        if (($g['stage_id'] ?? '') === $stageId && ($g['group_id'] ?? '') === $groupId) {
            $currentIndex = $idx;
            break;
        }
    }
    if ($currentIndex === null) {
        return null;
    }
    $nextGroup = $groups[$currentIndex + 1] ?? null;
    if (!$nextGroup) {
        return null;
    }

    $summaryTopics = [];
    foreach (($currentGroup['topics'] ?? []) as $topic) {
        $tid = (string)($topic['topic_id'] ?? '');
        if ($tid !== '' && (($state['topic_state'][$tid]['status'] ?? '') === 'achieved')) {
            $summaryTopics[] = (string)($topic['title'] ?? $tid);
        }
    }

    $state['stage_state']['stage_id'] = (string)$nextGroup['stage_id'];
    $state['stage_state']['group_id'] = (string)$nextGroup['group_id'];
    $firstTopicId = first_topic_id_in_group($nextGroup);
    if ($firstTopicId) {
        $state['active_topic']['topic_id'] = $firstTopicId;
        $state['active_topic']['since'] = $now;
        $state['active_topic']['attempts'] = 0;
        $state['active_topic']['mode'] = 'normal';
        $state['active_topic']['pending_confirmation'] = null;
    }

    $state['pending_transition_announcement'] = [
        'from_group_title' => (string)($currentGroup['group_title'] ?? ''),
        'to_group_title' => (string)($nextGroup['group_title'] ?? ''),
        'achieved_topics' => array_slice($summaryTopics, -6),
        'from_points' => $progress['collected'],
        'required_points' => $progress['required'],
        'created_at' => $now,
    ];

    return $state['pending_transition_announcement'];
}

function extract_assistant_and_control(string $assistantRaw): array
{
    $control = [
        'next_active_topic_id' => null,
        'active_topic_action' => 'continue',
        'events' => [],
        'notes_to_add' => [],
    ];

    $assistantText = trim($assistantRaw);
    if (!str_contains($assistantRaw, '<<<CONTROL_JSON>>>')) {
        return [$assistantText, $control];
    }

    [$assistantPart, $controlPart] = explode('<<<CONTROL_JSON>>>', $assistantRaw, 2);
    $assistantText = trim(str_replace('[ASSISTANT_TEXT]', '', $assistantPart));

    $controlJsonRaw = trim($controlPart);
    $firstBrace = strpos($controlJsonRaw, '{');
    $lastBrace = strrpos($controlJsonRaw, '}');
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace >= $firstBrace) {
        $controlJsonRaw = substr($controlJsonRaw, $firstBrace, $lastBrace - $firstBrace + 1);
    }

    $parsed = json_decode($controlJsonRaw, true);
    if (is_array($parsed)) {
        $control = array_replace($control, $parsed);
    }

    // Fallback: jeżeli model zwrócił marker i JSON, ale bez treści assistant, nie wymazuj całej odpowiedzi.
    if ($assistantText === '') {
        $assistantText = trim(preg_replace('/<<<CONTROL_JSON>>>.*$/s', '', $assistantRaw) ?? '');
        $assistantText = trim(str_replace('[ASSISTANT_TEXT]', '', $assistantText));
    }

    return [$assistantText, $control];
}


function fallback_assistant_text(array $topics, array $state): string
{
    $activeTopicId = (string)($state['active_topic']['topic_id'] ?? '');
    $activeRef = $activeTopicId !== '' ? find_topic($topics, $activeTopicId) : null;
    $title = (string)($activeRef['topic']['title'] ?? 'kolejny krok');
    $micro = trim((string)($activeRef['topic']['micro_prompt'] ?? ''));
    $suffix = $micro !== '' ? (' ' . $micro) : '';
    return "Dzięki za odpowiedź — kontynuujmy temat: {$title}.{$suffix}";
}

$assistantRaw = '';
$buffer = '';
$hasStreamDelta = false;
$backendDebug = [
    'had_control_marker' => false,
    'assistant_raw_len' => 0,
    'assistant_text_len' => 0,
    'repair_attempted' => false,
    'repair_success' => false,
    'summarized_topics' => [],
    'topic_achievement_inferred' => false,
    'topic_achievement_inferred_confidence' => 0.0,
];
$ch = curl_init($apiBase . '/responses');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_WRITEFUNCTION => static function ($ch, $data) use (&$buffer, &$assistantRaw, &$hasStreamDelta) {
        $buffer .= $data;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);
            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }
            $json = trim(substr($line, 5));
            if ($json === '[DONE]') {
                return strlen($data);
            }
            $evt = json_decode($json, true);
            if (!is_array($evt)) {
                continue;
            }
            $type = (string)($evt['type'] ?? '');
            $delta = '';
            if ($type === 'response.output_text.delta') {
                $delta = (string)($evt['delta'] ?? '');
                if ($delta !== '') {
                    $hasStreamDelta = true;
                }
            } elseif ($type === 'response.output_text.done' && !$hasStreamDelta) {
                // Fallback tylko gdy upstream nie wysłał delta.
                $delta = (string)($evt['text'] ?? '');
            }
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

$assistantText = '';
$control = [];
[$assistantText, $control] = extract_assistant_and_control($assistantRaw);
$backendDebug['had_control_marker'] = str_contains($assistantRaw, '<<<CONTROL_JSON>>>');
$backendDebug['assistant_raw_len'] = mb_strlen($assistantRaw);
$backendDebug['assistant_text_len'] = mb_strlen($assistantText);

if ($assistantText === '' && $apiKey !== '') {
    $backendDebug['repair_attempted'] = true;
    $repair = request_repair_assistant_text($apiBase, $apiKey, $model, $effort, $prompt['system_prompt'], $prompt['turn_context']);
    if (is_string($repair) && trim($repair) !== '') {
        $assistantText = trim($repair);
        $backendDebug['repair_success'] = true;
    }
}

if ($assistantText === '') {
    $assistantText = fallback_assistant_text($topics, $state);
}
$backendDebug['assistant_text_len'] = mb_strlen($assistantText);

$now = time();
$convPairs = (int)($settings['conversation_window_pairs'] ?? 5);
$state['conversation_window'][] = ['ts' => $now, 'user' => $userMessage, 'assistant' => $assistantText];
$state['conversation_window'] = array_slice($state['conversation_window'], -$convPairs);
$state['last_active_ts'] = $now;
$state['active_topic']['attempts'] = (int)($state['active_topic']['attempts'] ?? 0) + 1;
$activeTopicId = (string)($state['active_topic']['topic_id'] ?? '');
if ($activeTopicId !== '') {
    $state['topic_state'][$activeTopicId]['status'] = $state['topic_state'][$activeTopicId]['status'] ?? 'in_progress';
    $state['topic_state'][$activeTopicId]['attempts'] = (int)($state['topic_state'][$activeTopicId]['attempts'] ?? 0) + 1;
}


$activeTopicRef = $activeTopicId !== '' ? find_topic($topics, $activeTopicId) : null;
$hasAchievedEvent = false;
foreach (($control['events'] ?? []) as $evt) {
    if (($evt['type'] ?? '') === 'topic_achieved') {
        $hasAchievedEvent = true;
        break;
    }
}

if (!$hasAchievedEvent && $activeTopicRef && (($activeTopicRef['topic']['type'] ?? '') === 'question_goal')) {
    $detected = detect_topic_achievement_with_llm(
        $apiBase,
        $apiKey,
        $model,
        $effort,
        $activeTopicRef['topic'],
        $userMessage,
        $assistantText
    );
    if (is_array($detected) && !empty($detected['achieved'])) {
        $control['events'][] = [
            'type' => 'topic_achieved',
            'topic_id' => (string)$activeTopicRef['topic']['topic_id'],
            'confidence' => (float)($detected['confidence'] ?? 0.7),
        ];
        $backendDebug['topic_achievement_inferred'] = true;
        $backendDebug['topic_achievement_inferred_confidence'] = (float)($detected['confidence'] ?? 0.7);
    }
}

$summarizerPrompt = trim((string)file_get_contents(DATA_DIR . '/prompt_summarizer.txt'));

foreach (($control['events'] ?? []) as $event) {
    if (($event['type'] ?? '') === 'topic_achieved' && !empty($event['topic_id'])) {
        $tid = (string)$event['topic_id'];
        $state['topic_state'][$tid] = [
            'status' => 'achieved',
            'attempts' => (int)($state['topic_state'][$tid]['attempts'] ?? 0),
            'achieved_at' => $now,
        ];
        $topicRef = find_topic($topics, $tid);
        if ($topicRef) {
            $summary = run_topic_summarizer(
                $apiBase,
                $apiKey,
                $model,
                $effort,
                $summarizerPrompt,
                $topicRef['topic'],
                $userMessage,
                $assistantText
            );

            $oneLiner = mb_substr('Domknięto temat: ' . ($topicRef['topic']['title'] ?? $tid), 0, 140);
            if (is_array($summary) && trim((string)($summary['one_liner'] ?? '')) !== '') {
                $oneLiner = (string)$summary['one_liner'];
            }
            $backendDebug['summarized_topics'][] = [
                'topic_id' => $tid,
                'summary_ok' => is_array($summary),
                'one_liner_len' => mb_strlen($oneLiner),
            ];

            if (!empty($topicRef['topic']['record_on_close'])) {
                $state['closed_topics_log'][] = ['topic_id' => $tid, 'created_at' => $now, 'text' => $oneLiner];
            }
            if (!empty($topicRef['topic']['fact_key'])) {
                $factKey = (string)$topicRef['topic']['fact_key'];
                $value = trim((string)($summary['fact_value'] ?? ''));
                if ($value === '' || strtolower($value) === 'null') {
                    $value = mb_substr($assistantText, 0, 120);
                }
                $existingIndex = null;
                foreach (($state['profile_facts'] ?? []) as $idx => $fact) {
                    if (($fact['fact_key'] ?? '') === $factKey) {
                        $existingIndex = $idx;
                        break;
                    }
                }
                $newFact = [
                    'fact_key' => $factKey,
                    'value' => $value,
                    'updated_at' => $now,
                    'source_topic_id' => $tid,
                    'confidence' => (float)($event['confidence'] ?? 0.6),
                ];
                if ($existingIndex === null) {
                    $state['profile_facts'][] = $newFact;
                } else {
                    $oldValue = (string)($state['profile_facts'][$existingIndex]['value'] ?? '');
                    if ($oldValue !== $value && !empty($topicRef['topic']['confirmation_required_on_change'])) {
                        $state['active_topic']['mode'] = 'confirmation_pending';
                        $state['active_topic']['pending_confirmation'] = [
                            'fact_key' => $factKey,
                            'old_value' => $oldValue,
                            'new_value' => $value,
                            'question' => 'Widzę inną wartość niż wcześniej. Czy chcesz zaktualizować tę informację?',
                        ];
                    } else {
                        $state['profile_facts'][$existingIndex] = $newFact;
                    }
                }
            }
        }
    }

    if (($event['type'] ?? '') === 'fact_change_proposed') {
        $state['active_topic']['mode'] = 'confirmation_pending';
        $state['active_topic']['pending_confirmation'] = [
            'fact_key' => $event['fact_key'] ?? '',
            'old_value' => $event['old_value'] ?? null,
            'new_value' => $event['new_value'] ?? '',
            'question' => $event['question'] ?? 'Czy potwierdzasz zmianę?',
        ];
    }
}


$revisitTopicId = '';
foreach (($control['events'] ?? []) as $event) {
    if (($event['type'] ?? '') === 'user_requested_past_topic' && !empty($event['topic_id'])) {
        $candidate = (string)$event['topic_id'];
        if (find_topic($topics, $candidate)) {
            $revisitTopicId = $candidate;
            break;
        }
    }
}
if ($revisitTopicId !== '') {
    $state['active_topic']['topic_id'] = $revisitTopicId;
    $state['active_topic']['since'] = $now;
    $state['active_topic']['attempts'] = 0;
    $state['active_topic']['mode'] = 'normal';
    $state['active_topic']['pending_confirmation'] = null;
    $state['topic_state'][$revisitTopicId]['status'] = 'in_progress';
}

maybe_advance_group($topics, $state, $now);

$maxAttempts = (int)($settings['max_attempts_before_switch'] ?? 2);
if (($control['active_topic_action'] ?? 'continue') === 'switch' || (int)($state['active_topic']['attempts'] ?? 0) > $maxAttempts) {
    $nextId = (string)($control['next_active_topic_id'] ?? '');
    if ($nextId === '') {
        foreach (pick_candidate_topics($topics, $state) as $candidate) {
            if (($candidate['topic_id'] ?? '') !== $activeTopicId) {
                $nextId = (string)$candidate['topic_id'];
                break;
            }
        }
    }
    if ($nextId !== '') {
        if ($activeTopicId !== '') {
            $state['topic_state'][$activeTopicId]['status'] = $state['topic_state'][$activeTopicId]['status'] ?? 'in_progress';
        }
        $state['active_topic']['topic_id'] = $nextId;
        $state['active_topic']['since'] = $now;
        $state['active_topic']['attempts'] = 0;
        $state['active_topic']['mode'] = 'normal';
        $state['active_topic']['pending_confirmation'] = null;
    }
}

$nextId = (string)($control['next_active_topic_id'] ?? '');
if ($nextId !== '' && ($control['active_topic_action'] ?? 'continue') !== 'switch') {
    $state['active_topic']['topic_id'] = $nextId;
    $state['active_topic']['since'] = $now;
}

atomic_write($userPath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
append_chatlog($userId, [
    ['ts' => $now, 'role' => 'user', 'text' => $userMessage],
    ['ts' => $now, 'role' => 'assistant', 'text' => $assistantText],
]);

$ui = ui_payload($topics, $state, $prompt['prompt_debug_text'], $settings, $userId);

echo "event: done\n";
$pendingTransition = $state['pending_transition_announcement'] ?? null;
if ($pendingTransition !== null) {
    $state['pending_transition_announcement'] = null;
    atomic_write($userPath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

echo 'data: ' . json_encode([
    'assistant_text' => $assistantText,
    'control_json' => $control,
    'sidebar' => $ui['sidebar'],
    'progress' => $ui['progress'],
    'prompt_debug_text' => $ui['prompt_debug_text'],
    'chatlog_tail' => $ui['chatlog_tail'],
    'summarizer_prompt_used' => $summarizerPrompt !== '',
    'backend_debug' => $backendDebug,
    'pending_transition_announcement' => $pendingTransition,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
flush();
