import http from 'node:http';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PORT = process.env.PORT || 3000;
const DATA_DIR = path.join(__dirname, 'data', 'users');
const PUBLIC_DIR = path.join(__dirname, 'public');

const BASE_PROMPT = 'Jesteś doradcą zawodowym dla licealisty. Masz około 30 lat,nazywasz się Emma i jesteś bardzo przyjazna, entuzjastyczna ale jednocześnie ciekawa drugiego człowieka, gadatliwa. Prowadzisz rozmowę spokojnie, prostym językiem, pytasz jedno główne pytanie naraz i dopytujesz tylko wtedy, gdy to potrzebne do celu tematu. Twoim celem jest stopniowo domykać tematy (topics) w określonej kolejności etapów, ale rozmowa ma brzmieć naturalnie, nie jak ankieta.';

const sections = [
  { id: 'zainteresowania', title: 'Zainteresowania', targetPoints: 3 },
  { id: 'umiejetnosci', title: 'Umiejętności', targetPoints: 3 },
  { id: 'plan_dzialania', title: 'Plan działania', targetPoints: 3 }
];

function safeUserId(raw = '') {
  return (raw || 'guest').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 64) || 'guest';
}

function userFile(userId) {
  return path.join(DATA_DIR, `${safeUserId(userId)}.json`);
}

async function ensureDataDir() {
  await fs.mkdir(DATA_DIR, { recursive: true });
}

function createInitialData(userId) {
  return {
    userId,
    apiKey: '',
    systemPrompt: BASE_PROMPT,
    sections: sections.map((s) => ({ ...s, collectedPoints: 0, closed: false })),
    activeSectionId: sections[0].id,
    originalHistory: [
      {
        role: 'assistant',
        content: 'Cześć! Jestem Emma. Miło Cię poznać 😊 Od czego chcesz zacząć: zainteresowania, mocne strony czy marzenia zawodowe?'
      }
    ]
  };
}

async function loadUserData(userId) {
  const file = userFile(userId);
  try {
    const raw = await fs.readFile(file, 'utf8');
    return JSON.parse(raw);
  } catch {
    const initial = createInitialData(userId);
    await saveUserData(userId, initial);
    return initial;
  }
}

async function saveUserData(userId, data) {
  await ensureDataDir();
  await fs.writeFile(userFile(userId), JSON.stringify(data, null, 2), 'utf8');
}

function nextOpenSection(data) {
  return data.sections.find((s) => !s.closed) || data.sections[data.sections.length - 1];
}

function applyProgress(data) {
  const active = data.sections.find((s) => s.id === data.activeSectionId) || nextOpenSection(data);
  if (!active.closed) {
    active.collectedPoints += 1;
    if (active.collectedPoints >= active.targetPoints) {
      active.closed = true;
      const next = nextOpenSection(data);
      data.activeSectionId = next.id;
    }
  }
}

function buildPrompt(data) {
  const active = data.sections.find((s) => s.id === data.activeSectionId);
  const progressLine = data.sections
    .map((s) => `${s.title}: ${s.collectedPoints}/${s.targetPoints}`)
    .join(', ');
  return `${data.systemPrompt}\nAktywna sekcja: ${active?.title || '-'}\nPostęp: ${progressLine}`;
}

async function callOpenAI(data, history) {
  const apiKey = String(data.apiKey || '').trim();
  if (!apiKey) {
    return 'Brak skonfigurowanego klucza API. Poproś administratora o ustawienie klucza w panelu admin.php.';
  }

  try {
    const response = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${apiKey}`
      },
      body: JSON.stringify({
        model: 'gpt-4o-mini',
        messages: [
          { role: 'system', content: buildPrompt(data) },
          ...history.slice(-20)
        ],
        temperature: 0.7
      })
    });

    if (!response.ok) {
      throw new Error(`OpenAI HTTP ${response.status}`);
    }

    const json = await response.json();
    return json.choices?.[0]?.message?.content?.trim();
  } catch {
    const active = data.sections.find((s) => s.id === data.activeSectionId);
    return `Super, dziękuję! Jesteśmy teraz w sekcji „${active?.title || 'rozmowa'}”. Opowiedz proszę trochę więcej — co jest dla Ciebie najważniejsze w tym temacie?`;
  }
}

function json(res, status, payload) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload));
}

async function serveStatic(req, res) {
  const reqPath = req.url === '/' ? '/index.html' : req.url;
  const fullPath = path.join(PUBLIC_DIR, decodeURIComponent(reqPath));
  if (!fullPath.startsWith(PUBLIC_DIR)) {
    res.writeHead(403);
    res.end('Forbidden');
    return;
  }

  try {
    const file = await fs.readFile(fullPath);
    const ext = path.extname(fullPath);
    const type = ext === '.html' ? 'text/html' : ext === '.css' ? 'text/css' : 'application/javascript';
    res.writeHead(200, { 'Content-Type': `${type}; charset=utf-8` });
    res.end(file);
  } catch {
    res.writeHead(404);
    res.end('Not found');
  }
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);

  if (req.method === 'GET' && url.pathname.startsWith('/api/history/')) {
    const userId = safeUserId(url.pathname.split('/').pop());
    const full = url.searchParams.get('full') === '1';
    const data = await loadUserData(userId);
    const history = full ? data.originalHistory : data.originalHistory.slice(-100);
    return json(res, 200, {
      recognized: data.originalHistory.length > 1,
      history,
      hasMore: data.originalHistory.length > history.length,
      sections: data.sections,
      currentPrompt: buildPrompt(data)
    });
  }

  if (req.method === 'POST' && url.pathname === '/api/chat') {
    let body = '';
    req.on('data', (chunk) => {
      body += chunk;
    });

    req.on('end', async () => {
      try {
        const parsed = JSON.parse(body || '{}');
        const userId = safeUserId(parsed.userId || 'guest');
        const message = String(parsed.message || '').trim();

        if (!message) {
          return json(res, 400, { error: 'Brak wiadomości.' });
        }

        const data = await loadUserData(userId);
        data.originalHistory.push({ role: 'user', content: message });
        applyProgress(data);

        const assistantReply = await callOpenAI(data, data.originalHistory);
        data.originalHistory.push({ role: 'assistant', content: assistantReply });
        await saveUserData(userId, data);

        return json(res, 200, {
          reply: assistantReply,
          sections: data.sections,
          currentPrompt: buildPrompt(data)
        });
      } catch {
        return json(res, 500, { error: 'Błąd serwera.' });
      }
    });
    return;
  }

  return serveStatic(req, res);
});

server.listen(PORT, () => {
  console.log(`Server running at http://localhost:${PORT}`);
});
