<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(['error' => 'Method not allowed'], 405);
}

$userId = trim((string)($_GET['user_id'] ?? ''));
$full = (string)($_GET['full'] ?? '0') === '1';

if ($userId === '') {
    sendJson(['error' => 'user_id is required'], 422);
}

$userPath = userFilePath($userId);
if (!file_exists($userPath)) {
    sendJson(['error' => 'User not found'], 404);
}

$all = getConversationHistory($userId);
$total = count($all);

if ($full) {
    $items = $all;
} else {
    $items = array_slice($all, -100);
}

sendJson([
    'user_id' => $userId,
    'total_lines' => $total,
    'returned_lines' => count($items),
    'has_more' => !$full && $total > 100,
    'items' => $items,
]);
