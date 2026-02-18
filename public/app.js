const chat = document.getElementById('chat');
const form = document.getElementById('chatForm');
const input = document.getElementById('messageInput');
const sectionsList = document.getElementById('sections');
const promptView = document.getElementById('promptView');
const loadFullBtn = document.getElementById('loadFull');
const copyPromptBtn = document.getElementById('copyPrompt');

const userId = localStorage.getItem('userId') || `user_${crypto.randomUUID().slice(0, 8)}`;
localStorage.setItem('userId', userId);

let loadedFull = false;

function renderMessage(role, content) {
  const div = document.createElement('div');
  div.className = `msg ${role}`;
  div.textContent = content;
  chat.appendChild(div);
  chat.scrollTop = chat.scrollHeight;
}

function renderSections(sections) {
  sectionsList.innerHTML = '';
  sections.forEach((s) => {
    const missing = Math.max(s.targetPoints - s.collectedPoints, 0);
    const li = document.createElement('li');
    li.textContent = `${s.title}: ${s.collectedPoints} pkt, brakuje ${missing} pkt${s.closed ? ' (zamknięta)' : ''}`;
    sectionsList.appendChild(li);
  });
}

function renderPrompt(prompt) {
  promptView.textContent = prompt;
}

async function loadHistory(full = false) {
  const res = await fetch(`/api/history/${userId}?full=${full ? '1' : '0'}`);
  const data = await res.json();
  chat.innerHTML = '';
  data.history.forEach((msg) => renderMessage(msg.role, msg.content));
  renderSections(data.sections);
  renderPrompt(data.currentPrompt);
  loadFullBtn.hidden = !(data.recognized && data.hasMore && !full);
  loadedFull = full;
}

loadFullBtn.addEventListener('click', async () => {
  await loadHistory(true);
});

copyPromptBtn.addEventListener('click', async () => {
  await navigator.clipboard.writeText(promptView.textContent);
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const message = input.value.trim();
  if (!message) return;
  renderMessage('user', message);
  input.value = '';

  const res = await fetch('/api/chat', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ userId, message })
  });

  const data = await res.json();
  if (data.reply) {
    renderMessage('assistant', data.reply);
    renderSections(data.sections);
    renderPrompt(data.currentPrompt);
  }

  if (loadedFull === false) {
    loadFullBtn.hidden = false;
  }
});

loadHistory(false);
