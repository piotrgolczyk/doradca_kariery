<?php

declare(strict_types=1);
require_once __DIR__ . '/api/bootstrap.php';

$settingsPath = __DIR__ . '/data/settings.json';
$promptMainPath = __DIR__ . '/data/prompt_main.txt';
$promptSummPath = __DIR__ . '/data/prompt_summarizer.txt';

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = settings();
    $settings['openai_api_key'] = trim((string)($_POST['openai_api_key'] ?? ''));
    $settings['openai_api_base'] = trim((string)($_POST['openai_api_base'] ?? 'https://api.openai.com/v1'));
    $settings['openai_endpoint'] = 'responses';
    $settings['openai_model'] = 'gpt-5-mini';
    $settings['openai_reasoning_effort'] = 'low';
    $settings['conversation_window_pairs'] = max(1, (int)($_POST['conversation_window_pairs'] ?? 5));
    $settings['show_chatlog_lines_default'] = max(10, (int)($_POST['show_chatlog_lines_default'] ?? 100));
    $settings['closed_topics_prompt_limit'] = max(1, (int)($_POST['closed_topics_prompt_limit'] ?? 25));
    $settings['profile_facts_limit'] = max(1, (int)($_POST['profile_facts_limit'] ?? 30));
    $settings['max_attempts_before_switch'] = max(1, (int)($_POST['max_attempts_before_switch'] ?? 2));
    $settings['diagnostics_enabled'] = isset($_POST['diagnostics_enabled']);
    $settings['greeting_first_visit_enabled'] = isset($_POST['greeting_first_visit_enabled']);
    $settings['greeting_return_enabled'] = isset($_POST['greeting_return_enabled']);
    $settings['greeting_return_threshold_seconds'] = max(60, (int)($_POST['greeting_return_threshold_seconds'] ?? 300));

    with_file_lock($settingsPath, static function () use ($settingsPath, $settings): void {
        atomic_write($settingsPath, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    });
    atomic_write($promptMainPath, (string)($_POST['prompt_main'] ?? ''));
    atomic_write($promptSummPath, (string)($_POST['prompt_summarizer'] ?? ''));
    $message = 'Zapisano ustawienia.';
}

$settings = settings();
$promptMain = file_exists($promptMainPath) ? file_get_contents($promptMainPath) : '';
$promptSumm = file_exists($promptSummPath) ? file_get_contents($promptSummPath) : '';
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin — Doradca Kariery</title>
  <link rel="stylesheet" href="public/styles.css" />
</head>
<body class="admin-body">
  <main class="admin-wrap">
    <h1>Panel administratora</h1>
    <?php if ($message): ?><p class="success"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <form method="post" class="admin-form">
      <label>OpenAI API Key <input type="password" name="openai_api_key" value="<?= htmlspecialchars($settings['openai_api_key'] ?? '') ?>" /></label>
      <label>API Base <input type="text" name="openai_api_base" value="<?= htmlspecialchars($settings['openai_api_base'] ?? 'https://api.openai.com/v1') ?>" /></label>
      <label>Endpoint <input type="text" value="responses" readonly /></label>
      <label>Model <input type="text" value="gpt-5-mini" readonly /></label>
      <label>Reasoning effort <input type="text" value="low" readonly /></label>
      <label>conversation_window_pairs <input type="number" name="conversation_window_pairs" value="<?= (int)($settings['conversation_window_pairs'] ?? 5) ?>" /></label>
      <label>show_chatlog_lines_default <input type="number" name="show_chatlog_lines_default" value="<?= (int)($settings['show_chatlog_lines_default'] ?? 100) ?>" /></label>
      <label>closed_topics_prompt_limit <input type="number" name="closed_topics_prompt_limit" value="<?= (int)($settings['closed_topics_prompt_limit'] ?? 25) ?>" /></label>
      <label>profile_facts_limit <input type="number" name="profile_facts_limit" value="<?= (int)($settings['profile_facts_limit'] ?? 30) ?>" /></label>
      <label>max_attempts_before_switch <input type="number" name="max_attempts_before_switch" value="<?= (int)($settings['max_attempts_before_switch'] ?? 2) ?>" /></label>
      <label><input type="checkbox" name="diagnostics_enabled" <?= !empty($settings['diagnostics_enabled']) ? 'checked' : '' ?> /> diagnostics_enabled</label>
      <label><input type="checkbox" name="greeting_first_visit_enabled" <?= !empty($settings['greeting_first_visit_enabled']) ? 'checked' : '' ?> /> greeting_first_visit_enabled</label>
      <label><input type="checkbox" name="greeting_return_enabled" <?= !empty($settings['greeting_return_enabled']) ? 'checked' : '' ?> /> greeting_return_enabled</label>
      <label>greeting_return_threshold_seconds <input type="number" name="greeting_return_threshold_seconds" value="<?= (int)($settings['greeting_return_threshold_seconds'] ?? 300) ?>" /></label>
      <label>Prompt główny
        <textarea name="prompt_main" rows="10"><?= htmlspecialchars((string)$promptMain) ?></textarea>
      </label>
      <label>Prompt summarizera
        <textarea name="prompt_summarizer" rows="8"><?= htmlspecialchars((string)$promptSumm) ?></textarea>
      </label>
      <button type="submit">Zapisz</button>
    </form>
  </main>
</body>
</html>
