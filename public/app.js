const state = {
  userId: null,
  hardId: null,
  fingerprint: null,
  streaming: false,
  lastPrompt: '',
  lastChatError: null,
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

function setApiStatus(ok, tooltip) {
  $('apiStatus').classList.toggle('ok', ok);
  $('apiStatus').title = tooltip;
}

function applyUiPayload(payload) {
  const sidebar = payload.sidebar || {};
  $('activeTopic').textContent = sidebar.active_topic_title || sidebar.active_topic_id || '-';
  $('upcomingTopics').innerHTML = (sidebar.upcoming_topics || []).slice(0, 3).map((t) => `<li>${t.title}</li>`).join('');
  $('oneLiners').innerHTML = (sidebar.oneliners || []).slice(-10).reverse().map((o) => `<li>${o.text}</li>`).join('');

  const p = payload.progress || {};
  $('points').textContent = `Zebrane: ${p.points_collected ?? 0} / ${p.points_required ?? 0}; Brakuje: ${p.points_missing ?? 0}`;

  state.lastPrompt = payload.prompt_debug_text || state.lastPrompt;
  $('promptPreview').textContent = state.lastPrompt;
}

async function runDiagnostics() {
  const details = [];
  const apiBase = `${location.origin}${location.pathname.replace(/\/[^/]*$/, '')}/api`;
  details.push(`Online/offline: ${navigator.onLine ? 'online' : 'offline'}`);
  details.push(`URL aplikacji: ${location.href}`);
  details.push(`API_BASE: ${apiBase}`);

  let lsStatus = 'ok';
  try {
    localStorage.setItem('doradca_uuid_probe', '1');
    localStorage.removeItem('doradca_uuid_probe');
  } catch (e) {
    lsStatus = `błąd (${e.message})`;
  }
  details.push(`localStorage/cookies: ${lsStatus}`);

  try {
    const t0 = performance.now();
    const r = await fetch('api/health.php');
    const txt = await r.text();
    let validJson = false;
    try { JSON.parse(txt); validJson = true; } catch {}
    details.push(`health.php => HTTP ${r.status}, ${Math.round(performance.now() - t0)}ms, JSON=${validJson}`);
  } catch (e) {
    details.push(`health.php fetch error: ${e.message} (network/cors/timeout)`);
  }

  try {
    const t1 = performance.now();
    const initRes = await fetch('api/init.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ hard_id: state.hardId, fingerprint: state.fingerprint }) });
    const txt = await initRes.text();
    details.push(`init.php => HTTP ${initRes.status}, ${Math.round(performance.now() - t1)}ms, user_id=${txt.includes('user_id')}`);
    details.push(`init snippet: ${txt.slice(0, 110).replace(/\s+/g, ' ')}`);
  } catch (e) {
    details.push(`init.php fetch error: ${e.message} (network/cors/timeout)`);
  }

  try {
    const getRes = await fetch('api/chat.php');
    details.push(`chat.php GET => HTTP ${getRes.status} (${getRes.status === 405 ? 'endpoint osiągalny' : (getRes.status === 404 ? 'brak endpointu' : 'inny status')})`);
  } catch (e) {
    details.push(`chat.php GET fetch error: ${e.message}`);
  }

  if (state.lastChatError) {
    details.push(`lastChatError: ${state.lastChatError}`);
  }

  details.push('Mapowanie kodów: ' + Object.entries(HTTP_HELP).map(([k, v]) => `${k}: ${v}`).join(' | ') + ' | 5xx: błąd serwera/upstream');
  setApiStatus(false, details.join('\n'));
}

async function init() {
  state.hardId = ensureHardId();
  state.fingerprint = fingerprint();
  await runDiagnostics();

  const res = await fetch('api/init.php', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ hard_id: state.hardId, fingerprint: state.fingerprint }),
  });
  const data = await res.json();
  state.userId = data.user_id;

  $('chat').innerHTML = '';
  (data.chatlog_tail || []).forEach((m) => addMessage(m.role, m.text));
  (data.preloaded_messages || []).forEach((m) => addMessage(m.role, m.text));
  applyUiPayload(data);
  $('loadAll').style.display = data.has_more ? 'inline' : 'none';
}

async function loadFullChat() {
  if (!state.userId) return;
  const res = await fetch(`api/init.php?chatlog=full&user_id=${encodeURIComponent(state.userId)}`);
  const data = await res.json();
  $('chat').innerHTML = '';
  (data.chatlog || []).forEach((m) => addMessage(m.role, m.text));
}

async function sendMessage(text) {
  if (state.streaming) return;
  addMessage('user', text);
  const assistantNode = addMessage('assistant', '');
  state.streaming = true;
  $('sendBtn').disabled = true;
  state.lastChatError = null;

  const res = await fetch('api/chat.php', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ user_id: state.userId, user_message: text, client_timestamp: Date.now() }),
  });

  if (!res.ok || !res.body) {
    const hint = HTTP_HELP[res.status] || (res.status >= 500 ? 'Błąd serwera/upstream.' : 'Nieznany błąd HTTP.');
    state.lastChatError = `HTTP ${res.status}: ${hint}`;
    setApiStatus(false, state.lastChatError);
    assistantNode.textContent = '[Błąd połączenia z API]';
    state.streaming = false;
    $('sendBtn').disabled = false;
    return;
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let sseBuffer = '';

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    sseBuffer += decoder.decode(value, { stream: true });
    const chunks = sseBuffer.split('\n\n');
    sseBuffer = chunks.pop();

    for (const chunk of chunks) {
      const lines = chunk.split('\n');
      const event = lines.find((l) => l.startsWith('event:'))?.replace('event:', '').trim() || 'message';
      const dataLine = lines.find((l) => l.startsWith('data:'));
      if (!dataLine) continue;
      const payload = JSON.parse(dataLine.replace(/^data:\s*/, ''));

      if (event === 'done') {
        assistantNode.textContent = payload.assistant_text || assistantNode.textContent;
        applyUiPayload(payload);
        setApiStatus(true, 'Połączenie API OK. Ostatnia rozmowa zakończona sukcesem.');
      } else if (event === 'error') {
        state.lastChatError = payload.message || 'unknown error';
        setApiStatus(false, `Błąd: ${state.lastChatError}`);
      } else {
        assistantNode.textContent += payload.token || '';
      }
    }
  }

  state.streaming = false;
  $('sendBtn').disabled = false;
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

init();
