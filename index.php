<?php

declare(strict_types=1);
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Doradca kariery</title>
  <link rel="stylesheet" href="public/styles.css">
</head>
<body>
  <main class="chat-shell">
    <h1>Doradca kariery</h1>
    <div id="messages" class="messages"></div>
    <form id="chat-form" class="chat-form">
      <input id="chat-input" type="text" placeholder="Napisz wiadomość..." autocomplete="off" required>
      <button id="send-btn" type="submit">Wyślij</button>
    </form>
  </main>
  <script src="public/app.js"></script>
</body>
</html>
