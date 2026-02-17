const state = {
  userId: null,
  hardId: null,
  fingerprint: null,
  streaming: false,
  lastPrompt: '',
  lastChatError: null
};

const $ = (id) => document.getElementById(id);

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

function renderState(s) {
  const active = s.active_topic?.topic_id || '-';
  $('activeTopic').textContent = active;
  const one = (s.closed_topics_log || []).slice(-10).reverse();
  $('oneLiners').innerHTML = one.map(o => `<li>${o.text}</li>`).join('');
}

function renderUpcoming(list) {
  $('upcomingTopics').innerHTML = (list || []).slice(0,3).map(t => `<li>${t.title}</li>`).join('');
}

function setApiStatus(ok, tooltip) {
  $('apiStatus').classList.toggle('ok', ok);
  $('apiStatus').title = tooltip;
}

async function runDiagnostics() {
  const details = [];
  details.push(`Online: ${navigator.onLine ? 'tak' : 'nie'}`);
  details.push(`URL: ${location.href}`);
  details.push(`API_BASE: ${location.origin}${location.pathname.replace(/\/[^/]*$/, '')}/api`);
  details.push(`localStorage UUID: ${state.hardId ? 'ok' : 'błąd'}`);
  const t0 = performance.now();
  try {
    const r = await fetch('api/health.php');
    const txt = await r.text();
    let validJson = false;
    try { JSON.parse(txt); validJson = true; } catch {}
    details.push(`health.php: HTTP ${r.status}, ${(performance.now()-t0).toFixed(0)}ms, json=${validJson}`);
  } catch (e) {
    details.push(`health.php: błąd fetch (${e.message})`);
  }

  try {
    const t1 = performance.now();
    const initRes = await fetch('api/init.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ hard_id: state.hardId, fingerprint: state.fingerprint }) });
    const txt = await initRes.text();
    details.push(`init.php: HTTP ${initRes.status}, ${(performance.now()-t1).toFixed(0)}ms, snippet=${txt.slice(0,80)}`);
  } catch (e) {
    details.push(`init.php: błąd fetch (${e.message})`);
  }

  try {
    const getRes = await fetch('api/chat.php');
    details.push(`chat.php GET: HTTP ${getRes.status} (${getRes.status===405?'endpoint osiągalny':getRes.status===404?'brak endpointu':'inne'})`);
  } catch (e) {
    details.push(`chat.php GET: błąd fetch (${e.message})`);
  }

  if (state.lastChatError) details.push(`lastChatError: ${state.lastChatError}`);
  setApiStatus(false, details.join('\n'));
}

async function init(loadAll=false) {
  state.hardId = ensureHardId();
  state.fingerprint = fingerprint();
  await runDiagnostics();

  const res = await fetch('api/init.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ hard_id: state.hardId, fingerprint: state.fingerprint, load_all: loadAll })
  });
  const data = await res.json();
  state.userId = data.user_id;
  $('chat').innerHTML = '';
  data.messages.forEach(m => addMessage(m.role, m.text));
  (data.preloaded_messages || []).forEach(m => addMessage(m.role, m.text));
  renderState(data.state);
  $('loadAll').style.display = data.has_more ? 'inline' : 'none';
}

async function sendMessage(text) {
  if (state.streaming) return;
  addMessage('user', text);
  const assistantNode = addMessage('assistant', '');
  state.streaming = true;
  $('sendBtn').disabled = true;
  state.lastChatError = null;

  const res = await fetch('api/chat.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ user_id: state.userId, user_message: text, client_timestamp: Date.now() })
  });

  if (!res.ok || !res.body) {
    state.lastChatError = `HTTP ${res.status}`;
    setApiStatus(false, `Błąd ${res.status}: sprawdź URL, klucz API lub payload.`);
    assistantNode.textContent = '[Błąd połączenia z API]';
    state.streaming = false;
    $('sendBtn').disabled = false;
    return;
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let sseBuffer = '';
  while (true) {
    const {value, done} = await reader.read();
    if (done) break;
    sseBuffer += decoder.decode(value, {stream:true});
    const chunks = sseBuffer.split('\n\n');
    sseBuffer = chunks.pop();
    for (const c of chunks) {
      const lines = c.split('\n');
      const event = lines.find(l => l.startsWith('event:'))?.replace('event:','').trim() || 'message';
      const dline = lines.find(l => l.startsWith('data:'));
      if (!dline) continue;
      const payload = JSON.parse(dline.replace(/^data:\s*/, ''));
      if (event === 'done') {
        assistantNode.textContent = payload.assistant_text;
        renderState(payload.state);
        renderUpcoming(payload.candidate_topics);
        $('points').textContent = `Zebrane: ${payload.progress.collected}/${payload.progress.required}; Brakuje: ${payload.progress.missing}`;
        state.lastPrompt = payload.prompt_preview;
        $('promptPreview').textContent = state.lastPrompt;
        setApiStatus(true, 'Połączenie API OK. Ostatnia rozmowa zakończona sukcesem.');
      } else if (event === 'error') {
        state.lastChatError = payload.message;
        setApiStatus(false, `Błąd: ${payload.message}`);
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
$('loadAll').onclick = (e) => { e.preventDefault(); init(true); };

init();
