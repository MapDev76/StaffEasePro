<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../assistant.php';
require_once __DIR__ . '/../models/DepartmentModel.php';
require_once __DIR__ . '/../models/ShiftModel.php';

/**
 * AI assistant endpoint ("Giulia") — admin and department_manager only.
 * Gives usage guidance and, when "automatic mode" is on, can propose real
 * actions (create a shift template, send a notification). Proposed actions
 * are never executed automatically: they are staged in the session and
 * only carried out when the user explicitly confirms via the confirm_pending
 * action, which the client triggers from a dedicated Confirm/Cancel prompt.
 */
$currentUser = currentUser();
$currentRole = (string) ($currentUser['role'] ?? '');

if (!isLoggedIn() || !in_array($currentRole, ['admin', 'department_manager'], true)) {
    jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
}

if (!assistantIsAvailable()) {
    jsonResponse(['ok' => false, 'error' => 'assistant_unavailable'], 503);
}

$pdo = getPDO();
$shiftModel = new ShiftModel($pdo);

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;
$action = $input['action'] ?? ($_GET['action'] ?? 'send');

$userId = (int) ($currentUser['id'] ?? 0);
$pendingSessionKey = 'assistant_pending_action_' . $userId;
$pendingTtlSeconds = 900;

/**
 * Resolves the scope this user is allowed to act within: their own company
 * (admin) or their own department (department_manager). Never trusts a
 * scope value coming from the model — every tool re-derives it here.
 */
$resolveScope = static function () use ($pdo, $currentRole, $currentUser): array {
    if ($currentRole === 'department_manager') {
        $departmentId = (int) ($currentUser['department_id'] ?? 0);
        if ($departmentId <= 0) {
            jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
        }
        $stmt = $pdo->prepare('SELECT company_id FROM departments WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $departmentId]);
        $companyId = (int) ($stmt->fetchColumn() ?: 0);

        return ['role' => 'department_manager', 'company_id' => $companyId, 'department_id' => $departmentId];
    }

    $companyId = (int) ($currentUser['company_id'] ?? 0);
    if ($companyId <= 0) {
        $userId2 = (int) ($currentUser['id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT d.company_id FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId2]);
        $companyId = (int) ($stmt->fetchColumn() ?: 0);
    }
    if ($companyId <= 0) {
        jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
    }

    return ['role' => 'admin', 'company_id' => $companyId, 'department_id' => 0];
};

$scope = $resolveScope();

/**
 * True when the given department belongs to this user's allowed scope.
 */
$departmentInScope = static function (int $departmentId) use ($pdo, $scope): bool {
    if ($departmentId <= 0) {
        return false;
    }
    if ($scope['role'] === 'department_manager') {
        return $departmentId === $scope['department_id'];
    }
    $stmt = $pdo->prepare('SELECT company_id FROM departments WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $departmentId]);
    return (int) ($stmt->fetchColumn() ?: 0) === $scope['company_id'];
};

$departmentName = static function (int $departmentId) use ($pdo): string {
    $stmt = $pdo->prepare('SELECT name FROM departments WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $departmentId]);
    return (string) ($stmt->fetchColumn() ?: ('#' . $departmentId));
};

/**
 * Recipient ids a notification from the current scope would reach —
 * shared by the proposal preview (for the confirmation summary) and the
 * real send.
 */
$resolveNotificationRecipients = static function () use ($pdo, $scope): array {
    $sql = "SELECT u.id FROM users u LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.role = 'employee' AND u.status = 'active'";
    $params = [];
    if ($scope['role'] === 'department_manager') {
        $sql .= ' AND u.department_id = :department_id';
        $params['department_id'] = $scope['department_id'];
    } else {
        $sql .= ' AND COALESCE(u.company_id, d.company_id) = :company_id';
        $params['company_id'] = $scope['company_id'];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
};

/**
 * Executes the real, side-effecting action for a staged pending action.
 * Used only by the confirm_pending endpoint below — never called directly
 * from the model's tool loop.
 */
$executePendingAction = function (array $pending) use ($pdo, $shiftModel, $userId, $resolveNotificationRecipients): array {
    if ($pending['type'] === 'create_shift') {
        $p = $pending['input'];
        $shiftId = $shiftModel->create([
            'department_id' => (int) $p['department_id'],
            'name' => $p['name'],
            'icon' => $p['icon'] ?? null,
            'color' => $p['color'] ?? null,
            'description' => $p['description'] ?? null,
            'kind' => 'work',
            'start_time' => $p['start_time'],
            'end_time' => $p['end_time'],
        ]);
        return [
            'type' => 'create_shift',
            'shift_id' => $shiftId,
            'name' => $p['name'],
            'department_id' => (int) $p['department_id'],
        ];
    }

    if ($pending['type'] === 'send_notification') {
        $p = $pending['input'];
        $targetIds = $resolveNotificationRecipients();
        if (empty($targetIds)) {
            return ['error' => 'no eligible recipients'];
        }
        $insert = $pdo->prepare(
            "INSERT INTO requests (user_id, sender_id, type, title, message, status, requires_response)
             VALUES (:user_id, :sender_id, 'notification', :title, :message, 'pending', :requires_response)"
        );
        $pdo->beginTransaction();
        try {
            foreach ($targetIds as $targetUserId) {
                $insert->execute([
                    'user_id' => $targetUserId,
                    'sender_id' => $userId,
                    'title' => $p['title'],
                    'message' => $p['message'],
                    'requires_response' => !empty($p['requires_response']) ? 1 : 0,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['error' => 'failed to send notification'];
        }

        return ['type' => 'send_notification', 'title' => $p['title'], 'recipients' => count($targetIds)];
    }

    return ['error' => 'unknown pending action'];
};

// ---------------------------------------------------------------------
// history / clear / confirm_pending / cancel_pending — no model call.
// ---------------------------------------------------------------------

if ($action === 'history') {
    $stmt = $pdo->prepare(
        'SELECT role, content, actions_json, created_at FROM ai_assistant_messages
         WHERE user_id = :user_id ORDER BY id ASC LIMIT 100'
    );
    $stmt->execute(['user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $messages = array_map(static function (array $row): array {
        return [
            'role' => $row['role'],
            'content' => $row['content'],
            'actions' => $row['actions_json'] ? json_decode($row['actions_json'], true) : [],
            'created_at' => $row['created_at'],
        ];
    }, $rows);

    jsonResponse([
        'ok' => true,
        'messages' => $messages,
        'pending_action' => $_SESSION[$pendingSessionKey] ?? null,
    ]);
}

if ($action === 'clear') {
    $stmt = $pdo->prepare('DELETE FROM ai_assistant_messages WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    unset($_SESSION[$pendingSessionKey]);
    jsonResponse(['ok' => true]);
}

$saveMessage = $pdo->prepare(
    'INSERT INTO ai_assistant_messages (user_id, role, content, actions_json) VALUES (:user_id, :role, :content, :actions_json)'
);

if ($action === 'confirm_pending') {
    $pending = $_SESSION[$pendingSessionKey] ?? null;
    unset($_SESSION[$pendingSessionKey]);
    if (!is_array($pending) || (time() - (int) ($pending['created_at'] ?? 0)) > $pendingTtlSeconds) {
        jsonResponse(['ok' => false, 'error' => 'no_pending_action'], 400);
    }

    $result = $executePendingAction($pending);
    $executedActions = isset($result['error']) ? [] : [$result];
    $replyText = isset($result['error'])
        ? t('assistant.pending_failed')
        : t('assistant.pending_confirmed');

    $saveMessage->execute([
        'user_id' => $userId,
        'role' => 'assistant',
        'content' => $replyText,
        'actions_json' => empty($executedActions) ? null : json_encode($executedActions, JSON_UNESCAPED_UNICODE),
    ]);

    jsonResponse(['ok' => true, 'reply' => $replyText, 'actions' => $executedActions, 'pending_action' => null]);
}

if ($action === 'cancel_pending') {
    unset($_SESSION[$pendingSessionKey]);
    $replyText = t('assistant.pending_cancelled');
    $saveMessage->execute(['user_id' => $userId, 'role' => 'assistant', 'content' => $replyText, 'actions_json' => null]);
    jsonResponse(['ok' => true, 'reply' => $replyText, 'actions' => [], 'pending_action' => null]);
}

if ($action !== 'send') {
    jsonResponse(['ok' => false, 'error' => 'Unknown action'], 400);
}

$userMessage = trim((string) ($input['message'] ?? ''));
if ($userMessage === '') {
    jsonResponse(['ok' => false, 'error' => 'message is required'], 400);
}
$autoMode = !empty($input['auto_mode']);

// A new message implicitly drops any earlier unconfirmed proposal — the
// conversation moved on, so acting on a stale proposal later would be
// surprising rather than helpful.
unset($_SESSION[$pendingSessionKey]);

// ---------------------------------------------------------------------
// Tool definitions and executor. Every tool re-validates department/company
// ownership against $scope — the model's own input is never trusted as an
// authorization boundary. Read-only tools are always available; the two
// propose_* tools (which are how anything with real effects happens) are
// only exposed when the user turned automatic mode on.
// ---------------------------------------------------------------------

$tools = [
    [
        'name' => 'list_departments',
        'description' => "Lists the departments the current user can manage, with their id and name. Call this first when you need a department_id and don't already have one from the conversation.",
        'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    ],
    [
        'name' => 'list_employees',
        'description' => 'Lists active employees, optionally filtered by department_id. Returns id, name, and department.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'department_id' => ['type' => 'integer', 'description' => 'Optional. Restrict to one department.'],
            ],
            'additionalProperties' => false,
        ],
    ],
    [
        'name' => 'list_shifts',
        'description' => 'Lists existing shift templates (work and system kinds) for a department.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'department_id' => ['type' => 'integer', 'description' => 'Department to list shifts for.'],
            ],
            'required' => ['department_id'],
            'additionalProperties' => false,
        ],
    ],
];

// The two propose_* tools are ALWAYS declared, regardless of auto_mode —
// even on turns where it's off. Groq (and OpenAI-style providers generally)
// validate every tool_call referenced anywhere in the conversation history
// against the CURRENT request's tools array; since auto_mode can be toggled
// between messages, omitting these tools on an off-turn would make an
// earlier on-turn's proposal in history invalid and break the whole
// request. Whether auto_mode actually allows executing them is enforced at
// call time in $executeTool below — the model can *see* the tool, but
// calling it while auto_mode is off returns an error instead of staging
// anything.
$tools[] = [
    'name' => 'propose_create_shift',
    'description' => 'Stages a new work shift template (name, hours, optional color/icon/description) for a department. This does NOT create it yet — it only prepares the action so the user can review and confirm it in the UI. Only usable when automatic mode is on. Call this once you have every required field; do not call it just to check whether the user wants to proceed.',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'department_id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'start_time' => ['type' => 'string', 'description' => 'HH:MM 24h format'],
            'end_time' => ['type' => 'string', 'description' => 'HH:MM 24h format'],
            'color' => ['type' => 'string', 'description' => 'Optional hex color, e.g. #2f6fed'],
            'icon' => ['type' => 'string', 'description' => 'Optional icon identifier'],
            'description' => ['type' => 'string'],
        ],
        'required' => ['department_id', 'name', 'start_time', 'end_time'],
        'additionalProperties' => false,
    ],
];
$tools[] = [
    'name' => 'propose_send_notification',
    'description' => 'Stages a notification to employees. This does NOT send it yet — it only prepares the action so the user can review and confirm it in the UI. Only usable when automatic mode is on. department_manager reaches only their own department; admin reaches their whole company.',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'message' => ['type' => 'string'],
            'requires_response' => ['type' => 'boolean', 'description' => 'Whether the recipient must approve or reject it.'],
        ],
        'required' => ['title', 'message'],
        'additionalProperties' => false,
    ],
];

$pendingActionResult = null;

$executeTool = function (string $name, array $toolInput) use (
    $pdo,
    $scope,
    $departmentInScope,
    $departmentName,
    $resolveNotificationRecipients,
    $pendingSessionKey,
    $autoMode,
    &$pendingActionResult
): array {
    if (in_array($name, ['propose_create_shift', 'propose_send_notification'], true) && !$autoMode) {
        return ['error' => 'automatic mode is off — tell the user to enable it if they want you to perform this action, otherwise describe how to do it themselves'];
    }

    if ($name === 'list_departments') {
        if ($scope['role'] === 'department_manager') {
            $stmt = $pdo->prepare('SELECT id, name FROM departments WHERE id = :id');
            $stmt->execute(['id' => $scope['department_id']]);
        } else {
            $stmt = $pdo->prepare('SELECT id, name FROM departments WHERE company_id = :company_id ORDER BY name ASC');
            $stmt->execute(['company_id' => $scope['company_id']]);
        }
        return ['departments' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    if ($name === 'list_employees') {
        $departmentId = (int) ($toolInput['department_id'] ?? 0);
        $sql = "SELECT u.id, u.first_name, u.last_name, d.name AS department_name, u.department_id
                FROM users u LEFT JOIN departments d ON d.id = u.department_id
                WHERE u.role = 'employee' AND u.status = 'active'";
        $params = [];
        if ($departmentId > 0) {
            if (!$departmentInScope($departmentId)) {
                return ['error' => 'department out of scope'];
            }
            $sql .= ' AND u.department_id = :department_id';
            $params['department_id'] = $departmentId;
        } elseif ($scope['role'] === 'department_manager') {
            $sql .= ' AND u.department_id = :department_id';
            $params['department_id'] = $scope['department_id'];
        } else {
            $sql .= ' AND COALESCE(u.company_id, d.company_id) = :company_id';
            $params['company_id'] = $scope['company_id'];
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY u.first_name ASC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $employees = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'department' => $row['department_name'],
            ];
        }, $rows);
        return ['employees' => $employees];
    }

    if ($name === 'list_shifts') {
        $departmentId = (int) ($toolInput['department_id'] ?? 0);
        if (!$departmentInScope($departmentId)) {
            return ['error' => 'department out of scope'];
        }
        $stmt = $pdo->prepare('SELECT id, name, kind, start_time, end_time FROM shifts WHERE department_id = :id ORDER BY name ASC');
        $stmt->execute(['id' => $departmentId]);
        return ['shifts' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    if ($name === 'propose_create_shift') {
        $departmentId = (int) ($toolInput['department_id'] ?? 0);
        if (!$departmentInScope($departmentId)) {
            return ['error' => 'department out of scope'];
        }
        $shiftName = trim((string) ($toolInput['name'] ?? ''));
        $startTime = trim((string) ($toolInput['start_time'] ?? ''));
        $endTime = trim((string) ($toolInput['end_time'] ?? ''));
        if ($shiftName === '' || $startTime === '' || $endTime === '') {
            return ['error' => 'name, start_time and end_time are required'];
        }

        $summary = t('assistant.proposal_create_shift', [
            'name' => $shiftName,
            'department' => $departmentName($departmentId),
            'start' => $startTime,
            'end' => $endTime,
        ]);
        $pending = [
            'type' => 'create_shift',
            'input' => [
                'department_id' => $departmentId,
                'name' => $shiftName,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'color' => $toolInput['color'] ?? null,
                'icon' => $toolInput['icon'] ?? null,
                'description' => $toolInput['description'] ?? null,
            ],
            'summary' => $summary,
            'created_at' => time(),
        ];
        $_SESSION[$pendingSessionKey] = $pending;
        $pendingActionResult = $pending;

        return ['ok' => true, 'status' => 'awaiting_user_confirmation', 'summary' => $summary];
    }

    if ($name === 'propose_send_notification') {
        $title = trim((string) ($toolInput['title'] ?? ''));
        $message = trim((string) ($toolInput['message'] ?? ''));
        if ($title === '' || $message === '') {
            return ['error' => 'title and message are required'];
        }
        $recipientCount = count($resolveNotificationRecipients());
        if ($recipientCount === 0) {
            return ['error' => 'no eligible recipients'];
        }

        $summary = t('assistant.proposal_send_notification', [
            'title' => $title,
            'message' => $message,
            'count' => $recipientCount,
        ]);
        $pending = [
            'type' => 'send_notification',
            'input' => [
                'title' => $title,
                'message' => $message,
                'requires_response' => !empty($toolInput['requires_response']),
            ],
            'summary' => $summary,
            'created_at' => time(),
        ];
        $_SESSION[$pendingSessionKey] = $pending;
        $pendingActionResult = $pending;

        return ['ok' => true, 'status' => 'awaiting_user_confirmation', 'summary' => $summary];
    }

    return ['error' => 'unknown tool'];
};

// ---------------------------------------------------------------------
// System prompt + conversation history.
// ---------------------------------------------------------------------

$scopeLabel = $scope['role'] === 'department_manager'
    ? 'Sei collegato come manager di reparto: puoi vedere e agire solo sul tuo reparto.'
    : 'Sei collegato come admin: puoi vedere e agire su tutta la tua azienda (tutti i reparti).';

$autoModeLabel = $autoMode
    ? 'La modalità automatica è ATTIVA: puoi proporre azioni reali (creare un turno, inviare una notifica) usando gli strumenti propose_create_shift e propose_send_notification.'
    : 'La modalità automatica è DISATTIVATA: gli strumenti propose_create_shift e propose_send_notification esistono ma se li chiami restituiscono un errore, perché l\'utente non ha ancora attivato la modalità automatica. NON chiamarli in questo stato. Se l\'utente chiede di fare qualcosa di concreto, spiegagli passo dopo passo come farlo lui stesso (come una guida), e menziona che può attivare la modalità automatica se vuole che sia tu a farlo.';

$systemPrompt = <<<PROMPT
Ti chiami Giulia, sei l'assistente integrato di StaffEase Pro, un'app di gestione turni e personale.
Aiuti amministratori e manager di reparto a capire come usare l'app e, quando possibile, a compiere azioni concrete.

Panoramica delle funzionalità principali dell'app, per aiutarti a rispondere anche senza usare strumenti:
- Aziende e reparti: gestiti dal super admin/admin, ogni reparto ha turni e dipendenti propri.
- Turni: modelli (nome, orario, colore, icona) creati tramite un wizard a fasi; esistono anche turni di
  sistema (riposo, ferie, malattia, straordinario) gestiti automaticamente.
- Pianificazione: un wizard a 5 fasi permette di scegliere periodo/reparto, turni da assegnare,
  dipendenti con le loro preferenze di riposo/lavoro per quel periodo, esegue l'assegnazione automatica
  e mostra il risultato in un calendario modificabile manualmente (drag & drop).
- Notifiche: admin e manager possono inviare notifiche ai dipendenti, anche con richiesta di
  approvazione/rifiuto.
- Presenze: i dipendenti firmano la presenza digitalmente all'inizio/fine turno, da postazioni autorizzate.

{$scopeLabel}
{$autoModeLabel}
Non inventare dati: usa sempre gli strumenti di lettura (list_departments, list_employees, list_shifts) per
rispondere a domande su reparti, dipendenti o turni esistenti, invece di indovinare.
Rispondi sempre nella stessa lingua del messaggio dell'utente. Sii conciso e concreto.
Non nominare mai gli strumenti interni (list_departments, propose_create_shift, ecc.) parlando con
l'utente: sono dettagli tecnici invisibili a lui. Quando descrivi come si fa qualcosa manualmente, descrivi
i passaggi nell'interfaccia dell'app (es. "apri Impostazioni, scheda Turni..."), non le funzioni che usi tu.

Regola sugli strumenti propose_*: NON eseguono l'azione, la mettono solo in attesa di conferma da parte
dell'utente tramite un bottone "Conferma"/"Annulla" che l'interfaccia mostra automaticamente subito dopo
la tua chiamata allo strumento. Chiamali solo quando hai già tutti i dati necessari (se mancano, chiedili
prima). Non serve che tu chieda di nuovo "confermi?" a parole né che inviti l'utente a scrivere sì/no in
chat: i pulsanti bastano.

Regola importante sull'uso degli strumenti: se per eseguire un'azione ti serve un department_id (es. per
proporre un turno) e l'utente ha indicato solo il NOME del reparto (non un numero), NON indovinare mai l'ID
e non rifiutare l'azione per questo motivo. Chiama prima list_departments, trova l'id corrispondente al nome
indicato, e SOLO DOPO chiama lo strumento con quell'id. Fai lo stesso per i dipendenti con list_employees.

Regola critica anti-invenzione: non dire MAI di aver creato un turno, inviato una notifica o eseguito
qualsiasi altra azione con effetti reali: quelle azioni avvengono SOLO quando l'utente clicca "Conferma",
un passaggio che avviene fuori da questa conversazione. Il tuo ruolo si ferma alla proposta.
PROMPT;

$historyStmt = $pdo->prepare(
    'SELECT role, content FROM ai_assistant_messages WHERE user_id = :user_id ORDER BY id ASC LIMIT 40'
);
$historyStmt->execute(['user_id' => $userId]);
$historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$messages = [];
foreach ($historyRows as $row) {
    $messages[] = ['role' => $row['role'], 'content' => $row['content']];
}
$messages[] = ['role' => 'user', 'content' => $userMessage];

// Persist the user's message immediately so it survives even if the
// model call fails.
$saveMessage->execute(['user_id' => $userId, 'role' => 'user', 'content' => $userMessage, 'actions_json' => null]);

// ---------------------------------------------------------------------
// Tool-use loop (bounded).
// ---------------------------------------------------------------------

$finalText = '';
$maxIterations = 4;

try {
    for ($i = 0; $i < $maxIterations; $i++) {
        $response = assistantChat($systemPrompt, $messages, $tools);
        $stopReason = $response['stop_reason'] ?? '';
        $content = $response['content'] ?? [];

        if ($stopReason !== 'tool_use') {
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $finalText .= $block['text'];
                }
            }
            break;
        }

        // Append the assistant turn (including tool_use blocks) then run
        // every requested tool and append a single user turn with all
        // tool_result blocks, per the Messages API tool-use contract.
        $messages[] = ['role' => 'assistant', 'content' => $content];

        $toolResults = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }
            $toolName = (string) ($block['name'] ?? '');
            $toolInput = is_array($block['input'] ?? null) ? $block['input'] : [];
            $result = $executeTool($toolName, $toolInput);
            $toolResults[] = [
                'type' => 'tool_result',
                'tool_use_id' => $block['id'],
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $toolResults];

        // Once a proposal is staged, stop the loop — the user must decide
        // before anything else happens.
        if ($pendingActionResult !== null) {
            break;
        }
    }
} catch (Throwable $callError) {
    assistantLog('Tool loop error: ' . $callError->getMessage());
    jsonResponse(['ok' => false, 'error' => 'assistant_error'], 502);
}

if ($finalText === '' && $pendingActionResult !== null) {
    $finalText = $pendingActionResult['summary'];
} elseif ($finalText === '') {
    $finalText = t('common.error');
}

$saveMessage->execute([
    'user_id' => $userId,
    'role' => 'assistant',
    'content' => $finalText,
    'actions_json' => null,
]);

jsonResponse([
    'ok' => true,
    'reply' => $finalText,
    'actions' => [],
    'pending_action' => $pendingActionResult,
]);
