<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(['error' => 'Method not allowed'], 405);
}

$userId = trim((string)($_GET['user_id'] ?? ''));
if ($userId === '') {
    sendJson(['error' => 'user_id is required'], 422);
}

$topicsData = readJsonFile(TOPICS_FILE, []);
$flatTopics = flattenTopics($topicsData);
$userPath = userFilePath($userId);

if (!file_exists($userPath)) {
    sendJson(['error' => 'User not found'], 404);
}

function pickCandidateTopicsState(array $userState, array $flatTopics): array
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

        $row = [
            'topic_id' => $topicId,
            'title' => $entry['topic']['title'] ?? $topicId,
            'weight' => (int)($entry['topic']['weight'] ?? 1),
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

    if ($backlog) {
        shuffle($backlog);
        usort($backlog, fn($a, $b) => ($b['weight'] <=> $a['weight']));
        $selected = array_merge($selected, array_slice($backlog, 0, 2));
    }

    $pickedIds = array_column($selected, 'topic_id');
    $pool = array_values(array_filter($allOpen, fn($t) => !in_array($t['topic_id'], $pickedIds, true)));
    if ($pool) {
        $selected[] = $pool[array_rand($pool)];
    }

    $activeTopicId = $userState['active_topic']['topic_id'] ?? null;
    if ($activeTopicId && isset($flatTopics[$activeTopicId]) && !in_array($activeTopicId, array_column($selected, 'topic_id'), true)) {
        array_unshift($selected, [
            'topic_id' => $activeTopicId,
            'title' => $flatTopics[$activeTopicId]['topic']['title'] ?? $activeTopicId,
            'weight' => (int)($flatTopics[$activeTopicId]['topic']['weight'] ?? 1),
            'stage_id' => $flatTopics[$activeTopicId]['stage_id'],
            'group_id' => $flatTopics[$activeTopicId]['group_id'],
        ]);
    }

    return array_slice($selected, 0, 10);
}

$userState = readJsonFile($userPath, []);
if (!$userState) {
    sendJson(['error' => 'Empty user state'], 500);
}

$activeTopicId = $userState['active_topic']['topic_id'] ?? null;
$activeTopicTitle = ($activeTopicId && isset($flatTopics[$activeTopicId]))
    ? ($flatTopics[$activeTopicId]['topic']['title'] ?? $activeTopicId)
    : null;

$candidates = pickCandidateTopicsState($userState, $flatTopics);
$nextThree = [];
foreach ($candidates as $candidate) {
    if (($candidate['topic_id'] ?? '') === $activeTopicId) {
        continue;
    }
    $nextThree[] = [
        'topic_id' => $candidate['topic_id'],
        'title' => $candidate['title'],
    ];
    if (count($nextThree) >= 3) {
        break;
    }
}

$history = array_slice($userState['closed_topics_log'] ?? [], -10);

sendJson([
    'user_id' => $userId,
    'active_topic' => [
        'topic_id' => $activeTopicId,
        'title' => $activeTopicTitle,
    ],
    'upcoming_topics' => $nextThree,
    'history' => $history,
]);
