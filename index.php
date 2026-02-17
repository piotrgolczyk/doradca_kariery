<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Doradca Kariery — Emma</title>
  <link rel="stylesheet" href="public/styles.css" />
</head>
<body>
  <div class="layout">
    <aside id="sidebar" class="sidebar hidden">
      <h3>Stan rozmowy</h3>
      <section><strong>Aktualny temat:</strong><div id="activeTopic"></div></section>
      <section><strong>Nadchodzące 3:</strong><ol id="upcomingTopics"></ol></section>
      <section><strong>Historia (oneliners):</strong><ul id="oneLiners"></ul></section>
      <section class="progress"><strong>Punkty:</strong><div id="points"></div></section>
      <section class="prompt-box">
        <div class="prompt-title">Aktualny prompt <button id="copyPrompt">Kopiuj</button></div>
        <pre id="promptPreview"></pre>
      </section>
    </aside>
    <main class="chat-wrap">
      <header>
        <button id="toggleSidebar">☰</button>
        <h1>Emma — doradca zawodowy</h1>
        <span id="apiStatus" title="Diagnostyka API">●</span>
      </header>
      <div class="load-all"><a href="#" id="loadAll">Wczytaj całość rozmowy</a></div>
      <div id="chat" class="chat"></div>
      <form id="chatForm" class="chat-form">
        <input id="messageInput" placeholder="Napisz wiadomość..." autocomplete="off" />
        <button id="sendBtn" type="submit">Wyślij</button>
      </form>
    </main>
  </div>
  <script src="public/app.js"></script>
</body>
</html>
