<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/lib.php';

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $promptMain = (string)($_POST['prompt_main'] ?? '');
    $summarizerSystem = (string)($_POST['summarizer_system'] ?? '');
    $apiKey = trim((string)($_POST['openai_api_key'] ?? ''));

    try {
        savePrompts([
            'prompt_main' => $promptMain,
            'summarizer_system' => $summarizerSystem,
        ]);
        saveSettings([
            'openai_api_key' => $apiKey,
        ]);
        $flash = 'Zapisano ustawienia.';
    } catch (Throwable $e) {
        $error = 'Nie udało się zapisać: ' . $e->getMessage();
    }
}

$prompts = getPrompts();
$settings = getSettings();
$maskedKey = $settings['openai_api_key'] !== '' ? str_repeat('•', max(8, strlen($settings['openai_api_key']) - 6)) . substr($settings['openai_api_key'], -6) : '';
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel administracyjny</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f5f7fb; }
        .wrap { max-width: 980px; margin: 24px auto; background: #fff; border-radius: 10px; padding: 20px; box-shadow: 0 8px 24px rgba(0,0,0,.08); }
        h1 { margin-top: 0; }
        .field { margin-bottom: 16px; }
        label { font-weight: 700; display: block; margin-bottom: 8px; }
        textarea, input[type="text"], input[type="password"] { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #ccd4e0; border-radius: 8px; font-family: inherit; }
        textarea { min-height: 200px; resize: vertical; }
        .small { color: #555; font-size: 12px; margin-top: 6px; }
        .ok { background: #e8f8ec; color: #1c6a32; padding: 10px; border-radius: 8px; margin-bottom: 12px; }
        .err { background: #ffe9e9; color: #8a2222; padding: 10px; border-radius: 8px; margin-bottom: 12px; }
        button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 14px; cursor: pointer; }
        .links { margin-top: 16px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Admin – prompty i klucz OpenAI</h1>

    <?php if ($flash): ?>
        <div class="ok"><?= htmlspecialchars($flash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="field">
            <label for="openai_api_key">OpenAI API Key</label>
            <input id="openai_api_key" name="openai_api_key" type="password" value="<?= htmlspecialchars($settings['openai_api_key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="off">
            <div class="small">Aktualnie zapisany: <?= $maskedKey !== '' ? htmlspecialchars($maskedKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'brak' ?></div>
        </div>

        <div class="field">
            <label for="prompt_main">Prompt główny (chat)</label>
            <textarea id="prompt_main" name="prompt_main"><?= htmlspecialchars($prompts['prompt_main'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        </div>

        <div class="field">
            <label for="summarizer_system">Prompt summarizera</label>
            <textarea id="summarizer_system" name="summarizer_system"><?= htmlspecialchars($prompts['summarizer_system'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        </div>

        <button type="submit">Zapisz</button>
    </form>

    <div class="links">
        <a href="index.php">← Powrót do czatu</a>
    </div>
</div>
</body>
</html>
