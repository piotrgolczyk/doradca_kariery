const messagesEl = document.getElementById('messages');
const formEl = document.getElementById('chat-form');
const inputEl = document.getElementById('chat-input');
const sendBtn = document.getElementById('send-btn');

const KEY_UUID = 'career_uuid';
let userId = null;
let streaming = false;

function uuidv4() {
  return crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0; const v = c === 'x' ? r : (r & 0x3 | 0x8); return v.toString(16);
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

async function initUser() {
  const res = await fetch('../api/init.php', {
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

  const res = await fetch('../api/chat.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: userId, user_message: text, client_timestamp: new Date().toISOString() })
  });

  if (!res.ok || !res.body) {
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
    assistantEl.textContent = buffer;
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  streaming = false;
  sendBtn.disabled = false;
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

initUser().then(() => {
  addMessage('assistant', 'Cześć! Jestem Twoim doradcą kariery. Jak masz na imię?');
});
