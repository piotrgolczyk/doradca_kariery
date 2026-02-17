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
  <button id="sidebar-toggle" class="sidebar-toggle" aria-label="Pokaż panel">☰</button>

  <aside id="sidebar" class="sidebar">
    <h2>Panel rozmowy</h2>

    <section>
      <h3>Aktualny temat</h3>
      <ul><li id="active-topic-item">-</li></ul>
    </section>

    <section>
      <h3>Nadchodzące 3 tematy</h3>
      <ul id="upcoming-topics-list"><li>-</li></ul>
    </section>

    <section>
      <h3>Zapisana historia</h3>
      <ul id="history-list"><li>-</li></ul>
    </section>

    <section>
      <h3>Punkty sekcji</h3>
      <ul>
        <li id="points-current">Zebrane: -</li>
        <li id="points-missing">Brakuje: -</li>
      </ul>
    </section>

    <section>
      <div class="prompt-header">
        <h3>Aktualny prompt</h3>
        <button id="copy-prompt-btn" class="copy-btn" type="button" title="Kopiuj prompt">📋</button>
      </div>
      <pre id="current-prompt-box" class="prompt-box">-</pre>
    </section>
  </aside>

  <main class="chat-shell">
    <h1>Doradca kariery</h1>
    <div id="history-load-wrap" class="history-load-wrap" hidden>
      <button id="load-full-history-btn" type="button" class="load-history-btn">Wczytaj całość rozmowy</button>
    </div>
    <div id="messages" class="messages"></div>
    <form id="chat-form" class="chat-form">
      <input id="chat-input" type="text" placeholder="Napisz wiadomość..." autocomplete="off" required>
      <button id="send-btn" type="submit">Wyślij</button>
    </form>
  </main>

  <script src="public/app.js"></script>
</body>
</html>
