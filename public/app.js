const state = {
  userId: null,
  hardId: null,
  fingerprint: null,
  streaming: false,
  lastPrompt: '',
  lastChatError: null,
  diagText: '',
  diagEntries: [],
  runId: 0,
};

const $ = (id) => document.getElementById(id);

const HTTP_HELP = {
  400: 'Błąd danych wejściowych. Sprawdź payload.',
  401: 'Brak lub nieprawidłowy klucz API.',
  403: 'Brak uprawnień / blokada.',
  404: 'Zły URL lub brak pliku endpointu.',
  405: 'Endpoint istnieje, ale metoda nieobsługiwana.',
  422: 'Niepoprawne dane wejściowe.',
  429: 'Limit zapytań / rate-limit.',
};

function nowIso() {
  return new Date().toISOString();
}

function addDiag(stage, status, details = {}) {
  state.diagEntries.push({ ts: nowIso(), stage, status, details });
}

function formatDiagReport() {
  const header = [
    '=== RAPORT DIAGNOSTYCZNY DORADCA API ===',
    `czas: ${nowIso()}`,
    `url: ${location.href}`,
    `user_id: ${state.userId || '(brak)'}`,
    `hard_id: ${state.hardId || '(brak)'}`,
    `lastChatError: ${state.lastChatError || '(brak)'}`,
    '',
    '--- ETAPY ---',
  ];

  const body = state.diagEntries.map((e, i) => {
    const d = Object.entries(e.details || {})
      .map(([k, v]) => `  - ${k}: ${typeof v === 'string' ? v : JSON.stringify(v)}`)
      .join('\n');
    return `${i + 1}. [${e.ts}] ${e.stage} -> ${e.status}${d ? `\n${d}` : ''}`;
  });

  const footer = [
    '',
    '--- MAPOWANIE KODÓW HTTP ---',
    ...Object.entries(HTTP_HELP).map(([k, v]) => `${k}: ${v}`),
    '5xx: błąd serwera / upstream',
    '',
    '--- OSTATNI PROMPT DEBUG (SKRÓT) ---',
    (state.lastPrompt || '(brak)').slice(0, 4000),
  ];

  return [...header, ...body, ...footer].join('\n');
}

function refreshDiagText() {
  state.diagText = formatDiagReport();
  $('diagContent').textContent = state.diagText;
  $('apiStatus').title = 'Kliknij, aby otworzyć raport diagnostyczny';
}

function openDiagModal() {
  refreshDiagText();
  $('diagModal').classList.remove('hidden');
}

function closeDiagModal() {
  $('diagModal').classList.add('hidden');
}

function ensureHardId() {
  let id = localStorage.getItem('doradca_uuid');
  if (!id) {
    id = crypto.randomUUID();
    localStorage.setItem('doradca_uuid', id);
  }
  document.cookie = `doradca_uuid=${id}; path=/; max-age=31536000`;
  return id;
}

function fingerprint() {
  return [navigator.userAgent, navigator.language, screen.width, screen.height, Intl.DateTimeFormat().resolvedOptions().timeZone].join('|');
}

function addMessage(role, text) {
  const el = document.createElement('div');
  el.className = `msg ${role}`;
  el.textContent = text;
  $('chat').appendChild(el);
  $('chat').scrollTop = $('chat').scrollHeight;
  return el;
}

function setApiStatus(ok) {
  $('apiStatus').classList.toggle('ok', ok);
  refreshDiagText();
}

function applyUiPayload(payload) {
  const sidebar = payload.sidebar || {};
  $('activeTopic').textContent = sidebar.active_topic_title || sidebar.active_topic_id || '-';
  $('upcomingTopics').innerHTML = (sidebar.upcoming_topics || []).slice(0, 3).map((t) => `<li>${t.title}</li>`).join('');
  $('oneLiners').innerHTML = (sidebar.oneliners || []).slice(-10).reverse().map((o) => `<li>${o.text}</li>`).join('');

  const p = payload.progress || {};
  $('points').textContent = `Zebrane: ${p.points_collected ?? 0} / ${p.points_required ?? 0}; Brakuje: ${p.points_missing ?? 0}`;
  $('stageProgress').textContent = `Etap ${p.current_group_order ?? 1} z ${p.total_groups ?? 1}: ${p.current_group_title || '-'}${p.next_group_title ? ` → potem: ${p.next_group_title}` : ''}`;

  state.lastPrompt = payload.prompt_debug_text || state.lastPrompt;
  $('promptPreview').textContent = state.lastPrompt;
}

async function runDiagnostics() {
  state.diagEntries = [];
  const apiBase = `${location.origin}${location.pathname.replace(/\/[^/]*$/, '')}/api`;

  addDiag('browser', navigator.onLine ? 'ok' : 'warn', { online: navigator.onLine, apiBase });

  try {
    localStorage.setItem('doradca_uuid_probe', '1');
    localStorage.removeItem('doradca_uuid_probe');
    addDiag('storage', 'ok', { localStorage: true, cookieWrite: true });
  } catch (e) {
    addDiag('storage', 'error', { message: e.message });
  }

  try {
    const t0 = performance.now();
    const r = await fetch('api/health.php');
    const txt = await r.text();
    let validJson = false;
    try { JSON.parse(txt); validJson = true; } catch {}
    addDiag('health.php', r.ok ? 'ok' : 'error', {
      http: r.status,
      ms: Math.round(performance.now() - t0),
      json: validJson,
      snippet: txt.slice(0, 120),
    });
  } catch (e) {
    addDiag('health.php', 'error', { message: e.message, hint: 'network/cors/timeout' });
  }

  try {
    const t1 = performance.now();
    const initRes = await fetch('api/init.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ hard_id: `${state.hardId}-probe`, fingerprint: `${state.fingerprint}-probe`, probe: true }),
    });
    const txt = await initRes.text();
    addDiag('init.php (probe)', initRes.ok ? 'ok' : 'error', {
      http: initRes.status,
      ms: Math.round(performance.now() - t1),
      hasUserId: txt.includes('user_id'),
      snippet: txt.slice(0, 150).replace(/\s+/g, ' '),
    });
  } catch (e) {
    addDiag('init.php (probe)', 'error', { message: e.message, hint: 'network/cors/timeout' });
  }

  try {
    const getRes = await fetch('api/chat.php');
    const reachability = getRes.status === 405 || getRes.status === 200 ? 'reachable' : 'unexpected';
    addDiag('chat.php GET', reachability === 'reachable' ? 'ok' : 'warn', { http: getRes.status, reachability });
  } catch (e) {
    addDiag('chat.php GET', 'error', { message: e.message });
  }

  if (state.lastChatError) {
    addDiag('lastChatError', 'error', { message: state.lastChatError });
    setApiStatus(false);
  } else {
    setApiStatus(true);
  }
}

async function init() {
  state.hardId = ensureHardId();
  state.fingerprint = fingerprint();
  await runDiagnostics();

  try {
    const t = performance.now();
    const res = await fetch('api/init.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ hard_id: state.hardId, fingerprint: state.fingerprint }),
    });
    const data = await res.json();
    addDiag('init.php (real)', res.ok ? 'ok' : 'error', {
      http: res.status,
      ms: Math.round(performance.now() - t),
      userId: data.user_id || null,
      preloadedCount: (data.preloaded_messages || []).length,
      chatlogTail: (data.chatlog_tail || []).length,
    });

    state.userId = data.user_id;
    $('chat').innerHTML = '';
    const tail = data.chatlog_tail || [];
    tail.forEach((m) => addMessage(m.role, m.text));

    const seen = new Set(tail.map((m) => `${m.role}::${m.text}`));
    (data.preloaded_messages || []).forEach((m) => {
      const key = `${m.role}::${m.text}`;
      if (!seen.has(key)) {
        addMessage(m.role, m.text);
      }
    });

    applyUiPayload(data);
    $('loadAll').style.display = data.has_more ? 'inline' : 'none';
    setApiStatus(true);
  } catch (e) {
    state.lastChatError = `INIT FAILED: ${e.message}`;
    addDiag('init.php (real)', 'error', { message: e.message });
    setApiStatus(false);
    addMessage('assistant', `[Błąd inicjalizacji: ${e.message}]`);
  }
}

async function loadFullChat() {
  if (!state.userId) return;
  try {
    const t = performance.now();
    const res = await fetch(`api/init.php?chatlog=full&user_id=${encodeURIComponent(state.userId)}`);
    const data = await res.json();
    addDiag('loadFullChat', res.ok ? 'ok' : 'error', {
      http: res.status,
      ms: Math.round(performance.now() - t),
      lines: (data.chatlog || []).length,
    });
    $('chat').innerHTML = '';
    (data.chatlog || []).forEach((m) => addMessage(m.role, m.text));
  } catch (e) {
    state.lastChatError = `LOAD FULL FAILED: ${e.message}`;
    addDiag('loadFullChat', 'error', { message: e.message });
    setApiStatus(false);
  }
}

async function sendMessage(text) {
  if (state.streaming) return;
  const runId = ++state.runId;

  addMessage('user', text);
  const assistantNode = addMessage('assistant', '');
  state.streaming = true;
  $('sendBtn').disabled = true;
  state.lastChatError = null;

  addDiag(`chat#${runId} start`, 'info', { userId: state.userId, textLen: text.length });

  let res;
  try {
    const t = performance.now();
    res = await fetch('api/chat.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ user_id: state.userId, user_message: text, client_timestamp: Date.now() }),
    });
    addDiag(`chat#${runId} fetch`, res.ok ? 'ok' : 'error', {
      http: res.status,
      ms: Math.round(performance.now() - t),
      hasBody: !!res.body,
    });
  } catch (e) {
    state.lastChatError = `FETCH FAILED: ${e.message}`;
    addDiag(`chat#${runId} fetch`, 'error', { message: e.message, hint: 'network/cors/timeout' });
    assistantNode.textContent = `[Błąd połączenia z API: ${e.message}]`;
    state.streaming = false;
    $('sendBtn').disabled = false;
    setApiStatus(false);
    return;
  }

  if (!res.ok || !res.body) {
    const hint = HTTP_HELP[res.status] || (res.status >= 500 ? 'Błąd serwera/upstream.' : 'Nieznany błąd HTTP.');
    state.lastChatError = `HTTP ${res.status}: ${hint}`;
    addDiag(`chat#${runId} response`, 'error', { status: res.status, hint });
    assistantNode.textContent = `[Błąd połączenia z API: ${state.lastChatError}]`;
    state.streaming = false;
    $('sendBtn').disabled = false;
    setApiStatus(false);
    return;
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let sseBuffer = '';
  let tokenCount = 0;
  let doneReceived = false;

  const parseSseEvents = (buffer) => {
    const normalized = buffer.replace(/\r\n/g, '\n');
    const blocks = normalized.split('\n\n');
    return { events: blocks.slice(0, -1), rest: blocks[blocks.length - 1] || '' };
  };

  const parseSseBlock = (block) => {
    const lines = block.split('\n');
    const event = lines.find((l) => l.startsWith('event:'))?.replace('event:', '').trim() || 'message';
    const dataLines = lines
      .filter((l) => l.startsWith('data:'))
      .map((l) => l.replace(/^data:\s*/, ''));
    return { event, data: dataLines.join('\n') };
  };

  try {
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      sseBuffer += decoder.decode(value, { stream: true });
      const parsed = parseSseEvents(sseBuffer);
      sseBuffer = parsed.rest;

      for (const block of parsed.events) {
        const { event, data } = parseSseBlock(block);
        if (!data) {
          addDiag(`chat#${runId} sse`, 'warn', { event, issue: 'missing data line', chunk: block.slice(0, 120) });
          continue;
        }

        let payload;
        try {
          payload = JSON.parse(data);
        } catch (e) {
          addDiag(`chat#${runId} sse`, 'error', { event, issue: 'invalid JSON', parseError: e.message, raw: data.slice(0, 180) });
          continue;
        }

        if (event === 'done') {
          doneReceived = true;
          assistantNode.textContent = payload.assistant_text || assistantNode.textContent;
          applyUiPayload(payload);
          addDiag(`chat#${runId} done`, 'ok', {
            assistantLen: (payload.assistant_text || '').length,
            tokenCount,
            hasControlJson: !!payload.control_json,
            backendDebug: payload.backend_debug || null,
          });
          setApiStatus(true);
        } else if (event === 'error') {
          state.lastChatError = payload.message || 'unknown error';
          addDiag(`chat#${runId} sse error`, 'error', { message: state.lastChatError });
          if (!assistantNode.textContent.trim()) {
            assistantNode.textContent = `[Błąd streamingu: ${state.lastChatError}]`;
          }
          setApiStatus(false);
        } else if (event === 'token' || event === 'message') {
          const token = payload.token || '';
          if (token) {
            tokenCount += 1;
            assistantNode.textContent += token;
          }
        }
      }
    }
  } catch (e) {
    state.lastChatError = `STREAM FAILED: ${e.message}`;
    addDiag(`chat#${runId} stream`, 'error', { message: e.message });
    if (!assistantNode.textContent.trim()) {
      assistantNode.textContent = `[Błąd streamingu: ${e.message}]`;
    }
    setApiStatus(false);
  } finally {
    if (!doneReceived) {
      addDiag(`chat#${runId} finalize`, 'warn', { issue: 'missing done event', tokenCount });
    }
    if (!assistantNode.textContent.trim()) {
      const fallback = `Brak treści odpowiedzi. Otwórz diagnostykę (kropka w prawym górnym rogu), runId=${runId}.`;
      assistantNode.textContent = fallback;
      state.lastChatError = `EMPTY_ASSISTANT_BUBBLE runId=${runId}`;
      addDiag(`chat#${runId} empty-bubble`, 'error', { fallbackShown: true });
      setApiStatus(false);
    }

    state.streaming = false;
    $('sendBtn').disabled = false;
    refreshDiagText();
  }
}

$('chatForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const text = $('messageInput').value.trim();
  if (!text) return;
  $('messageInput').value = '';
  sendMessage(text);
});

$('toggleSidebar').onclick = () => $('sidebar').classList.toggle('hidden');
$('copyPrompt').onclick = () => navigator.clipboard.writeText(state.lastPrompt || '');
$('loadAll').onclick = (e) => { e.preventDefault(); loadFullChat(); };

$('apiStatus').onclick = openDiagModal;
$('closeDiag').onclick = closeDiagModal;
$('diagModal').addEventListener('click', (e) => { if (e.target.id === 'diagModal') closeDiagModal(); });
$('copyDiag').onclick = async () => {
  refreshDiagText();
  await navigator.clipboard.writeText(state.diagText || '');
};

init();
