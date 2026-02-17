<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$hardId = trim((string)($input['hard_id'] ?? ''));
$fingerprint = trim((string)($input['fingerprint'] ?? ''));
$loadAll = (bool)($input['load_all'] ?? false);

if ($hardId === '' && $fingerprint === '') {
    json_response(['error' => 'Brak hard_id/fingerprint'], 422);
}

$topics = topics_data();
$settings = settings();
$usersPath = DATA_DIR . '/users.json';

$result = with_file_lock($usersPath, function () use ($usersPath, $hardId, $fingerprint, $topics) {
    $users = read_json_file($usersPath, ['by_hard_id' => [], 'by_fingerprint' => [], 'next_user_seq' => 1]);

    $mapping = null;
    if ($hardId !== '' && isset($users['by_hard_id'][$hardId])) {
        $mapping = $users['by_hard_id'][$hardId];
    } elseif ($fingerprint !== '' && isset($users['by_fingerprint'][$fingerprint])) {
        $mapping = $users['by_fingerprint'][$fingerprint];
    }

    $isNew = false;
    if (!$mapping) {
        $seq = (int)($users['next_user_seq'] ?? 1);
        $userId = 'u' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
        $mapping = [
            'user_id' => $userId,
            'user_file' => 'user_' . $userId . '.json',
            'chatlog_file' => 'chatlog_' . $userId . '.jsonl'
        ];
        $users['next_user_seq'] = $seq + 1;
        $isNew = true;
    }

    if ($hardId !== '') {
        $users['by_hard_id'][$hardId] = $mapping;
    }
    if ($fingerprint !== '') {
        $users['by_fingerprint'][$fingerprint] = $mapping;
    }

    atomic_write($usersPath, json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    return [$mapping['user_id'], $isNew];
});

[$userId, $isNew] = $result;
$userPath = user_file_path($userId);
$chatlogPath = chatlog_file_path($userId);

if (!file_exists($userPath)) {
    $state = init_user_state($userId, $topics);
    atomic_write($userPath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
if (!file_exists($chatlogPath)) {
    touch($chatlogPath);
}

$state = read_json_file($userPath, []);
$now = time();
$preloaded = [];

[$stage, $group, $firstTopic] = get_first_topic($topics);
if (empty($state['active_topic']['topic_id'])) {
    $state['active_topic'] = [
        'topic_id' => $firstTopic['topic_id'],
        'since' => $now,
        'attempts' => 0,
        'mode' => 'normal',
        'pending_confirmation' => null,
        'missing_info_hint' => null
    ];
}
if (empty($state['stage_state']['group_id'])) {
    $state['stage_state'] = ['stage_id' => $stage['stage_id'], 'group_id' => $group['group_id']];
}

$activeTopicId = (string)$state['active_topic']['topic_id'];
$activeRef = find_topic($topics, $activeTopicId);
$activeTopic = $activeRef['topic'] ?? $firstTopic;

if ($isNew && !empty($settings['greeting_first_visit_enabled'])) {
    $question = (($activeTopic['topic_id'] ?? '') === 'imie')
        ? 'Na start: jak masz na imię?'
        : (string)($activeTopic['micro_prompt'] ?? 'Od czego chcesz zacząć?');
    $text = "Cześć! Tu Emma — bardzo się cieszę, że tu jesteś. Jestem Twoją doradczynią zawodową i pomogę Ci spokojnie poukładać pomysły na przyszłość. {$question}";
    $preloaded[] = ['role' => 'assistant', 'text' => $text];
    append_chatlog($userId, [['ts' => $now, 'role' => 'assistant', 'text' => $text]]);
    $state['last_active_ts'] = $now;
} elseif (!$isNew && !empty($settings['greeting_return_enabled'])) {
    $lastActive = (int)($state['last_active_ts'] ?? 0);
    $threshold = (int)($settings['greeting_return_threshold_seconds'] ?? 300);
    if ($lastActive > 0 && ($now - $lastActive) >= $threshold) {
        $delta = $now - $lastActive;
        $days = (int)floor($delta / 86400);
        if ($days >= 365) {
            $human = 'ponad rok';
        } elseif ($days >= 30) {
            $human = 'ponad ' . (int)floor($days / 30) . ' miesięcy';
        } elseif ($days >= 21) {
            $human = 'ponad 3 tygodnie';
        } elseif ($days >= 14) {
            $human = 'ponad 2 tygodnie';
        } elseif ($days >= 7) {
            $human = 'ponad tydzień';
        } elseif ($days >= 5) {
            $human = 'ponad 5 dni';
        } elseif ($days >= 1) {
            $human = 'ponad dzień';
        } else {
            $human = 'kilka godzin';
        }
        $text = "Fajnie cię znowu widzieć — nie było cię {$human}. O czym dziś pogadamy? Mam parę pytań, żeby kontynuować temat: " . ($activeTopic['title'] ?? 'kolejny krok') . '.';
        $preloaded[] = ['role' => 'assistant', 'text' => $text];
        append_chatlog($userId, [['ts' => $now, 'role' => 'assistant', 'text' => $text]]);
        $state['last_active_ts'] = $now;
    }
}

$state['last_session_start_ts'] = $now;
atomic_write($userPath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

$allLog = read_chatlog_lines($userId);
$defaultLines = (int)($settings['show_chatlog_lines_default'] ?? 100);
$messages = $loadAll ? $allLog : array_slice($allLog, -$defaultLines);

json_response([
    'ok' => true,
    'user_id' => $userId,
    'preloaded_messages' => $preloaded,
    'messages' => $messages,
    'has_more' => count($allLog) > count($messages),
    'state' => $state,
    'settings' => [
        'show_chatlog_lines_default' => $defaultLines,
        'conversation_window_pairs' => (int)($settings['conversation_window_pairs'] ?? 5)
    ]
]);
