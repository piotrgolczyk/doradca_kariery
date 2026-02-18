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

        $groupsIn = $stage['groups'] ?? [];
        $groupsOut = [];

        foreach ($groupsIn as $groupIdx => $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupId = trim((string)($group['group_id'] ?? ''));
            $groupTitle = trim((string)($group['title'] ?? ''));
            $minPoints = max(0, (int)($group['min_points_to_advance'] ?? 0));

            if ($groupId === '') {
                $groupId = 'obszar_' . ($groupIdx + 1);
            }
            if ($groupTitle === '') {
                $groupTitle = 'Obszar ' . ($groupIdx + 1);
            }

            $topicsIn = $group['topics'] ?? [];
            $topicsOut = [];
            foreach ($topicsIn as $topicIdx => $topic) {
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
                'min_points_to_advance' => $minPoints,
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
        throw new RuntimeException('Musisz mieć co najmniej 1 etap z 1 obszarem i 1 zadaniem.');
    }

    return ['stages' => $stagesOut];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = (string)($_POST['topics_json'] ?? '');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $error = 'Nie udało się odczytać zmian. Odśwież stronę i spróbuj ponownie.';
    } else {
        try {
            $normalized = normalize_topics_payload($decoded);
            with_file_lock($topicsPath, static function () use ($topicsPath, $normalized): void {
                atomic_write($topicsPath, json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            });
            $message = 'Zapisano zmiany ✅';
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
  <title>Sterowanie etapami i zadaniami</title>
  <link rel="stylesheet" href="public/styles.css" />
</head>
<body class="admin-body">
  <main class="control-wrap">
    <h1>Sterowanie rozmową (etapy i zadania)</h1>
    <p class="control-lead">To miejsce służy do prostego układania ścieżki rozmowy. Najpierw dodajesz <strong>etapy</strong>, a w nich <strong>obszary</strong> i <strong>zadania/pytania</strong>. Opisy przy polach mówią, co wpisać i jaki to ma wpływ.</p>

    <?php if ($message): ?><p class="success"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <div class="control-help">
      <h2>Jak z tego korzystać?</h2>
      <ol>
        <li><strong>Etap</strong> = większy krok rozmowy (np. poznanie, zainteresowania).</li>
        <li><strong>Obszar</strong> = mniejszy blok w etapie.</li>
        <li><strong>Zadanie</strong> = konkretne pytanie, które Emma ma domknąć.</li>
        <li><strong>Waga (punkty)</strong> mówi, jak mocno zadanie liczy się do przejścia dalej.</li>
      </ol>
    </div>

    <form id="controlForm" method="post" class="control-form">
      <input type="hidden" name="topics_json" id="topicsJsonInput" />
      <div id="builder"></div>

      <div class="control-actions">
        <button type="button" id="addStageBtn">+ Dodaj etap</button>
        <button type="submit" id="saveBtn">Zapisz zmiany</button>
      </div>
    </form>
  </main>

  <template id="stageTpl">
    <section class="control-card stage-card">
      <div class="card-head">
        <h3>Etap</h3>
        <button type="button" class="danger remove-stage">Usuń etap</button>
      </div>
      <label>ID etapu (techniczne)
        <input class="stage-id" placeholder="np. poznanie" />
        <small>Krótki unikalny identyfikator bez spacji.</small>
      </label>
      <label>Nazwa etapu (dla Ciebie i podglądu)
        <input class="stage-title" placeholder="np. Poznajmy Cię" />
      </label>
      <div class="groups"></div>
      <button type="button" class="add-group">+ Dodaj obszar w tym etapie</button>
    </section>
  </template>

  <template id="groupTpl">
    <section class="control-card group-card">
      <div class="card-head">
        <h4>Obszar w etapie</h4>
        <button type="button" class="danger remove-group">Usuń obszar</button>
      </div>
      <label>ID obszaru (techniczne)
        <input class="group-id" placeholder="np. tozsamosc" />
      </label>
      <label>Nazwa obszaru
        <input class="group-title" placeholder="np. Tożsamość i preferencje" />
      </label>
      <label>Minimalna liczba punktów do przejścia dalej
        <input class="group-min" type="number" min="0" value="0" />
        <small>Gdy suma wag domkniętych zadań osiągnie ten próg, można przejść dalej.</small>
      </label>
      <div class="topics"></div>
      <button type="button" class="add-topic">+ Dodaj zadanie</button>
    </section>
  </template>

  <template id="topicTpl">
    <section class="control-card topic-card">
      <div class="card-head">
        <h5>Zadanie / pytanie</h5>
        <button type="button" class="danger remove-topic">Usuń zadanie</button>
      </div>
      <label>ID zadania (techniczne)
        <input class="topic-id" placeholder="np. imie" />
      </label>
      <label>Nazwa zadania
        <input class="topic-title" placeholder="np. Imię użytkownika" />
      </label>
      <label>Cel zadania
        <textarea class="topic-goal" rows="2" placeholder="Co chcemy ustalić?"></textarea>
      </label>
      <label>Krótka podpowiedź pytania
        <input class="topic-micro" placeholder="np. Zapytaj jak ma na imię." />
      </label>
      <div class="grid-2">
        <label>Waga (punkty)
          <input class="topic-weight" type="number" min="0" value="1" />
        </label>
        <label>Typ
          <select class="topic-type">
            <option value="question_goal">question_goal</option>
            <option value="internal_comment">internal_comment</option>
          </select>
        </label>
      </div>
      <div class="grid-2">
        <label><input type="checkbox" class="topic-required" /> Wymagane (must-have)</label>
        <label><input type="checkbox" class="topic-record" checked /> Zapisz one-liner po domknięciu</label>
      </div>
      <div class="grid-2">
        <label>Klucz pamięci (fact_key)
          <input class="topic-fact" placeholder="np. name" />
        </label>
        <label><input type="checkbox" class="topic-confirm" /> Pytaj o potwierdzenie przy zmianie</label>
      </div>
    </section>
  </template>

  <script>
    const initialTopics = <?= json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const builder = document.getElementById('builder');
    const form = document.getElementById('controlForm');
    const hiddenInput = document.getElementById('topicsJsonInput');
    const addStageBtn = document.getElementById('addStageBtn');
    const saveBtn = document.getElementById('saveBtn');

    let dirty = false;

    function markDirty() {
      dirty = true;
    }

    function createStage(stage = {}) {
      const node = document.getElementById('stageTpl').content.firstElementChild.cloneNode(true);
      node.querySelector('.stage-id').value = stage.stage_id || '';
      node.querySelector('.stage-title').value = stage.title || '';
      const groupsBox = node.querySelector('.groups');

      (stage.groups || []).forEach((group) => groupsBox.appendChild(createGroup(group)));
      if ((stage.groups || []).length === 0) {
        groupsBox.appendChild(createGroup());
      }

      node.querySelector('.add-group').onclick = () => {
        groupsBox.appendChild(createGroup());
        markDirty();
      };
      node.querySelector('.remove-stage').onclick = () => {
        node.remove();
        markDirty();
      };

      node.querySelectorAll('input,textarea,select').forEach((el) => {
        el.addEventListener('input', markDirty);
        el.addEventListener('change', markDirty);
      });

      return node;
    }

    function createGroup(group = {}) {
      const node = document.getElementById('groupTpl').content.firstElementChild.cloneNode(true);
      node.querySelector('.group-id').value = group.group_id || '';
      node.querySelector('.group-title').value = group.title || '';
      node.querySelector('.group-min').value = group.min_points_to_advance ?? 0;
      const topicsBox = node.querySelector('.topics');

      (group.topics || []).forEach((topic) => topicsBox.appendChild(createTopic(topic)));
      if ((group.topics || []).length === 0) {
        topicsBox.appendChild(createTopic());
      }

      node.querySelector('.add-topic').onclick = () => {
        topicsBox.appendChild(createTopic());
        markDirty();
      };
      node.querySelector('.remove-group').onclick = () => {
        node.remove();
        markDirty();
      };

      node.querySelectorAll('input,textarea,select').forEach((el) => {
        el.addEventListener('input', markDirty);
        el.addEventListener('change', markDirty);
      });

      return node;
    }

    function createTopic(topic = {}) {
      const node = document.getElementById('topicTpl').content.firstElementChild.cloneNode(true);
      node.querySelector('.topic-id').value = topic.topic_id || '';
      node.querySelector('.topic-title').value = topic.title || '';
      node.querySelector('.topic-goal').value = topic.goal || '';
      node.querySelector('.topic-micro').value = topic.micro_prompt || '';
      node.querySelector('.topic-weight').value = topic.weight ?? 1;
      node.querySelector('.topic-type').value = topic.type || 'question_goal';
      node.querySelector('.topic-required').checked = !!topic.required;
      node.querySelector('.topic-record').checked = topic.record_on_close !== false;
      node.querySelector('.topic-fact').value = topic.fact_key || '';
      node.querySelector('.topic-confirm').checked = !!topic.confirmation_required_on_change;

      node.querySelector('.remove-topic').onclick = () => {
        node.remove();
        markDirty();
      };

      node.querySelectorAll('input,textarea,select').forEach((el) => {
        el.addEventListener('input', markDirty);
        el.addEventListener('change', markDirty);
      });

      return node;
    }

    function collectData() {
      const stages = Array.from(builder.querySelectorAll('.stage-card')).map((stageNode) => {
        const groups = Array.from(stageNode.querySelectorAll('.group-card')).map((groupNode) => {
          const topics = Array.from(groupNode.querySelectorAll('.topic-card')).map((topicNode) => ({
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
      if ((initialTopics.stages || []).length === 0) {
        builder.appendChild(createStage());
      }
      dirty = false;
    }

    addStageBtn.onclick = () => {
      builder.appendChild(createStage());
      markDirty();
    };

    form.addEventListener('submit', () => {
      const payload = collectData();
      hiddenInput.value = JSON.stringify(payload);
      dirty = false;
      saveBtn.textContent = 'Zapisywanie...';
    });

    window.addEventListener('beforeunload', (e) => {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = 'Masz niezapisane zmiany. Czy chcesz je zapisać przed wyjściem?';
    });

    renderInitial();
  </script>
</body>
</html>
