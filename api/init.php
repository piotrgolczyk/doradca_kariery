<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$uuid = trim((string)($input['uuid'] ?? ''));
$fingerprint = trim((string)($input['fingerprint'] ?? ''));

if ($uuid === '') {
    sendJson(['error' => 'uuid is required'], 422);
}

$topicsData = readJsonFile(TOPICS_FILE, []);
if (empty($topicsData)) {
    sendJson(['error' => 'topics.json missing'], 500);
}

$result = withLockedJsonFile(USERS_FILE, function (array $usersData) use ($uuid, $fingerprint, $topicsData): array {
    $users = $usersData['users'] ?? [];
    foreach ($users as $user) {
        if (($user['uuid'] ?? '') === $uuid) {
            return [
                'data' => ['users' => $users],
                'return' => ['user_id' => $user['user_id'], 'is_new' => false],
            ];
        }
    }

    $userId = 'u_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $userFile = 'user_' . $userId . '.json';
    $users[] = [
        'user_id' => $userId,
        'uuid' => $uuid,
        'fingerprint' => $fingerprint,
        'created_at' => nowIso(),
        'user_file' => $userFile,
    ];

    $state = defaultUserState($userId, $topicsData);
    atomicWriteJson(DATA_DIR . '/' . $userFile, $state);
    atomicWriteJson(historyFilePath($userId), ['items' => []]);

    return [
        'data' => ['users' => $users],
        'return' => ['user_id' => $userId, 'is_new' => true],
    ];
});

sendJson($result);
