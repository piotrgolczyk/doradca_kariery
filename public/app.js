const messagesEl = document.getElementById('messages');
const formEl = document.getElementById('chat-form');
const inputEl = document.getElementById('chat-input');
const sendBtn = document.getElementById('send-btn');

const sidebarEl = document.getElementById('sidebar');
const sidebarToggleEl = document.getElementById('sidebar-toggle');
const activeTopicItemEl = document.getElementById('active-topic-item');
const upcomingTopicsListEl = document.getElementById('upcoming-topics-list');
const historyListEl = document.getElementById('history-list');
const pointsCurrentEl = document.getElementById('points-current');
const pointsMissingEl = document.getElementById('points-missing');
const promptBoxEl = document.getElementById('current-prompt-box');
const copyPromptBtnEl = document.getElementById('copy-prompt-btn');
const historyLoadWrapEl = document.getElementById('history-load-wrap');
const loadFullHistoryBtnEl = document.getElementById('load-full-history-btn');
const apiStatusDotEl = document.getElementById('api-status-dot');
const apiStatusLabelEl = document.getElementById('api-status-label');
const apiStatusTooltipEl = document.getElementById('api-status-tooltip');

const KEY_UUID = 'career_uuid';
const API_BASE = window.location.pathname.includes('/public/') ? '../api/' : 'api/';
let userId = null;
let streaming = false;
let lastChatError = null;

function setApiStatus(ok, label, tooltip) {
  if (!apiStatusDotEl || !apiStatusLabelEl || !apiStatusTooltipEl) return;

  apiStatusDotEl.classList.remove('api-status-ok', 'api-status-error', 'api-status-unknown');
  apiStatusDotEl.classList.add(ok ? 'api-status-ok' : 'api-status-error');
  apiStatusLabelEl.textContent = label;
  apiStatusTooltipEl.title = tooltip;
  apiStatusDotEl.title = tooltip;
  apiStatusLabelEl.title = tooltip;
  apiStatusTooltipEl.setAttribute('aria-label', tooltip);
}

function classifyHttpStatus(status) {
  if (status >= 200 && status < 300) return 'OK: endpoint odpowiada poprawnie.';
  if (status === 400) return 'Błąd danych wejściowych (400) – sprawdź payload żądania.';
  if (status === 401) return 'Brak autoryzacji (401) – w tym API zwykle brak klucza OpenAI po stronie backendu.';
  if (status === 403) return 'Brak uprawnień (403) – możliwa blokada serwera/proxy/WAF.';
  if (status === 404) return 'Endpoint nie istnieje (404) – możliwa zła ścieżka API_BASE.';
  if (status === 405) return 'Metoda HTTP niedozwolona (405) – endpoint jest osiągalny, ale użyto złej metody.';
  if (status === 409) return 'Konflikt stanu (409).';
  if (status === 413) return 'Payload za duży (413).';
  if (status === 415) return 'Zły Content-Type (415).';
  if (status === 422) return 'Błąd walidacji danych (422).';
  if (status === 429) return 'Rate limit (429) – za dużo żądań.';
  if (status >= 500) return 'Błąd backendu (5xx) – API działa sieciowo, ale logika serwera nie kończy żądania poprawnie.';
  return `Nieoczekiwany kod HTTP (${status}).`;
}

function explainFetchError(err) {
  const msg = (err?.message || String(err || '')).toLowerCase();
  if (msg.includes('timeout')) return 'Przekroczono timeout – API może wisieć, być niedostępne lub blokowane przez sieć.';
  if (msg.includes('failed to fetch')) return 'Błąd sieci/CORS/DNS – przeglądarka nie mogła pobrać odpowiedzi.';
  if (msg.includes('networkerror')) return 'Błąd sieci przeglądarki – brak połączenia z hostem API.';
  if (msg.includes('cors')) return 'Prawdopodobny problem CORS.';
  return `Błąd fetch: ${err?.message || String(err)}`;
}

function buildHeaderReport() {
  const report = [];
  report.push(`time: ${new Date().toISOString()}`);
  report.push(`online: ${navigator.onLine}`);
  report.push(`location: ${window.location.href}`);
  report.push(`api_base: ${API_BASE}`);
  report.push(`user_id_known: ${Boolean(userId)}`);
  report.push(`last_chat_error_present: ${Boolean(lastChatError)}`);

  try {
    const testKey = '__diag_localstorage__';
    localStorage.setItem(testKey, '1');
    localStorage.removeItem(testKey);
    report.push('localStorage: ok');
  } catch (e) {
    report.push(`localStorage: fail (${e?.message || String(e)})`);
  }

  report.push(`cookie_enabled: ${navigator.cookieEnabled}`);
  return report;
}

function withTimeout(ms, promiseFactory) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`timeout after ${ms}ms`)), ms);
    Promise.resolve()
      .then(() => promiseFactory())
      .then((val) => {
        clearTimeout(timer);
        resolve(val);
      })
      .catch((err) => {
        clearTimeout(timer);
        reject(err);
      });
  });
}

async function diagnoseApiConnection() {
  const report = buildHeaderReport();
  let ok = true;

  if (!navigator.onLine) {
    ok = false;
    report.push('browser_offline: true');
    report.push('explain_browser_offline: Brak połączenia internetowego po stronie przeglądarki.');
  }

  if (lastChatError) {
    ok = false;
    report.push(`last_chat_error: ${lastChatError}`);
    report.push('explain_last_chat_error: Ostatnia próba rozmowy z chat.php zakończyła się błędem.');
  }

  try {
    const t0 = performance.now();
    const healthRes = await withTimeout(4000, () => fetch(`${API_BASE}health.php`, { cache: 'no-store' }));
    const t1 = performance.now();
    report.push(`health_status: ${healthRes.status}`);
    report.push(`health_latency_ms: ${Math.round(t1 - t0)}`);
    report.push(`health_explain: ${classifyHttpStatus(healthRes.status)}`);
    if (!healthRes.ok) {
      ok = false;
      report.push('health_ok: false');
    } else {
      const healthJson = await healthRes.json().catch(() => null);
      report.push(`health_ok: ${Boolean(healthJson?.ok)}`);
      report.push(`health_json_parse: ${healthJson ? 'ok' : 'fail_or_empty'}`);
      if (!healthJson?.ok) ok = false;
    }
  } catch (e) {
    ok = false;
    report.push(`health_error: ${e?.message || String(e)}`);
    report.push(`health_explain: ${explainFetchError(e)}`);
  }

  try {
    const initPayload = { uuid: getOrCreateUuid(), fingerprint: getFingerprint() };
    report.push(`init_payload_uuid_present: ${Boolean(initPayload.uuid)}`);
    report.push(`init_payload_fingerprint_present: ${Boolean(initPayload.fingerprint)}`);
    const t0 = performance.now();
    const initRes = await withTimeout(5000, () => fetch(`${API_BASE}init.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(initPayload)
    }));
    const t1 = performance.now();
    report.push(`init_status: ${initRes.status}`);
    report.push(`init_latency_ms: ${Math.round(t1 - t0)}`);
    report.push(`init_explain: ${classifyHttpStatus(initRes.status)}`);
    if (!initRes.ok) {
      ok = false;
      const initText = await initRes.text().catch(() => '');
      report.push(`init_response_snippet: ${(initText || 'empty').slice(0, 240).replace(/\s+/g, ' ')}`);
    } else {
      const initJson = await initRes.json().catch(() => null);
      if (!initJson?.user_id) {
        ok = false;
        report.push('init_user_id: missing');
        report.push('init_explain_data: endpoint odpowiedział, ale bez user_id.');
      } else {
        report.push('init_user_id: present');
      }
    }
  } catch (e) {
    ok = false;
    report.push(`init_error: ${e?.message || String(e)}`);
    report.push(`init_explain: ${explainFetchError(e)}`);
  }

  try {
    const methodRes = await withTimeout(4000, () => fetch(`${API_BASE}chat.php`, { method: 'GET' }));
    report.push(`chat_get_status: ${methodRes.status}`);
    report.push(`chat_get_explain: ${classifyHttpStatus(methodRes.status)}`);
    if (!(methodRes.status === 405 || methodRes.status === 422 || methodRes.status === 400 || methodRes.ok)) {
      ok = false;
    }
  } catch (e) {
    ok = false;
    report.push(`chat_get_error: ${e?.message || String(e)}`);
    report.push(`chat_get_explain: ${explainFetchError(e)}`);
  }

  if (ok) {
    setApiStatus(true, 'API działa', report.join('\n'));
  } else {
    setApiStatus(false, 'Brak połączenia z API', report.join('\n'));
  }
}

function uuidv4() {
  return crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}

function getOrCreateUuid() {
  let id = localStorage.getItem(KEY_UUID);
  if (!id) {
    id = uuidv4();
    localStorage.setItem(KEY_UUID, id);
    document.cookie = `${KEY_UUID}=${id}; path=/; max-age=31536000; SameSite=Lax`;
  }
  return id;
}

function getFingerprint() {
  return [navigator.userAgent, navigator.language, `${screen.width}x${screen.height}`, Intl.DateTimeFormat().resolvedOptions().timeZone].join('|');
}

function addMessage(role, text) {
  const div = document.createElement('div');
  div.className = `msg ${role}`;
  div.textContent = text;
  messagesEl.appendChild(div);
  messagesEl.scrollTop = messagesEl.scrollHeight;
  return div;
}

function stripControlJson(text) {
  const delimiter = '<<<CONTROL_JSON>>>';
  const idx = text.indexOf(delimiter);
  return idx === -1 ? text : text.slice(0, idx).trimEnd();
}

function renderList(container, items, mapFn) {
  container.innerHTML = '';
  if (!items || items.length === 0) {
    const li = document.createElement('li');
    li.textContent = '-';
    container.appendChild(li);
    return;
  }
  items.forEach(item => {
    const li = document.createElement('li');
    li.textContent = mapFn(item);
    container.appendChild(li);
  });
}

function renderChatHistory(items) {
  messagesEl.innerHTML = '';
  if (!items || items.length === 0) {
    addMessage('assistant', 'Cześć! Jestem Twoim doradcą kariery. Jak masz na imię?');
    return;
  }
  items.forEach(item => {
    const role = item.role === 'user' ? 'user' : 'assistant';
    addMessage(role, item.text || '');
  });
}

async function loadChatHistory(full = false) {
  if (!userId) return;
  const res = await fetch(`${API_BASE}history.php?user_id=${encodeURIComponent(userId)}&full=${full ? '1' : '0'}`);
  if (!res.ok) return;
  const data = await res.json();
  renderChatHistory(data.items || []);

  if (data.has_more) {
    historyLoadWrapEl.hidden = false;
  } else {
    historyLoadWrapEl.hidden = true;
  }
}

async function refreshStatePanel() {
  if (!userId) return;
  try {
    const res = await fetch(`${API_BASE}state.php?user_id=${encodeURIComponent(userId)}`);
    if (!res.ok) return;
    const state = await res.json();

    activeTopicItemEl.textContent = state.active_topic?.title || '-';
    renderList(upcomingTopicsListEl, state.upcoming_topics || [], item => item.title || item.topic_id || '-');
    renderList(historyListEl, state.history || [], item => item.text || '-');

    const pts = state.section_points || {};
    pointsCurrentEl.textContent = `Zebrane: ${pts.current ?? '-'} / ${pts.required ?? '-'}`;
    pointsMissingEl.textContent = `Brakuje: ${pts.missing ?? '-'}`;

    promptBoxEl.textContent = state.current_prompt || '-';
  } catch (_) {
    // ignore panel refresh failures in MVP
  }
}

async function initUser() {
  const res = await fetch(`${API_BASE}init.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ uuid: getOrCreateUuid(), fingerprint: getFingerprint() })
  });
  const data = await res.json();
  userId = data.user_id;
}

async function sendMessage(text) {
  streaming = true;
  sendBtn.disabled = true;
  const assistantEl = addMessage('assistant', '');

  const res = await fetch(`${API_BASE}chat.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: userId, user_message: text, client_timestamp: new Date().toISOString() })
  });

  if (!res.ok || !res.body) {
    let details = '';
    try {
      details = await res.text();
    } catch (_) {
      details = '';
    }
    const snippet = details ? details.slice(0, 300).replace(/\s+/g, ' ') : 'no response body';
    lastChatError = `chat_status=${res.status}; details=${snippet}`;
    setApiStatus(false, 'Brak połączenia z API', `time: ${new Date().toISOString()}\n${lastChatError}`);
    assistantEl.textContent = 'Błąd połączenia z serwerem.';
    streaming = false;
    sendBtn.disabled = false;
    return;
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    assistantEl.textContent = stripControlJson(buffer);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  assistantEl.textContent = stripControlJson(buffer);
  streaming = false;
  sendBtn.disabled = false;
  lastChatError = null;
  await refreshStatePanel();
  await diagnoseApiConnection();
}

formEl.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (streaming || !userId) return;
  const text = inputEl.value.trim();
  if (!text) return;
  addMessage('user', text);
  inputEl.value = '';
  await sendMessage(text);
});

sidebarToggleEl?.addEventListener('click', () => {
  sidebarEl?.classList.toggle('open');
});

loadFullHistoryBtnEl?.addEventListener('click', async () => {
  await loadChatHistory(true);
  historyLoadWrapEl.hidden = true;
});

copyPromptBtnEl?.addEventListener('click', async () => {
  const text = promptBoxEl?.textContent || '';
  if (!text || text === '-') return;
  try {
    await navigator.clipboard.writeText(text);
    copyPromptBtnEl.textContent = '✅';
    setTimeout(() => { copyPromptBtnEl.textContent = '📋'; }, 1200);
  } catch (_) {
    // no-op
  }
});

initUser().then(async () => {
  await loadChatHistory(false);
  await refreshStatePanel();
  await diagnoseApiConnection();
  setInterval(refreshStatePanel, 4000);
  setInterval(diagnoseApiConnection, 8000);
});
