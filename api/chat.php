<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$userId = trim((string)($input['user_id'] ?? ''));
$userMessage = trim((string)($input['user_message'] ?? ''));

if ($userId === '' || $userMessage === '') {
    sendJson(['error' => 'user_id and user_message are required'], 422);
}

$topicsData = readJsonFile(TOPICS_FILE, []);
$prompts = getPrompts();
$promptMain = $prompts['prompt_main'];
$summarizerSystem = $prompts['summarizer_system'];
$flatTopics = flattenTopics($topicsData);
$userPath = userFilePath($userId);

$apiKey = resolveApiKey();
if ($apiKey === '') {
    sendJson(['error' => 'OPENAI_API_KEY missing'], 500);
}

if (!file_exists($userPath)) {
    sendJson(['error' => 'User not found'], 404);
}

function pickCandidateTopics(array $userState, array $topicsData, array $flatTopics): array
{
    $currentStage = $userState['stage_state']['stage_id'] ?? null;
    $currentGroup = $userState['stage_state']['group_id'] ?? null;
    $topicState = $userState['topic_state'] ?? [];

    $current = [];
    $backlog = [];
    $allOpen = [];

    foreach ($flatTopics as $topicId => $entry) {
        $status = $topicState[$topicId]['status'] ?? 'not_started';
        if ($status === 'achieved') {
            continue;
        }

        $topic = $entry['topic'];
        $row = [
            'topic_id' => $topicId,
            'title' => $topic['title'] ?? '',
            'weight' => $topic['weight'] ?? 1,
            'goal' => $topic['goal'] ?? '',
            'type' => $topic['type'] ?? 'question_goal',
            'micro_prompt' => $topic['micro_prompt'] ?? '',
            'stage_id' => $entry['stage_id'],
            'group_id' => $entry['group_id'],
        ];
        $allOpen[] = $row;

        if ($entry['stage_id'] === $currentStage && $entry['group_id'] === $currentGroup) {
            $current[] = $row;
        } else {
            $backlog[] = $row;
        }
    }

    usort($current, fn($a, $b) => ($b['weight'] <=> $a['weight']));
    $selected = array_slice($current, 0, 7);

    if (count($backlog) > 0) {
        shuffle($backlog);
        usort($backlog, fn($a, $b) => ($b['weight'] <=> $a['weight']));
        $selected = array_merge($selected, array_slice($backlog, 0, 2));
    }

    $pool = array_values(array_filter($allOpen, function ($topic) use ($selected) {
        foreach ($selected as $picked) {
            if ($picked['topic_id'] === $topic['topic_id']) {
                return false;
            }
        }
        return true;
    }));
    if (count($pool) > 0) {
        $selected[] = $pool[array_rand($pool)];
    }

    $activeTopicId = $userState['active_topic']['topic_id'] ?? null;
    if ($activeTopicId && isset($flatTopics[$activeTopicId])) {
        $already = false;
        foreach ($selected as $item) {
            if ($item['topic_id'] === $activeTopicId) {
                $already = true;
                break;
            }
        }
        if (!$already) {
            array_unshift($selected, [
                'topic_id' => $activeTopicId,
                'title' => $flatTopics[$activeTopicId]['topic']['title'] ?? '',
                'weight' => $flatTopics[$activeTopicId]['topic']['weight'] ?? 1,
                'goal' => $flatTopics[$activeTopicId]['topic']['goal'] ?? '',
                'type' => $flatTopics[$activeTopicId]['topic']['type'] ?? 'question_goal',
                'micro_prompt' => $flatTopics[$activeTopicId]['topic']['micro_prompt'] ?? '',
                'stage_id' => $flatTopics[$activeTopicId]['stage_id'],
                'group_id' => $flatTopics[$activeTopicId]['group_id'],
            ]);
        }
    }

    return array_slice($selected, 0, 10);
}

function callOpenAi(string $apiKey, array $messages, bool $stream, ?callable $onChunk = null): string
{
    $url = 'https://api.openai.com/v1/chat/completions';
    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'temperature' => 0.4,
        'stream' => $stream,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => !$stream,
        CURLOPT_TIMEOUT => 120,
    ]);

    $fullText = '';

    if ($stream) {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$fullText, $onChunk) {
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }
                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    continue;
                }
                $decoded = json_decode($json, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $delta = $decoded['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $fullText .= $delta;
                    if ($onChunk) {
                        $onChunk($delta, $fullText);
                    }
                }
            }
            return strlen($data);
        });
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($err);
    }

    if (!$stream) {
        $decoded = json_decode($response, true);
        $fullText = $decoded['choices'][0]['message']['content'] ?? '';
    }

    curl_close($ch);
    return $fullText;
}

function upsertFact(array &$profileFacts, string $factKey, string $value, string $topicId, float $confidence): void
{
    foreach ($profileFacts as &$fact) {
        if (($fact['fact_key'] ?? '') === $factKey) {
            $fact['value'] = $value;
            $fact['updated_at'] = nowIso();
            $fact['source_topic_id'] = $topicId;
            $fact['confidence'] = $confidence;
            return;
        }
    }

    $profileFacts[] = [
        'fact_key' => $factKey,
        'value' => $value,
        'updated_at' => nowIso(),
        'source_topic_id' => $topicId,
        'confidence' => $confidence,
    ];

    if (count($profileFacts) > 30) {
        $profileFacts = array_slice($profileFacts, -30);
    }
}



function getFactValueFromProfile(array $profileFacts, string $factKey): ?string
{
    foreach ($profileFacts as $fact) {
        if (($fact['fact_key'] ?? '') === $factKey) {
            return isset($fact['value']) ? (string)$fact['value'] : null;
        }
    }
    return null;
}

function proposeFactConflict(array &$userState, string $factKey, string $oldValue, string $newValue, string $topicId, float $confidence): string
{
    $userState['active_topic']['mode'] = 'confirmation_pending';
    $userState['active_topic']['pending_confirmation'] = [
        'fact_key' => $factKey,
        'old_value' => $oldValue,
        'new_value' => $newValue,
        'topic_id' => $topicId,
        'confidence' => $confidence,
    ];

    return "Wykryłem zmianę dla pola '{$factKey}': '{$oldValue}' -> '{$newValue}'. Czy mam zaktualizować tę wartość?";
}

function applyFactWithConflictCheck(array &$userState, string $factKey, string $newValue, string $topicId, float $confidence): ?string
{
    $normalized = trim($newValue);
    if ($normalized === '') {
        return null;
    }

    $current = getFactValueFromProfile($userState['profile_facts'] ?? [], $factKey);
    if ($current !== null && mb_strtolower(trim($current)) !== mb_strtolower($normalized)) {
        return proposeFactConflict($userState, $factKey, trim($current), $normalized, $topicId, $confidence);
    }

    upsertFact($userState['profile_facts'], $factKey, $normalized, $topicId, $confidence);
    return null;
}

function closeTopicWithFallback(array &$userState, array $flatTopics, string $topicId, string $fallbackText): void
{
    if (!isset($flatTopics[$topicId])) {
        return;
    }

    $topic = $flatTopics[$topicId]['topic'];
    $userState['topic_state'][$topicId]['status'] = 'achieved';
    $userState['topic_state'][$topicId]['achieved_at'] = nowIso();

    if (($topic['record_on_close'] ?? true) === true) {
        $userState['closed_topics_log'][] = [
            'topic_id' => $topicId,
            'created_at' => nowIso(),
            'text' => $fallbackText,
        ];
        $userState['closed_topics_log'] = array_slice($userState['closed_topics_log'], -100);
    }
}
$chatResult = withLockedJsonFile($userPath, function (array $userState) use ($userId, $userMessage, $promptMain, $summarizerSystem, $apiKey, $flatTopics, $topicsData): array {
    $last = strtotime((string)($userState['rate_limit']['last_request_at'] ?? ''));
    if ($last && (time() - $last < 2)) {
        sendJson(['error' => 'Too many requests'], 429);
    }

    appendConversationEntry($userId, 'user', $userMessage);

    $pending = $userState['active_topic']['pending_confirmation'] ?? null;
    if ($pending) {
        $answer = mb_strtolower(trim($userMessage));
        if (in_array($answer, ['tak', 'yes', 'y', 'potwierdzam'], true)) {
            upsertFact(
                $userState['profile_facts'],
                $pending['fact_key'],
                $pending['new_value'],
                $pending['topic_id'],
                (float)($pending['confidence'] ?? 0.8)
            );
            $userState['active_topic']['pending_confirmation'] = null;
            $userState['active_topic']['mode'] = 'normal';
            $confirmMessage = 'Dzięki, zaktualizowałem to. Lecimy dalej.';
            $userState['conversation_window'][] = ['ts' => nowIso(), 'user' => $userMessage, 'assistant' => $confirmMessage];
            $userState['conversation_window'] = array_slice($userState['conversation_window'], -5);
            $userState['rate_limit']['last_request_at'] = nowIso();
            appendConversationEntry($userId, 'assistant', $confirmMessage);
            return ['data' => $userState, 'return' => ['immediate_text' => $confirmMessage]];
        }
        if (in_array($answer, ['nie', 'no', 'n'], true)) {
            $userState['active_topic']['pending_confirmation'] = null;
            $userState['active_topic']['mode'] = 'normal';
            $confirmMessage = 'Jasne, zostawiam poprzednią wartość. Dzięki za doprecyzowanie.';
            $userState['conversation_window'][] = ['ts' => nowIso(), 'user' => $userMessage, 'assistant' => $confirmMessage];
            $userState['conversation_window'] = array_slice($userState['conversation_window'], -5);
            $userState['rate_limit']['last_request_at'] = nowIso();
            appendConversationEntry($userId, 'assistant', $confirmMessage);
            return ['data' => $userState, 'return' => ['immediate_text' => $confirmMessage]];
        }
    }

    $candidateTopics = pickCandidateTopics($userState, $topicsData, $flatTopics);
    $activeTopicId = $userState['active_topic']['topic_id'] ?? null;
    $activeTopicMeta = $flatTopics[$activeTopicId]['topic'] ?? [];

    $promptInput = "ACTIVE_TOPIC:\n" . json_encode([
        'topic_id' => $activeTopicId,
        'mode' => $userState['active_topic']['mode'] ?? 'normal',
        'attempts' => $userState['active_topic']['attempts'] ?? 0,
        'goal' => $activeTopicMeta['goal'] ?? '',
        'micro_prompt' => $activeTopicMeta['micro_prompt'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

    $promptInput .= "PROFILE_FACTS (max 30):\n" . json_encode(array_slice($userState['profile_facts'] ?? [], -30), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    $promptInput .= "CLOSED_TOPICS (most recent 25):\n" . json_encode(array_slice($userState['closed_topics_log'] ?? [], -25), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    $promptInput .= "CONVERSATION_WINDOW (max 5 pairs):\n" . json_encode(array_slice($userState['conversation_window'] ?? [], -5), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    $promptInput .= "CANDIDATE_TOPICS (10):\n" . json_encode($candidateTopics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    $promptInput .= "USER_MESSAGE:\n" . $userMessage;

    $messages = [
        ['role' => 'system', 'content' => $promptMain],
        ['role' => 'user', 'content' => $promptInput],
    ];

    $userState['last_api_payload'] = [
        'updated_at' => nowIso(),
        'endpoint' => 'chat.completions',
        'model' => 'gpt-4o-mini',
        'temperature' => 0.4,
        'stream' => true,
        'messages' => $messages,
    ];

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $bufferedText = '';
    $sentLength = 0;

    try {
        $fullResponse = callOpenAi($apiKey, $messages, true, function (string $delta, string $full) use (&$bufferedText, &$sentLength) {
            $bufferedText = $full;
            $delimiter = '<<<CONTROL_JSON>>>';
            $delimiterPos = strpos($bufferedText, $delimiter);

            if ($delimiterPos === false) {
                $safeLen = max(0, strlen($bufferedText) - (strlen($delimiter) - 1));
                $safeText = substr($bufferedText, 0, $safeLen);
            } else {
                $safeText = substr($bufferedText, 0, $delimiterPos);
            }

            $toSend = substr($safeText, $sentLength);
            if ($toSend !== '') {
                echo $toSend;
                $sentLength += strlen($toSend);
                @ob_flush();
                flush();
            }
        });

        $delimiter = '<<<CONTROL_JSON>>>';
        $delimiterPos = strpos($bufferedText, $delimiter);
        if ($delimiterPos === false) {
            $finalText = $bufferedText;
        } else {
            $finalText = substr($bufferedText, 0, $delimiterPos);
        }
        $toSendFinal = substr($finalText, $sentLength);
        if ($toSendFinal !== '') {
            echo $toSendFinal;
            @ob_flush();
            flush();
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Wystąpił błąd podczas generowania odpowiedzi.';
        error_log(nowIso() . ' chat_error ' . $e->getMessage() . "\n", 3, ERRORS_LOG);
        return ['data' => $userState];
    }

    $parsed = parseControlJson($fullResponse);
    $assistantText = $parsed['assistant_text'];
    $control = $parsed['control_json'];

    appendConversationEntry($userId, 'assistant', $assistantText);

    $userState['conversation_window'][] = ['ts' => nowIso(), 'user' => $userMessage, 'assistant' => $assistantText];
    $userState['conversation_window'] = array_slice($userState['conversation_window'], -5);

    $achievedTopicIds = [];

    foreach (($control['events'] ?? []) as $event) {
        if (($event['type'] ?? '') === 'topic_achieved') {
            $topicId = $event['topic_id'] ?? null;
            if (!$topicId || !isset($flatTopics[$topicId])) {
                continue;
            }

            $achievedTopicIds[] = $topicId;
            $topic = $flatTopics[$topicId]['topic'];
            $userState['topic_state'][$topicId]['status'] = 'achieved';
            $userState['topic_state'][$topicId]['achieved_at'] = nowIso();

            $sumInput = "TOPIC_ID: {$topicId}\nTOPIC_GOAL: " . ($topic['goal'] ?? '') . "\nFACT_KEY: " . ($topic['fact_key'] ?? 'null') . "\nUSER_CONTEXT:\n- last_user_message: {$userMessage}\n- conversation_window: " . json_encode($userState['conversation_window'], JSON_UNESCAPED_UNICODE);
            $sumMessages = [
                ['role' => 'system', 'content' => $summarizerSystem],
                ['role' => 'user', 'content' => $sumInput],
            ];

            try {
                $sumRaw = callOpenAi($apiKey, $sumMessages, false);
                $sum = json_decode(trim($sumRaw), true);
            } catch (Throwable) {
                $sum = null;
            }

            if (!is_array($sum)) {
                $sum = [
                    'one_liner' => ($topic['title'] ?? $topicId) . ' - domknięty.',
                    'fact_key' => $topic['fact_key'] ?? null,
                    'fact_value' => null,
                    'confidence' => 0.5,
                ];
            }

            if (($topic['record_on_close'] ?? true) === true) {
                $userState['closed_topics_log'][] = [
                    'topic_id' => $topicId,
                    'created_at' => nowIso(),
                    'text' => $sum['one_liner'] ?? (($topic['title'] ?? $topicId) . ' - domknięty.'),
                ];
                $userState['closed_topics_log'] = array_slice($userState['closed_topics_log'], -100);
            }

            $factKey = $topic['fact_key'] ?? null;
            $factValue = $sum['fact_value'] ?? null;
            if ($factKey) {
                $candidateValue = null;
                if (is_string($factValue) && trim($factValue) !== '') {
                    $candidateValue = trim($factValue);
                } elseif (trim($userMessage) !== '') {
                    $candidateValue = mb_substr(trim($userMessage), 0, 140);
                }

                if ($candidateValue !== null) {
                    $confMsg = applyFactWithConflictCheck(
                        $userState,
                        (string)$factKey,
                        $candidateValue,
                        $topicId,
                        (float)($sum['confidence'] ?? 0.8)
                    );
                    if (is_string($confMsg) && $confMsg !== '') {
                        $userState['conversation_window'][] = ['ts' => nowIso(), 'user' => $userMessage, 'assistant' => $confMsg];
                        $userState['conversation_window'] = array_slice($userState['conversation_window'], -5);
                        appendConversationEntry($userId, 'assistant', $confMsg);
                    }
                }
            }
        }

        if (($event['type'] ?? '') === 'fact_change_proposed' && ($event['require_confirmation'] ?? false)) {
            $userState['active_topic']['mode'] = 'confirmation_pending';
            $userState['active_topic']['pending_confirmation'] = [
                'fact_key' => $event['fact_key'] ?? '',
                'old_value' => $event['old_value'] ?? null,
                'new_value' => $event['new_value'] ?? '',
                'topic_id' => $activeTopicId,
                'confidence' => $event['confidence'] ?? 0.8,
            ];
        }
    }



    $nextTopicId = $control['next_active_topic_id'] ?? $activeTopicId;
    if (is_string($nextTopicId) && isset($flatTopics[$nextTopicId])) {
        $userState['active_topic']['topic_id'] = $nextTopicId;
    }

    $currentActiveAfter = $userState['active_topic']['topic_id'] ?? null;
    if ($currentActiveAfter && (($userState['topic_state'][$currentActiveAfter]['status'] ?? '') === 'achieved')) {
        foreach ($candidateTopics as $candidate) {
            $cid = $candidate['topic_id'] ?? null;
            if ($cid && (($userState['topic_state'][$cid]['status'] ?? 'not_started') !== 'achieved')) {
                $userState['active_topic']['topic_id'] = $cid;
                $userState['active_topic']['since'] = nowIso();
                break;
            }
        }
    }

    if (($control['active_topic_action'] ?? '') === 'park') {
        $userState['active_topic']['attempts'] = (int)($userState['active_topic']['attempts'] ?? 0) + 1;
    }

    $currentGroupId = $userState['stage_state']['group_id'] ?? '';
    $currentStageId = $userState['stage_state']['stage_id'] ?? '';
    $sumPoints = 0;
    foreach ($flatTopics as $topicId => $meta) {
        if ($meta['group_id'] === $currentGroupId && $meta['stage_id'] === $currentStageId) {
            if (($userState['topic_state'][$topicId]['status'] ?? '') === 'achieved') {
                $sumPoints += (int)($meta['topic']['weight'] ?? 0);
            }
        }
    }

    $threshold = null;
    foreach ($topicsData['stages'] as $stage) {
        if (($stage['stage_id'] ?? '') !== $currentStageId) {
            continue;
        }
        foreach (($stage['groups'] ?? []) as $group) {
            if (($group['group_id'] ?? '') === $currentGroupId) {
                $threshold = (int)($group['min_points_to_advance'] ?? PHP_INT_MAX);
            }
        }
    }

    if ($threshold !== null && $sumPoints >= $threshold) {
        $next = findNextGroup($topicsData, $currentStageId, $currentGroupId);
        if ($next) {
            $userState['stage_state'] = $next;
        }
    }

    $userState['rate_limit']['last_request_at'] = nowIso();
    return ['data' => $userState];
});

if (is_array($chatResult) && isset($chatResult['immediate_text'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo (string)$chatResult['immediate_text'];
}
