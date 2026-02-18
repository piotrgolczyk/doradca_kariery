<?php

declare(strict_types=1);
require_once __DIR__ . '/api/bootstrap.php';

$topicsPath = __DIR__ . '/data/topics.json';
$message = null;
$error = null;

function normalize_topics_payload(array $payload): array
{
    $stagesIn = $payload['stages'] ?? [];
    $stagesOut = [];

    foreach ($stagesIn as $stageIdx => $stage) {
        if (!is_array($stage)) {
            continue;
        }

        $stageId = trim((string)($stage['stage_id'] ?? ''));
        $stageTitle = trim((string)($stage['title'] ?? ''));
        if ($stageId === '') {
            $stageId = 'etap_' . ($stageIdx + 1);
        }
        if ($stageTitle === '') {
            $stageTitle = 'Etap ' . ($stageIdx + 1);
        }

        $groupsOut = [];
        foreach (($stage['groups'] ?? []) as $groupIdx => $group) {
            if (!is_array($group)) {
                continue;
            }

            $groupId = trim((string)($group['group_id'] ?? ''));
            $groupTitle = trim((string)($group['title'] ?? ''));
            if ($groupId === '') {
                $groupId = 'obszar_' . ($groupIdx + 1);
            }
            if ($groupTitle === '') {
                $groupTitle = 'Obszar ' . ($groupIdx + 1);
            }

            $topicsOut = [];
            foreach (($group['topics'] ?? []) as $topicIdx => $topic) {
                if (!is_array($topic)) {
                    continue;
                }

                $topicId = trim((string)($topic['topic_id'] ?? ''));
                $topicTitle = trim((string)($topic['title'] ?? ''));
                if ($topicId === '') {
                    $topicId = 'zadanie_' . ($topicIdx + 1);
                }
                if ($topicTitle === '') {
                    $topicTitle = 'Zadanie ' . ($topicIdx + 1);
                }

                $topicsOut[] = [
                    'topic_id' => $topicId,
                    'title' => $topicTitle,
                    'weight' => max(0, (int)($topic['weight'] ?? 1)),
                    'required' => (bool)($topic['required'] ?? false),
                    'type' => (string)($topic['type'] ?? 'question_goal'),
                    'goal' => trim((string)($topic['goal'] ?? '')),
                    'micro_prompt' => trim((string)($topic['micro_prompt'] ?? '')),
                    'fact_key' => trim((string)($topic['fact_key'] ?? '')),
                    'record_on_close' => (bool)($topic['record_on_close'] ?? true),
                    'confirmation_required_on_change' => (bool)($topic['confirmation_required_on_change'] ?? false),
                ];
            }

            if (empty($topicsOut)) {
                continue;
            }

            $groupsOut[] = [
                'group_id' => $groupId,
                'title' => $groupTitle,
                'min_points_to_advance' => max(0, (int)($group['min_points_to_advance'] ?? 0)),
                'topics' => $topicsOut,
            ];
        }

        if (empty($groupsOut)) {
            continue;
        }

        $stagesOut[] = [
            'stage_id' => $stageId,
            'title' => $stageTitle,
            'groups' => $groupsOut,
        ];
    }

    if (empty($stagesOut)) {
        throw new RuntimeException('Dodaj minimum 1 etap, 1 obszar i 1 zadanie.');
    }

    return ['stages' => $stagesOut];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = (string)($_POST['topics_json'] ?? '');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $error = 'Nie udało się odczytać formularza. Odśwież stronę i spróbuj jeszcze raz.';
    } else {
        try {
            $normalized = normalize_topics_payload($decoded);
            with_file_lock($topicsPath, static function () use ($topicsPath, $normalized): void {
                atomic_write($topicsPath, json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            });
            $message = 'Zapis gotowy — zmiany zostały zapisane ✅';
        } catch (Throwable $e) {
            $error = 'Nie udało się zapisać: ' . $e->getMessage();
        }
    }
}

$topics = topics_data();
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sterowanie etapami</title>
  <link rel="stylesheet" href="public/styles.css" />
</head>
<body class="admin-body">
  <main class="control-page">
    <header class="control-header">
      <div>
        <h1>Sterowanie etapami i zadaniami</h1>
        <p>Tu układasz całą ścieżkę rozmowy Emmy. Wszystko poniżej jest opisane prostym językiem.</p>
      </div>
      <a href="index.php" class="ghost-link">← Wróć do rozmowy</a>
    </header>

    <?php if ($message): ?><div class="notice ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <section class="control-intro-grid">
      <article class="info-box">
        <h2>Jak to działa?</h2>
        <ol>
          <li><strong>Etap</strong> – duży krok (np. „Poznajmy Cię”).</li>
          <li><strong>Obszar</strong> – część etapu (np. „Tożsamość”).</li>
          <li><strong>Zadanie</strong> – konkretne pytanie/temat do domknięcia.</li>
          <li><strong>Waga</strong> – liczba punktów za to zadanie.</li>
        </ol>
      </article>
      <article class="stats-box">
        <h2>Szybki podgląd</h2>
        <p><strong>Etapy:</strong> <span id="countStages">0</span></p>
        <p><strong>Obszary:</strong> <span id="countGroups">0</span></p>
        <p><strong>Zadania:</strong> <span id="countTopics">0</span></p>
        <p><strong>Status:</strong> <span id="saveStatus">Brak niezapisanych zmian</span></p>
      </article>
    </section>

    <form id="controlForm" class="control-form" method="post">
      <input type="hidden" name="topics_json" id="topicsJsonInput" />

      <div id="builder" class="builder"></div>

      <div class="sticky-actions">
        <button type="button" id="addStageBtn" class="btn-secondary">+ Dodaj etap</button>
        <button type="submit" id="saveBtn" class="btn-primary">Zapisz</button>
      </div>
    </form>
  </main>

  <template id="stageTpl">
    <section class="block stage-block">
      <div class="block-title-row">
        <h3>Etap</h3>
        <button type="button" class="btn-danger remove-stage">Usuń etap</button>
      </div>
      <div class="grid-2">
        <label>ID etapu
          <input class="stage-id" placeholder="np. poznanie" />
          <small>Techniczne ID bez spacji.</small>
        </label>
        <label>Nazwa etapu
          <input class="stage-title" placeholder="np. Poznajmy Cię" />
          <small>To nazwa widoczna dla Ciebie.</small>
        </label>
      </div>
      <div class="groups"></div>
      <button type="button" class="btn-secondary add-group">+ Dodaj obszar</button>
    </section>
  </template>

  <template id="groupTpl">
    <section class="block group-block">
      <div class="block-title-row">
        <h4>Obszar</h4>
        <button type="button" class="btn-danger remove-group">Usuń obszar</button>
      </div>
      <div class="grid-3">
        <label>ID obszaru
          <input class="group-id" placeholder="np. tozsamosc" />
        </label>
        <label>Nazwa obszaru
          <input class="group-title" placeholder="np. Tożsamość i preferencje" />
        </label>
        <label>Próg punktów
          <input class="group-min" type="number" min="0" value="0" />
          <small>Po tylu punktach można iść dalej.</small>
        </label>
      </div>
      <div class="topics"></div>
      <button type="button" class="btn-secondary add-topic">+ Dodaj zadanie</button>
    </section>
  </template>

  <template id="topicTpl">
    <section class="block topic-block">
      <div class="block-title-row">
        <h5>Zadanie</h5>
        <button type="button" class="btn-danger remove-topic">Usuń zadanie</button>
      </div>
      <div class="grid-3">
        <label>ID zadania
          <input class="topic-id" placeholder="np. imie" />
        </label>
        <label>Nazwa zadania
          <input class="topic-title" placeholder="np. Imię użytkownika" />
        </label>
        <label>Waga (punkty)
          <input class="topic-weight" type="number" min="0" value="1" />
        </label>
      </div>
      <label>Cel zadania
        <textarea class="topic-goal" rows="2" placeholder="Co chcemy ustalić?"></textarea>
      </label>
      <label>Podpowiedź pytania
        <input class="topic-micro" placeholder="np. Zapytaj jak ma na imię." />
      </label>
      <div class="grid-3">
        <label>Typ
          <select class="topic-type">
            <option value="question_goal">question_goal</option>
            <option value="internal_comment">internal_comment</option>
          </select>
        </label>
        <label>Klucz pamięci (fact_key)
          <input class="topic-fact" placeholder="np. name" />
        </label>
        <label class="checkbox-row"><input type="checkbox" class="topic-required" /> Wymagane</label>
      </div>
      <div class="grid-2">
        <label class="checkbox-row"><input type="checkbox" class="topic-record" checked /> Zapisuj one-liner po domknięciu</label>
        <label class="checkbox-row"><input type="checkbox" class="topic-confirm" /> Potwierdzaj zmianę wartości</label>
      </div>
    </section>
  </template>

  <script>
    const initialTopics = <?= json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const builder = document.getElementById('builder');
    const form = document.getElementById('controlForm');
    const hiddenInput = document.getElementById('topicsJsonInput');
    const saveBtn = document.getElementById('saveBtn');
    const addStageBtn = document.getElementById('addStageBtn');
    const saveStatus = document.getElementById('saveStatus');

    let dirty = false;

    function setDirty(flag = true) {
      dirty = flag;
      saveStatus.textContent = dirty ? 'Masz niezapisane zmiany' : 'Brak niezapisanych zmian';
    }

    function updateCounters() {
      const stages = Array.from(builder.querySelectorAll('.stage-block'));
      const groups = Array.from(builder.querySelectorAll('.group-block'));
      const topics = Array.from(builder.querySelectorAll('.topic-block'));
      document.getElementById('countStages').textContent = String(stages.length);
      document.getElementById('countGroups').textContent = String(groups.length);
      document.getElementById('countTopics').textContent = String(topics.length);
    }

    function wireInputs(node) {
      node.querySelectorAll('input,textarea,select').forEach((el) => {
        el.addEventListener('input', () => { setDirty(true); updateCounters(); });
        el.addEventListener('change', () => { setDirty(true); updateCounters(); });
      });
    }

    function createTopic(topic = {}) {
      const node = document.getElementById('topicTpl').content.firstElementChild.cloneNode(true);
      node.querySelector('.topic-id').value = topic.topic_id || '';
      node.querySelector('.topic-title').value = topic.title || '';
      node.querySelector('.topic-weight').value = topic.weight ?? 1;
      node.querySelector('.topic-goal').value = topic.goal || '';
      node.querySelector('.topic-micro').value = topic.micro_prompt || '';
      node.querySelector('.topic-type').value = topic.type || 'question_goal';
      node.querySelector('.topic-fact').value = topic.fact_key || '';
      node.querySelector('.topic-required').checked = !!topic.required;
      node.querySelector('.topic-record').checked = topic.record_on_close !== false;
      node.querySelector('.topic-confirm').checked = !!topic.confirmation_required_on_change;

      node.querySelector('.remove-topic').onclick = () => {
        node.remove();
        setDirty(true);
        updateCounters();
      };

      wireInputs(node);
      return node;
    }

    function createGroup(group = {}) {
      const node = document.getElementById('groupTpl').content.firstElementChild.cloneNode(true);
      node.querySelector('.group-id').value = group.group_id || '';
      node.querySelector('.group-title').value = group.title || '';
      node.querySelector('.group-min').value = group.min_points_to_advance ?? 0;

      const topicsBox = node.querySelector('.topics');
      (group.topics || []).forEach((topic) => topicsBox.appendChild(createTopic(topic)));
      if ((group.topics || []).length === 0) topicsBox.appendChild(createTopic());

      node.querySelector('.add-topic').onclick = () => {
        topicsBox.appendChild(createTopic());
        setDirty(true);
        updateCounters();
      };
      node.querySelector('.remove-group').onclick = () => {
        node.remove();
        setDirty(true);
        updateCounters();
      };

      wireInputs(node);
      return node;
    }

    function createStage(stage = {}) {
      const node = document.getElementById('stageTpl').content.firstElementChild.cloneNode(true);
      node.querySelector('.stage-id').value = stage.stage_id || '';
      node.querySelector('.stage-title').value = stage.title || '';

      const groupsBox = node.querySelector('.groups');
      (stage.groups || []).forEach((group) => groupsBox.appendChild(createGroup(group)));
      if ((stage.groups || []).length === 0) groupsBox.appendChild(createGroup());

      node.querySelector('.add-group').onclick = () => {
        groupsBox.appendChild(createGroup());
        setDirty(true);
        updateCounters();
      };
      node.querySelector('.remove-stage').onclick = () => {
        node.remove();
        setDirty(true);
        updateCounters();
      };

      wireInputs(node);
      return node;
    }

    function collectData() {
      const stages = Array.from(builder.querySelectorAll('.stage-block')).map((stageNode) => {
        const groups = Array.from(stageNode.querySelectorAll('.group-block')).map((groupNode) => {
          const topics = Array.from(groupNode.querySelectorAll('.topic-block')).map((topicNode) => ({
            topic_id: topicNode.querySelector('.topic-id').value.trim(),
            title: topicNode.querySelector('.topic-title').value.trim(),
            weight: Number(topicNode.querySelector('.topic-weight').value || 0),
            required: topicNode.querySelector('.topic-required').checked,
            type: topicNode.querySelector('.topic-type').value,
            goal: topicNode.querySelector('.topic-goal').value.trim(),
            micro_prompt: topicNode.querySelector('.topic-micro').value.trim(),
            fact_key: topicNode.querySelector('.topic-fact').value.trim(),
            record_on_close: topicNode.querySelector('.topic-record').checked,
            confirmation_required_on_change: topicNode.querySelector('.topic-confirm').checked,
          }));

          return {
            group_id: groupNode.querySelector('.group-id').value.trim(),
            title: groupNode.querySelector('.group-title').value.trim(),
            min_points_to_advance: Number(groupNode.querySelector('.group-min').value || 0),
            topics,
          };
        });

        return {
          stage_id: stageNode.querySelector('.stage-id').value.trim(),
          title: stageNode.querySelector('.stage-title').value.trim(),
          groups,
        };
      });

      return { stages };
    }

    function renderInitial() {
      builder.innerHTML = '';
      (initialTopics.stages || []).forEach((stage) => builder.appendChild(createStage(stage)));
      if ((initialTopics.stages || []).length === 0) builder.appendChild(createStage());
      setDirty(false);
      updateCounters();
    }

    addStageBtn.onclick = () => {
      builder.appendChild(createStage());
      setDirty(true);
      updateCounters();
    };

    form.addEventListener('submit', () => {
      hiddenInput.value = JSON.stringify(collectData());
      setDirty(false);
      saveBtn.textContent = 'Zapisywanie...';
    });

    window.addEventListener('beforeunload', (e) => {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = 'Masz niezapisane zmiany. Czy na pewno chcesz wyjść bez zapisu?';
    });

    renderInitial();
  </script>
</body>
</html>
