<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/CompanyModel.php';
require_once __DIR__ . '/../models/DepartmentModel.php';
require_once __DIR__ . '/../services/DocumentSigningService.php';

/**
 * API dashboard endpoint returning JSON useful for AJAX/REST clients.
 *
 * Requires an authenticated session. Returns user/profile and role based
 * stats tailored to the current user's permissions.
 */
if (!isLoggedIn()) {
    jsonResponse([
        'success' => false,
        'message' => 'Login required.',
    ], 401);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;
$action = $input['action'] ?? ($_GET['action'] ?? 'view');

$pdo = getPDO();
ensureSchedulerSchema($pdo);
ensureDocumentStorageSchema($pdo);
ensureCompanyApprovalSchema($pdo);
$userModel = new UserModel($pdo);
$companyModel = new CompanyModel($pdo);
$departmentModel = new DepartmentModel($pdo);
$user = currentUser();
$role = $user['role'] ?? 'employee';
$profile = $userModel->profileWithRelations((int) $user['id']) ?? [];

// Signing helpers are now provided by DocumentSigningService.php (required above).

$resolveAllowedRecipients = static function () use ($pdo, $role, $profile, $user): array {
    $allowedRecipientsSql = 'SELECT u.id
                            FROM users u
                            LEFT JOIN departments d ON d.id = u.department_id
                            WHERE u.status = "active"
                              AND u.id <> :current_user_id';
    $allowedRecipientsParams = [
        'current_user_id' => (int) ($user['id'] ?? 0),
    ];

    if ($role === 'super_admin') {
        // Super admin can share with any active user.
    } elseif ($role === 'admin') {
        $allowedRecipientsSql .= ' AND ((d.company_id = :company_id AND u.role IN ("employee", "department_manager", "admin")) OR u.role = "super_admin")';
        $allowedRecipientsParams['company_id'] = (int) ($profile['company_id'] ?? 0);
    } elseif ($role === 'department_manager') {
        $allowedRecipientsSql .= ' AND ((d.company_id = :company_id AND u.role IN ("employee", "department_manager", "admin")) OR u.role = "super_admin")';
        $allowedRecipientsParams['company_id'] = (int) ($profile['company_id'] ?? 0);
    } else {
        $allowedRecipientsSql .= ' AND u.role = "employee"';
    }

    $allowedRecipientsStmt = $pdo->prepare($allowedRecipientsSql);
    $allowedRecipientsStmt->execute($allowedRecipientsParams);
    $allowedRecipientIds = array_map('intval', $allowedRecipientsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    return [$allowedRecipientIds, array_fill_keys($allowedRecipientIds, true)];
};

$enforceDocumentScope = static function (array $documentRow) use ($role, $profile): void {
    if ($role === 'admin' && (int) ($profile['company_id'] ?? 0) !== (int) ($documentRow['company_id'] ?? 0)) {
        jsonResponse(['success' => false, 'error' => 'Document out of scope'], 403);
    }
    if ($role === 'department_manager' && (int) ($profile['department_id'] ?? 0) !== (int) ($documentRow['department_id'] ?? 0)) {
        jsonResponse(['success' => false, 'error' => 'Document out of scope'], 403);
    }
};

if ($action === 'delete_connection') {
    if ($role !== 'super_admin') {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $connectionId = (int) ($input['connection_id'] ?? 0);
    if ($connectionId <= 0) {
        jsonResponse(['success' => false, 'error' => 'connection_id is required'], 400);
    }

    $deleteStmt = $pdo->prepare('DELETE FROM user_connections WHERE id = :id');
    $deleteStmt->execute(['id' => $connectionId]);

    jsonResponse(['success' => true]);
}

if ($action === 'company_connections') {
    if ($role !== 'super_admin') {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $requestedCompanyId = (int) ($input['company_id'] ?? 0);
    if ($requestedCompanyId <= 0) {
        jsonResponse(['success' => false, 'error' => 'company_id is required'], 400);
    }

    $companyConnectionsStmt = $pdo->prepare(
        'SELECT uc.id AS connection_id,
                uc.last_seen_at,
                uc.logged_out_at,
                CONCAT(u.first_name, " ", u.last_name) AS user_name,
                d.name AS department_name
         FROM user_connections uc
         INNER JOIN users u ON u.id = uc.user_id
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE d.company_id = :company_id
         ORDER BY COALESCE(uc.logged_out_at, uc.last_seen_at, uc.logged_in_at) DESC, uc.id DESC
         LIMIT 10'
    );
    $companyConnectionsStmt->execute(['company_id' => $requestedCompanyId]);
    $rows = $companyConnectionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $connections = array_map(static function (array $row): array {
        return [
            'connection_id' => (int) $row['connection_id'],
            'user_name' => (string) $row['user_name'],
            'department_name' => $row['department_name'] !== null ? (string) $row['department_name'] : null,
            'last_seen_at' => $row['last_seen_at'],
            'is_active' => empty($row['logged_out_at']),
            'time_ago' => timeAgo($row['last_seen_at']),
        ];
    }, $rows);

    jsonResponse(['success' => true, 'connections' => $connections]);
}

if ($action === 'send_general_notifications') {
    if (!in_array($role, ['admin', 'super_admin', 'department_manager'], true)) {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $broadcastTitle = trim((string) ($input['title'] ?? ''));
    $broadcastMessage = trim((string) ($input['message'] ?? ''));
    $broadcastToAll = !empty($input['send_to_all']);
    $broadcastRecipientIds = array_map('intval', is_array($input['recipient_ids'] ?? null) ? $input['recipient_ids'] : []);
    $broadcastRequiresResponse = !empty($input['requires_response']) ? 1 : 0;

    if ($broadcastTitle === '' || $broadcastMessage === '') {
        jsonResponse(['success' => false, 'error' => t('notifications.compose_required_fields')], 400);
    }

    // Scope eligible recipients by role: admin/department_manager stay inside
    // their own company (department_manager further inside their own
    // department); super_admin must name a company explicitly since they have
    // none of their own.
    $eligibleEmployeesSql = "SELECT u.id
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.role = 'employee'
           AND u.status = 'active'";
    $eligibleEmployeesParams = [];

    if ($role === 'admin') {
        $broadcastCompanyId = (int) ($profile['company_id'] ?? 0);
        if ($broadcastCompanyId <= 0) {
            jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
        }
        $eligibleEmployeesSql .= ' AND COALESCE(u.company_id, d.company_id) = :company_id';
        $eligibleEmployeesParams['company_id'] = $broadcastCompanyId;
    } elseif ($role === 'department_manager') {
        $broadcastDepartmentId = (int) ($profile['department_id'] ?? 0);
        if ($broadcastDepartmentId <= 0) {
            jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
        }
        $eligibleEmployeesSql .= ' AND u.department_id = :department_id';
        $eligibleEmployeesParams['department_id'] = $broadcastDepartmentId;
    } else {
        // super_admin: an explicit company_id is required.
        $broadcastCompanyId = (int) ($input['company_id'] ?? 0);
        if ($broadcastCompanyId <= 0) {
            jsonResponse(['success' => false, 'error' => t('common.department_required')], 400);
        }
        $eligibleEmployeesSql .= ' AND COALESCE(u.company_id, d.company_id) = :company_id';
        $eligibleEmployeesParams['company_id'] = $broadcastCompanyId;
    }

    $eligibleEmployeesStmt = $pdo->prepare($eligibleEmployeesSql);
    $eligibleEmployeesStmt->execute($eligibleEmployeesParams);
    $eligibleEmployeeIds = array_map('intval', $eligibleEmployeesStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $targetIds = $broadcastToAll
        ? $eligibleEmployeeIds
        : array_values(array_intersect($eligibleEmployeeIds, $broadcastRecipientIds));

    if (empty($targetIds)) {
        jsonResponse(['success' => false, 'error' => t('notifications.compose_no_recipients')], 400);
    }

    $insertNoticeStmt = $pdo->prepare(
        "INSERT INTO requests (user_id, sender_id, type, title, message, status, requires_response)
         VALUES (:user_id, :sender_id, 'notification', :title, :message, 'pending', :requires_response)"
    );
    $pdo->beginTransaction();
    try {
        foreach ($targetIds as $targetUserId) {
            $insertNoticeStmt->execute([
                'user_id' => $targetUserId,
                'sender_id' => (int) ($user['id'] ?? 0),
                'title' => $broadcastTitle,
                'message' => $broadcastMessage,
                'requires_response' => $broadcastRequiresResponse,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $broadcastError) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['success' => false, 'error' => t('common.error')], 500);
    }

    jsonResponse(['success' => true, 'sent' => count($targetIds)]);
}

if ($action === 'list_notifications') {
    $notifications = userGeneralNotifications($pdo, (int) $user['id']);
    jsonResponse(['success' => true, 'notifications' => $notifications]);
}

if ($action === 'mark_notification_read') {
    $notificationId = (int) ($input['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        jsonResponse(['success' => false, 'error' => 'notification_id is required'], 400);
    }

    // A notification awaiting a decision is not marked "read" by this action;
    // it only leaves "pending" once the recipient approves or rejects it.
    $markStmt = $pdo->prepare(
        'UPDATE requests
         SET status = "read"
         WHERE id = :id AND user_id = :user_id AND type = "notification"
           AND document_id IS NULL AND recipient_id IS NULL AND requires_response = 0'
    );
    $markStmt->execute(['id' => $notificationId, 'user_id' => (int) $user['id']]);

    jsonResponse(['success' => true, 'updated' => $markStmt->rowCount() > 0]);
}

if ($action === 'respond_notification') {
    $notificationId = (int) ($input['notification_id'] ?? 0);
    $decision = strtolower(trim((string) ($input['decision'] ?? '')));
    if ($notificationId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
        jsonResponse(['success' => false, 'error' => 'notification_id and a valid decision are required'], 400);
    }

    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
    $respondStmt = $pdo->prepare(
        'UPDATE requests
         SET status = :status
         WHERE id = :id AND user_id = :user_id AND type = "notification"
           AND document_id IS NULL AND recipient_id IS NULL
           AND requires_response = 1 AND status = "pending"'
    );
    $respondStmt->execute([
        'status' => $newStatus,
        'id' => $notificationId,
        'user_id' => (int) $user['id'],
    ]);

    if ($respondStmt->rowCount() === 0) {
        jsonResponse(['success' => false, 'error' => t('notifications.respond_not_available', ['fallback' => 'This notification cannot be answered anymore.'])], 409);
    }

    jsonResponse(['success' => true, 'status' => $newStatus]);
}

if ($action === 'delete_notification') {
    $notificationId = (int) ($input['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        jsonResponse(['success' => false, 'error' => 'notification_id is required'], 400);
    }

    // Deletable once its lifecycle is over: read (informational) or
    // approved/rejected (already answered).
    $deleteStmt = $pdo->prepare(
        'DELETE FROM requests
         WHERE id = :id AND user_id = :user_id AND type = "notification"
           AND document_id IS NULL AND recipient_id IS NULL
           AND status IN ("read", "approved", "rejected")'
    );
    $deleteStmt->execute(['id' => $notificationId, 'user_id' => (int) $user['id']]);

    if ($deleteStmt->rowCount() === 0) {
        jsonResponse(['success' => false, 'error' => t('notifications.delete_requires_read')], 409);
    }

    jsonResponse(['success' => true]);
}

if ($action === 'set_company_trial') {
    if ($role !== 'super_admin') {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $trialCompanyId = (int) ($input['company_id'] ?? 0);
    if ($trialCompanyId <= 0) {
        jsonResponse(['success' => false, 'error' => 'company_id is required'], 400);
    }

    ensureCompanyApprovalSchema($pdo);

    // No expiry date means "until the next paid subscription": the super
    // admin decides manually when to revisit it, no automatic cutoff.
    $trialNoExpiry = !empty($input['no_expiry']);
    $trialDateInput = trim((string) ($input['trial_ends_at'] ?? ''));

    if ($trialNoExpiry) {
        $trialEndsAtValue = null;
    } else {
        if ($trialDateInput === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $trialDateInput)) {
            jsonResponse(['success' => false, 'error' => t('mail.trial_date_invalid')], 400);
        }
        $trialDateParts = date_parse($trialDateInput);
        if (!empty($trialDateParts['error_count'])) {
            jsonResponse(['success' => false, 'error' => t('mail.trial_date_invalid')], 400);
        }
        // End of that day, so the company stays usable through the chosen date.
        $trialEndsAtValue = $trialDateInput . ' 23:59:59';
    }

    $trialUpdateStmt = $pdo->prepare(
        'UPDATE companies SET trial_ends_at = :trial_ends_at, trial_expired_notified_at = NULL WHERE id = :id'
    );
    $trialUpdateStmt->execute(['trial_ends_at' => $trialEndsAtValue, 'id' => $trialCompanyId]);

    jsonResponse(['success' => true, 'trial_ends_at' => $trialEndsAtValue]);
}

if ($action === 'send_company_notice') {
    if ($role !== 'super_admin') {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $noticeCompanyId = (int) ($input['company_id'] ?? 0);
    $noticeTemplate = trim((string) ($input['template'] ?? ''));
    if ($noticeCompanyId <= 0 || !in_array($noticeTemplate, companyNoticeTemplates(), true)) {
        jsonResponse(['success' => false, 'error' => 'company_id and a valid template are required'], 400);
    }

    $noticeCompanyStmt = $pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
    $noticeCompanyStmt->execute(['id' => $noticeCompanyId]);
    $noticeCompany = $noticeCompanyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$noticeCompany) {
        jsonResponse(['success' => false, 'error' => 'Company not found'], 404);
    }

    // The notice goes to the company owner: the first active admin.
    $noticeOwnerStmt = $pdo->prepare(
        "SELECT id, first_name, last_name, email
         FROM users
         WHERE company_id = :company_id AND role = 'admin' AND status = 'active'
         ORDER BY id ASC LIMIT 1"
    );
    $noticeOwnerStmt->execute(['company_id' => $noticeCompanyId]);
    $noticeOwner = $noticeOwnerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$noticeOwner) {
        jsonResponse(['success' => false, 'error' => t('mail.notice_no_admin')], 404);
    }

    $noticeResult = sendCompanyNotice($pdo, $noticeOwner, $noticeCompany, $noticeTemplate);

    jsonResponse([
        'success' => true,
        'email' => $noticeResult['email'],
        'in_app' => $noticeResult['in_app'],
        'message' => t('mail.notice_sent'),
    ]);
}

if ($action === 'decide_company_approval') {
    if ($role !== 'super_admin') {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $approvalCompanyId = (int) ($input['company_id'] ?? 0);
    $approvalDecision = strtolower(trim((string) ($input['decision'] ?? '')));
    if ($approvalCompanyId <= 0 || !in_array($approvalDecision, ['approve', 'reject'], true)) {
        jsonResponse(['success' => false, 'error' => 'company_id and decision are required'], 400);
    }

    ensureCompanyApprovalSchema($pdo);

    // Same pending row the emailed link would consume, found by company instead
    // of by token: the session already proves who is deciding.
    $pendingStmt = $pdo->prepare(
        "SELECT id, company_id, requested_by_user_id
         FROM company_approvals
         WHERE company_id = :company_id AND decision = 'pending' AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $pendingStmt->execute(['company_id' => $approvalCompanyId]);
    $pendingRow = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pendingRow) {
        jsonResponse(['success' => false, 'error' => t('approval.invalid_body')], 404);
    }

    $approved = $approvalDecision === 'approve';

    try {
        $pdo->beginTransaction();
        $decision = decideCompanyApproval($pdo, $pendingRow, $approved);
        $pdo->commit();
    } catch (Throwable $approvalError) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['success' => false, 'error' => t('common.error')], 500);
    }

    // Tell the applicant, exactly like the emailed decision does.
    try {
        $companyStmt = $pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
        $companyStmt->execute(['id' => $decision['company_id']]);
        $decidedCompany = $companyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Two separate lookups: native prepared statements (EMULATE_PREPARES is
        // off in db.php) reject the same named placeholder used twice.
        $decidedRequester = null;
        $requestedByUserId = (int) ($pendingRow['requested_by_user_id'] ?? 0);
        if ($requestedByUserId > 0) {
            $requesterStmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1');
            $requesterStmt->execute(['id' => $requestedByUserId]);
            $decidedRequester = $requesterStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($decidedRequester === null) {
            $ownerStmt = $pdo->prepare(
                "SELECT first_name, last_name, email FROM users
                 WHERE company_id = :company_id AND role = 'admin' AND status = 'active'
                 ORDER BY id ASC LIMIT 1"
            );
            $ownerStmt->execute(['company_id' => $decision['company_id']]);
            $decidedRequester = $ownerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($decidedRequester) {
            if ($approved) {
                sendCompanyApprovedEmail($decidedRequester, $decidedCompany, $decision['trial_ends_at']);
            } else {
                sendCompanyRejectedEmail($decidedRequester, $decidedCompany);
            }
        }
    } catch (Throwable $mailError) {
        mailLog('dashboard approval notification failed: ' . $mailError->getMessage());
    }

    jsonResponse([
        'success' => true,
        'decision' => $approved ? 'approved' : 'rejected',
        'trial_ends_at' => $decision['trial_ends_at'],
    ]);
}

if ($action === 'change_password') {
    $currentUserId = (int) ($user['id'] ?? 0);
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $currentPassword = (string) ($input['current_password'] ?? '');
    $newPassword = (string) ($input['new_password'] ?? '');
    $newPasswordConfirm = (string) ($input['new_password_confirm'] ?? '');

    // The current password authorises every change made here, password or not.
    $currentUserRow = $userModel->findById($currentUserId);
    if (!$currentUserRow || !password_verify($currentPassword, $currentUserRow['password'])) {
        jsonResponse(['success' => false, 'error' => t('auth.current_password_incorrect')], 400);
    }

    if ($firstName === '' || $lastName === '') {
        jsonResponse(['success' => false, 'error' => t('auth.name_required')], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'error' => t('auth.email_invalid')], 400);
    }

    $existingByEmail = $userModel->findByEmail($email);
    if ($existingByEmail && (int) ($existingByEmail['id'] ?? 0) !== $currentUserId) {
        jsonResponse(['success' => false, 'error' => t('auth.email_taken')], 400);
    }

    // A new password is optional: leaving both fields empty updates the profile only.
    $wantsPasswordChange = $newPassword !== '' || $newPasswordConfirm !== '';
    if ($wantsPasswordChange) {
        $passwordStrengthError = validatePasswordStrength($newPassword);
        if ($passwordStrengthError !== null) {
            jsonResponse(['success' => false, 'error' => $passwordStrengthError], 400);
        }

        if ($newPassword !== $newPasswordConfirm) {
            jsonResponse(['success' => false, 'error' => t('auth.password_mismatch')], 400);
        }
    }

    $updateFields = ['first_name = :first_name', 'last_name = :last_name', 'email = :email'];
    $updateParams = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'id' => $currentUserId,
    ];
    if ($wantsPasswordChange) {
        $updateFields[] = 'password = :password';
        $updateParams['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    $updateStmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $updateFields) . ' WHERE id = :id');
    $updateStmt->execute($updateParams);

    // Keep the session payload in sync so the header stops showing stale details.
    if (isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])) {
        $_SESSION['auth_user']['first_name'] = $firstName;
        $_SESSION['auth_user']['last_name'] = $lastName;
        $_SESSION['auth_user']['email'] = $email;
    }

    jsonResponse(['success' => true, 'password_changed' => $wantsPasswordChange]);
}

if ($action === 'save_planning_document' || $action === 'save_dashboard_document') {
    if (!in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $departmentId = (int) ($input['department_id'] ?? 0);
    $monthStart = trim((string) ($input['month_start'] ?? ''));
    $documentMode = trim((string) ($input['document_mode'] ?? 'planning'));
    if (!in_array($documentMode, ['planning', 'attendance'], true)) {
        $documentMode = 'planning';
    }

    $defaultName = $documentMode === 'attendance' ? 'attendance-signatures.html' : 'planning.csv';
    $fileName = trim((string) ($input['file_name'] ?? $defaultName));
    $fileContentB64 = trim((string) ($input['file_content_b64'] ?? ''));
    if ($fileContentB64 === '') {
        $fileContentB64 = trim((string) ($input['csv_content_b64'] ?? ''));
    }
    $fileMimeType = trim((string) ($input['file_mime_type'] ?? ''));
    if ($fileMimeType === '') {
        $fileMimeType = $documentMode === 'attendance'
            ? 'text/html; charset=utf-8'
            : 'text/csv; charset=utf-8';
    }

    if ($departmentId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $monthStart) || $fileContentB64 === '') {
        jsonResponse(['success' => false, 'error' => 'department_id, month_start and file_content_b64 are required'], 400);
    }

    $departmentLookup = $pdo->prepare('SELECT id, company_id FROM departments WHERE id = :id LIMIT 1');
    $departmentLookup->execute(['id' => $departmentId]);
    $departmentRow = $departmentLookup->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$departmentRow) {
        jsonResponse(['success' => false, 'error' => 'Department not found'], 404);
    }

    if ($role === 'department_manager' && (int) ($profile['department_id'] ?? 0) !== $departmentId) {
        jsonResponse(['success' => false, 'error' => 'Department out of scope'], 403);
    }
    if ($role === 'admin' && (int) ($profile['company_id'] ?? 0) !== (int) ($departmentRow['company_id'] ?? 0)) {
        jsonResponse(['success' => false, 'error' => 'Department out of scope'], 403);
    }

    $decoded = base64_decode($fileContentB64, true);
    if (!is_string($decoded) || $decoded === '') {
        jsonResponse(['success' => false, 'error' => 'Invalid file payload'], 400);
    }
    if (strlen($decoded) > 5 * 1024 * 1024) {
        jsonResponse(['success' => false, 'error' => 'File payload too large'], 400);
    }

    $safeBaseName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $fileName) ?: 'planning.csv';
    $lowerSafeBaseName = strtolower($safeBaseName);
    if ($documentMode === 'attendance') {
        if (!str_ends_with($lowerSafeBaseName, '.html') && !str_ends_with($lowerSafeBaseName, '.htm')) {
            $safeBaseName .= '.html';
        }
    } else {
        if (!str_ends_with($lowerSafeBaseName, '.csv')) {
            $safeBaseName .= '.csv';
        }
    }

    $insertDocument = $pdo->prepare(
        'INSERT INTO documents (user_id, document_type, file_name, file_path, file_blob, file_mime_type, status)
         VALUES (:user_id, :document_type, :file_name, :file_path, :file_blob, :file_mime_type, :status)'
    );
    $insertDocument->execute([
        'user_id' => (int) ($user['id'] ?? 0),
        'document_type' => 'other',
        'file_name' => $safeBaseName,
        'file_path' => '',
        'file_blob' => $decoded,
        'file_mime_type' => $fileMimeType,
        'status' => 'valid',
    ]);

    $documentId = (int) $pdo->lastInsertId();

    jsonResponse([
        'success' => true,
        'ok' => true,
        'document_id' => $documentId,
        'file_name' => $safeBaseName,
        'file_path' => '',
        'download_url' => appUrl('document-download', ['id' => $documentId]),
    ]);
}

if ($action === 'delete_document') {
    if (!in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $documentId = (int) ($input['document_id'] ?? 0);
    if ($documentId <= 0) {
        jsonResponse(['success' => false, 'error' => 'document_id is required'], 400);
    }

    $lookup = $pdo->prepare(
        'SELECT d.id, d.file_path, u.department_id, dep.company_id
         FROM documents d
         INNER JOIN users u ON u.id = d.user_id
         LEFT JOIN departments dep ON dep.id = u.department_id
         WHERE d.id = :id
         LIMIT 1'
    );
    $lookup->execute(['id' => $documentId]);
    $documentRow = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$documentRow) {
        jsonResponse(['success' => false, 'error' => 'Document not found'], 404);
    }

    if ($role === 'admin' && (int) ($profile['company_id'] ?? 0) !== (int) ($documentRow['company_id'] ?? 0)) {
        jsonResponse(['success' => false, 'error' => 'Document out of scope'], 403);
    }
    if ($role === 'department_manager' && (int) ($profile['department_id'] ?? 0) !== (int) ($documentRow['department_id'] ?? 0)) {
        jsonResponse(['success' => false, 'error' => 'Document out of scope'], 403);
    }

    $filePath = trim((string) ($documentRow['file_path'] ?? ''));
    if ($filePath !== '') {
        $candidates = [
            $filePath,
            __DIR__ . '/../../' . ltrim($filePath, '/'),
            __DIR__ . '/../../public/' . ltrim($filePath, '/'),
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                @unlink($candidate);
                break;
            }
        }
    }

    $delete = $pdo->prepare('DELETE FROM documents WHERE id = :id LIMIT 1');
    $delete->execute(['id' => $documentId]);

    jsonResponse([
        'success' => true,
        'ok' => true,
        'document_id' => $documentId,
    ]);
}

if ($action === 'archive_document' || $action === 'restore_document') {
    if (!in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $documentId = (int) ($input['document_id'] ?? 0);
    if ($documentId <= 0) {
        jsonResponse(['success' => false, 'error' => 'document_id is required'], 400);
    }

    $lookup = $pdo->prepare(
        'SELECT d.id, u.department_id, dep.company_id
         FROM documents d
         INNER JOIN users u ON u.id = d.user_id
         LEFT JOIN departments dep ON dep.id = u.department_id
         WHERE d.id = :id
         LIMIT 1'
    );
    $lookup->execute(['id' => $documentId]);
    $documentRow = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$documentRow) {
        jsonResponse(['success' => false, 'error' => 'Document not found'], 404);
    }

    $enforceDocumentScope($documentRow);

    $nextStatus = $action === 'archive_document' ? 'archived' : 'valid';
    try {
        $updateStatus = $pdo->prepare('UPDATE documents SET status = :status WHERE id = :id LIMIT 1');
        $updateStatus->execute([
            'status' => $nextStatus,
            'id' => $documentId,
        ]);
    } catch (Throwable $e) {
        jsonResponse([
            'success' => false,
            'error' => 'Unable to update document status. Please verify documents.status schema supports archived state.',
        ], 500);
    }

    jsonResponse([
        'success' => true,
        'ok' => true,
        'document_id' => $documentId,
        'status' => $nextStatus,
    ]);
}

if ($action === 'upload_and_share_document') {
    if (!in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $fileName = trim((string) ($input['file_name'] ?? ''));
    $fileContentB64 = trim((string) ($input['file_content_b64'] ?? ''));
    $fileMimeType = trim((string) ($input['file_mime_type'] ?? ''));
    $documentType = trim((string) ($input['document_type'] ?? 'other'));
    $requestType = trim((string) ($input['request_type'] ?? 'notification'));
    $shiftId = (int) ($input['shift_id'] ?? 0);
    $requestTitle = trim((string) ($input['title'] ?? ''));
    $requestMessage = trim((string) ($input['message'] ?? ''));
    $recipientScope = trim((string) ($input['recipient_scope'] ?? 'selected'));
    $recipientIdsRaw = $input['recipient_ids'] ?? [];
    $requireSignature = !empty($input['require_signature']);
    $shareNow = !array_key_exists('share_now', $input) || !empty($input['share_now']);

    $canRequestSignature = in_array($role, ['admin', 'department_manager'], true);
    if (!$canRequestSignature) {
        $requireSignature = false;
    }

    $allowedRequestTypes = ['notification'];
    if (in_array($role, ['admin', 'department_manager'], true)) {
        $allowedRequestTypes[] = 'shift_coverage';
    }
    if (!in_array($requestType, $allowedRequestTypes, true)) {
        $requestType = 'notification';
    }

    if ($requireSignature) {
        $requestType = 'document_signature';
    }

    $requiresDocument = $requestType !== 'shift_coverage';
    if ($requiresDocument && ($fileName === '' || $fileContentB64 === '')) {
        jsonResponse(['success' => false, 'error' => 'file_name and file_content_b64 are required'], 400);
    }

    if (!in_array($documentType, ['contract', 'medical_certificate', 'id_scan', 'other'], true)) {
        $documentType = 'other';
    }

    $decoded = '';
    $safeBaseName = '';
    if ($requiresDocument) {
        $decoded = base64_decode($fileContentB64, true);
        if (!is_string($decoded) || $decoded === '') {
            jsonResponse(['success' => false, 'error' => 'Invalid file payload'], 400);
        }
        if (strlen($decoded) > 8 * 1024 * 1024) {
            jsonResponse(['success' => false, 'error' => 'File payload too large'], 400);
        }

        $safeBaseName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $fileName) ?: ('document-' . date('Ymd-His'));
        if (mb_strlen($safeBaseName) > 180) {
            $safeBaseName = mb_substr($safeBaseName, 0, 180);
        }
    }

    [$allowedRecipientIds, $allowedRecipientSet] = $resolveAllowedRecipients();

    $recipientIds = [];
    if ($recipientScope === 'all') {
        $recipientIds = $allowedRecipientIds;
    } else {
        $recipientIds = array_values(array_filter(array_map('intval', is_array($recipientIdsRaw) ? $recipientIdsRaw : [$recipientIdsRaw])));
        $recipientIds = array_values(array_filter($recipientIds, static fn (int $id): bool => isset($allowedRecipientSet[$id])));
    }

    if ($shareNow && empty($recipientIds)) {
        jsonResponse(['success' => false, 'error' => 'At least one valid recipient is required'], 400);
    }

    if ($requestTitle === '') {
        $requestTitle = match ($requestType) {
            'document_signature' => 'Document to sign',
            'shift_coverage' => 'Shift coverage request',
            default => 'Shared document',
        };
    }
    if ($requestMessage === '') {
        $requestMessage = match ($requestType) {
            'document_signature' => 'Please review and sign the attached document.',
            'shift_coverage' => 'A shift replacement is requested. Please review and confirm availability.',
            default => 'Please review the attached document.',
        };
    }

    if ($requestType === 'shift_coverage' && $shareNow) {
        if ($shiftId <= 0) {
            jsonResponse(['success' => false, 'error' => 'shift_id is required for shift_coverage requests'], 400);
        }

        $scopeShift = $pdo->prepare(
            'SELECT s.id, s.kind, d.company_id
             FROM shifts s
             INNER JOIN departments d ON d.id = s.department_id
             WHERE s.id = :id
             LIMIT 1'
        );
        $scopeShift->execute(['id' => $shiftId]);
        $shiftRow = $scopeShift->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$shiftRow || !in_array((string) ($shiftRow['kind'] ?? ''), ['work', 'overtime'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid shift selected'], 400);
        }

        if ($role !== 'super_admin' && (int) ($profile['company_id'] ?? 0) !== (int) ($shiftRow['company_id'] ?? 0)) {
            jsonResponse(['success' => false, 'error' => 'Shift out of scope'], 403);
        }
    } else {
        $shiftId = 0;
    }

    if ($requiresDocument && $fileMimeType === '') {
        $extension = strtolower(pathinfo($safeBaseName, PATHINFO_EXTENSION));
        $mimeByExtension = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'txt' => 'text/plain; charset=utf-8',
            'csv' => 'text/csv; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'htm' => 'text/html; charset=utf-8',
        ];
        $fileMimeType = $mimeByExtension[$extension] ?? 'application/octet-stream';
    }

    $insertDocument = $pdo->prepare(
        'INSERT INTO documents (user_id, document_type, file_name, file_path, file_blob, file_mime_type, status)
         VALUES (:user_id, :document_type, :file_name, :file_path, :file_blob, :file_mime_type, :status)'
    );
    $insertRequest = $pdo->prepare(
        'INSERT INTO requests (user_id, recipient_id, type, title, message, status, document_id, shift_id)
         VALUES (:user_id, :recipient_id, :type, :title, :message, :status, :document_id, :shift_id)'
    );

    $documentId = 0;
    $pdo->beginTransaction();
    try {
        if ($requiresDocument) {
            $insertDocument->execute([
                'user_id' => (int) ($user['id'] ?? 0),
                'document_type' => $documentType,
                'file_name' => $safeBaseName,
                'file_path' => '',
                'file_blob' => $decoded,
                'file_mime_type' => $fileMimeType,
                'status' => 'valid',
            ]);

            $documentId = (int) $pdo->lastInsertId();
        }

        if ($shareNow) {
            $requestStatus = in_array($requestType, ['document_signature', 'shift_coverage'], true) ? 'pending' : 'unread';
            foreach ($recipientIds as $recipientId) {
                $insertRequest->execute([
                    'user_id' => (int) ($user['id'] ?? 0),
                    'recipient_id' => $recipientId,
                    'type' => $requestType,
                    'title' => $requestTitle,
                    'message' => $requestMessage,
                    'status' => $requestStatus,
                    'document_id' => $documentId > 0 ? $documentId : null,
                    'shift_id' => $shiftId > 0 ? $shiftId : null,
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['success' => false, 'error' => 'Unable to upload document'], 500);
    }

    jsonResponse([
        'success' => true,
        'ok' => true,
        'document_id' => $documentId,
        'file_name' => $safeBaseName,
        'recipient_count' => $shareNow ? count($recipientIds) : 0,
        'shared' => $shareNow,
        'requires_signature' => $requireSignature,
        'download_url' => $documentId > 0 ? appUrl('document-download', ['id' => $documentId]) : null,
    ]);
}

if ($action === 'share_existing_document') {
    if (!in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $documentId = (int) ($input['document_id'] ?? 0);
    $recipientScope = trim((string) ($input['recipient_scope'] ?? 'selected'));
    $recipientIdsRaw = $input['recipient_ids'] ?? [];
    $requireSignature = !empty($input['require_signature']);
    $requestType = trim((string) ($input['request_type'] ?? 'notification'));
    $shiftId = (int) ($input['shift_id'] ?? 0);
    $requestTitle = trim((string) ($input['title'] ?? ''));
    $requestMessage = trim((string) ($input['message'] ?? ''));

    $canRequestSignature = in_array($role, ['admin', 'department_manager'], true);
    if (!$canRequestSignature) {
        $requireSignature = false;
    }

    $allowedRequestTypes = ['notification'];
    if (in_array($role, ['admin', 'department_manager'], true)) {
        $allowedRequestTypes[] = 'shift_coverage';
    }
    if (!in_array($requestType, $allowedRequestTypes, true)) {
        $requestType = 'notification';
    }
    if ($requireSignature) {
        $requestType = 'document_signature';
    }

    if ($documentId <= 0) {
        jsonResponse(['success' => false, 'error' => 'document_id is required'], 400);
    }

    $lookup = $pdo->prepare(
        'SELECT d.id, d.file_name, d.status, u.department_id, dep.company_id
         FROM documents d
         INNER JOIN users u ON u.id = d.user_id
         LEFT JOIN departments dep ON dep.id = u.department_id
         WHERE d.id = :id
         LIMIT 1'
    );
    $lookup->execute(['id' => $documentId]);
    $documentRow = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$documentRow) {
        jsonResponse(['success' => false, 'error' => 'Document not found'], 404);
    }

    $enforceDocumentScope($documentRow);

    if ((string) ($documentRow['status'] ?? '') === 'archived') {
        jsonResponse(['success' => false, 'error' => 'Restore document before sharing'], 400);
    }

    [$allowedRecipientIds, $allowedRecipientSet] = $resolveAllowedRecipients();
    if ($recipientScope === 'all') {
        $recipientIds = $allowedRecipientIds;
    } else {
        $recipientIds = array_values(array_filter(array_map('intval', is_array($recipientIdsRaw) ? $recipientIdsRaw : [$recipientIdsRaw])));
        $recipientIds = array_values(array_filter($recipientIds, static fn (int $id): bool => isset($allowedRecipientSet[$id])));
    }

    if (empty($recipientIds)) {
        jsonResponse(['success' => false, 'error' => 'At least one valid recipient is required'], 400);
    }

    if ($requestType === 'shift_coverage') {
        if ($shiftId <= 0) {
            jsonResponse(['success' => false, 'error' => 'shift_id is required for shift_coverage requests'], 400);
        }
        $scopeShift = $pdo->prepare(
            'SELECT s.id, s.kind, d.company_id
             FROM shifts s
             INNER JOIN departments d ON d.id = s.department_id
             WHERE s.id = :id
             LIMIT 1'
        );
        $scopeShift->execute(['id' => $shiftId]);
        $shiftRow = $scopeShift->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$shiftRow || !in_array((string) ($shiftRow['kind'] ?? ''), ['work', 'overtime'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid shift selected'], 400);
        }
        if ($role !== 'super_admin' && (int) ($profile['company_id'] ?? 0) !== (int) ($shiftRow['company_id'] ?? 0)) {
            jsonResponse(['success' => false, 'error' => 'Shift out of scope'], 403);
        }
    } else {
        $shiftId = 0;
    }

    $requestStatus = in_array($requestType, ['document_signature', 'shift_coverage'], true) ? 'pending' : 'unread';
    if ($requestTitle === '') {
        $requestTitle = match ($requestType) {
            'document_signature' => 'Document to sign',
            'shift_coverage' => 'Shift coverage request',
            default => 'Shared document',
        };
    }
    if ($requestMessage === '') {
        $requestMessage = match ($requestType) {
            'document_signature' => 'Please review and sign the attached document.',
            'shift_coverage' => 'A shift replacement is requested. Please review and confirm availability.',
            default => 'Please review the attached document.',
        };
    }

    $insertRequest = $pdo->prepare(
        'INSERT INTO requests (user_id, recipient_id, type, title, message, status, document_id, shift_id)
         VALUES (:user_id, :recipient_id, :type, :title, :message, :status, :document_id, :shift_id)'
    );

    try {
        foreach ($recipientIds as $recipientId) {
            $insertRequest->execute([
                'user_id' => (int) ($user['id'] ?? 0),
                'recipient_id' => $recipientId,
                'type' => $requestType,
                'title' => $requestTitle,
                'message' => $requestMessage,
                'status' => $requestStatus,
                'document_id' => $requestType === 'shift_coverage' ? null : $documentId,
                'shift_id' => $shiftId > 0 ? $shiftId : null,
            ]);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'error' => 'Unable to share document'], 500);
    }

    jsonResponse([
        'success' => true,
        'ok' => true,
        'document_id' => $documentId,
        'recipient_count' => count($recipientIds),
    ]);
}

if ($action === 'sign_dashboard_document') {
    if (!in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $documentId = (int) ($input['document_id'] ?? 0);
    $signatureData = trim((string) ($input['signature_data'] ?? ''));
    $signaturePosX = 88.0;
    $signaturePosY = 92.0;
    $signaturePage = max(1, (int) ($input['signature_page'] ?? 1));

    if ($documentId <= 0 || $signatureData === '') {
        jsonResponse(['success' => false, 'error' => 'document_id and signature_data are required'], 400);
    }

    $documentLookup = $pdo->prepare(
        'SELECT d.id,
                d.user_id,
                d.document_type,
                d.file_name,
                d.file_path,
                d.file_blob,
                d.file_mime_type,
                u.department_id,
                dep.company_id
         FROM documents d
         INNER JOIN users u ON u.id = d.user_id
         LEFT JOIN departments dep ON dep.id = u.department_id
         WHERE d.id = :id
         LIMIT 1'
    );
    $documentLookup->execute(['id' => $documentId]);
    $document = $documentLookup->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$document) {
        jsonResponse(['success' => false, 'error' => 'Document not found'], 404);
    }

    if ($role === 'admin' && (int) ($profile['company_id'] ?? 0) !== (int) ($document['company_id'] ?? 0)) {
        jsonResponse(['success' => false, 'error' => 'Document out of scope'], 403);
    }
    if ($role === 'department_manager' && (int) ($profile['department_id'] ?? 0) !== (int) ($document['department_id'] ?? 0)) {
        jsonResponse(['success' => false, 'error' => 'Document out of scope'], 403);
    }

    $signerName = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    if ($signerName === '') {
        $signerName = (string) ($user['email'] ?? 'User');
    }
    $signedAt = appNow()->format('Y-m-d H:i:s');

    $sourceMimeType = strtolower(trim((string) ($document['file_mime_type'] ?? '')));
    if ($sourceMimeType === '') {
        $sourceMimeType = mimeTypeFromFileExtension((string) ($document['file_name'] ?? ''));
    }

    $sourceBlob = is_string($document['file_blob'] ?? null) ? (string) $document['file_blob'] : '';
    if ($sourceBlob === '') {
        $storedPath = trim((string) ($document['file_path'] ?? ''));
        if ($storedPath !== '') {
            $candidatePaths = [
                $storedPath,
                __DIR__ . '/../../' . ltrim($storedPath, '/'),
                __DIR__ . '/../../public/' . ltrim($storedPath, '/'),
            ];
            foreach ($candidatePaths as $candidate) {
                if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                    $content = @file_get_contents($candidate);
                    if (is_string($content) && $content !== '') {
                        $sourceBlob = $content;
                        break;
                    }
                }
            }
        }
    }

    if ($sourceBlob === '') {
        jsonResponse(['success' => false, 'error' => 'Source document content not found'], 404);
    }

    try {
        $signResult = documentSigningApply(
            $sourceBlob,
            $sourceMimeType,
            $signatureData,
            $signaturePosX,
            $signaturePosY,
            $signaturePage,
            $signerName,
            $signedAt
        );
    } catch (Throwable $e) {
        jsonResponse([
            'success' => false,
            'error' => 'Unable to apply signature on the original document: ' . $e->getMessage(),
        ], 500);
    }

    $signedBlob = $signResult['blob'];
    $signedMimeType = $signResult['mime_type'];
    $appliedSignaturePage = $signResult['page'];

    $insertSignature = $pdo->prepare(
        'INSERT INTO digital_signatures (user_id, signature_type, signature_data)
         VALUES (:user_id, :signature_type, :signature_data)'
    );
    $insertSignedDocument = $pdo->prepare(
        'INSERT INTO documents (
            user_id,
            document_type,
            file_name,
            file_path,
            file_blob,
            file_mime_type,
            status,
            signed_at,
            signed_by_user_id,
            signed_page
         ) VALUES (
            :user_id,
            :document_type,
            :file_name,
            :file_path,
            :file_blob,
            :file_mime_type,
            :status,
            :signed_at,
            :signed_by_user_id,
            :signed_page
         )'
    );

    $pdo->beginTransaction();
    try {
        $insertSignature->execute([
            'user_id' => (int) ($user['id'] ?? 0),
            'signature_type' => 'touchscreen',
            'signature_data' => $signatureData,
        ]);

        $sourceFileName = trim((string) ($document['file_name'] ?? 'document'));
        $fileNameBase = pathinfo($sourceFileName, PATHINFO_FILENAME);
        if ($fileNameBase === '') {
            $fileNameBase = 'document';
        }
        $fileNameExt = pathinfo($sourceFileName, PATHINFO_EXTENSION);
        $signedFileName = $fileNameBase . '_signed_' . appNow()->format('Ymd_His');
        if ($fileNameExt !== '') {
            $signedFileName .= '.' . $fileNameExt;
        }

        $insertSignedDocument->execute([
            'user_id' => (int) ($document['user_id'] ?? 0),
            'document_type' => (string) ($document['document_type'] ?? 'other'),
            'file_name' => $signedFileName,
            'file_path' => '',
            'file_blob' => $signedBlob,
            'file_mime_type' => $signedMimeType,
            'status' => 'valid',
            'signed_at' => $signedAt,
            'signed_by_user_id' => (int) ($user['id'] ?? 0),
            'signed_page' => $appliedSignaturePage,
        ]);
        $signedDocumentId = (int) $pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['success' => false, 'error' => 'Unable to sign document'], 500);
    }

    jsonResponse([
        'success' => true,
        'ok' => true,
        'signed_document_id' => $signedDocumentId,
        'signed_file_name' => (string) ($signedFileName ?? ($document['file_name'] ?? 'document')),
        'signed_file_mime_type' => $signedMimeType,
        'signature_page' => $appliedSignaturePage,
        'signed_at' => $signedAt,
        'download_url' => appUrl('document-download', ['id' => $signedDocumentId]),
    ]);
}

if ($action === 'save_user_month_hours') {
    if (!in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $targetUserId = (int) ($input['user_id'] ?? 0);
    $monthKeyRaw = trim((string) ($input['month_key'] ?? ''));
    $plannedHours = (float) ($input['planned_hours'] ?? 0);
    $workedHoursOverrideRaw = trim((string) ($input['worked_hours_override'] ?? ''));
    $workedHoursOverride = ($workedHoursOverrideRaw === '' ? null : (float) $workedHoursOverrideRaw);
    $note = trim((string) ($input['note'] ?? ''));

    if ($targetUserId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $monthKeyRaw)) {
        jsonResponse(['success' => false, 'error' => 'user_id and month_key (YYYY-MM) are required'], 400);
    }

    if ($plannedHours < 0) {
        $plannedHours = 0;
    }
    if ($plannedHours > 744) {
        $plannedHours = 744;
    }
    if ($workedHoursOverride !== null) {
        if ($workedHoursOverride < 0) {
            $workedHoursOverride = 0.0;
        }
        if ($workedHoursOverride > 744) {
            $workedHoursOverride = 744.0;
        }
    }

    $monthKeyDate = $monthKeyRaw . '-01';

    $userLookup = $pdo->prepare(
        'SELECT u.id,
                u.department_id,
                d.company_id
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = :user_id
         LIMIT 1'
    );
    $userLookup->execute(['user_id' => $targetUserId]);
    $targetUser = $userLookup->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$targetUser) {
        jsonResponse(['success' => false, 'error' => 'User not found'], 404);
    }

    if ($role === 'admin') {
        $companyId = (int) ($profile['company_id'] ?? 0);
        if ($companyId <= 0 || (int) ($targetUser['company_id'] ?? 0) !== $companyId) {
            jsonResponse(['success' => false, 'error' => 'User is outside your company'], 403);
        }
    }

    if ($role === 'department_manager') {
        $managerDepartmentId = (int) ($profile['department_id'] ?? 0);
        if ($managerDepartmentId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Department scope unavailable'], 403);
        }

        $isPrimaryDepartmentMatch = ((int) ($targetUser['department_id'] ?? 0) === $managerDepartmentId);
        $isLinkedDepartmentMatch = false;
        if (!$isPrimaryDepartmentMatch) {
            $linkLookup = $pdo->prepare(
                'SELECT 1
                 FROM user_department_links
                 WHERE user_id = :user_id
                   AND department_id = :department_id
                 LIMIT 1'
            );
            $linkLookup->execute([
                'user_id' => $targetUserId,
                'department_id' => $managerDepartmentId,
            ]);
            $isLinkedDepartmentMatch = (bool) $linkLookup->fetchColumn();
        }

        if (!$isPrimaryDepartmentMatch && !$isLinkedDepartmentMatch) {
            jsonResponse(['success' => false, 'error' => 'User is outside your department'], 403);
        }
    }

    $upsertPlan = $pdo->prepare(
        'INSERT INTO user_month_hours_plans (user_id, month_key, planned_hours, worked_hours_override, note, updated_by_user_id)
         VALUES (:user_id, :month_key, :planned_hours, :worked_hours_override, :note, :updated_by_user_id)
         ON DUPLICATE KEY UPDATE
           planned_hours = VALUES(planned_hours),
           worked_hours_override = VALUES(worked_hours_override),
           note = VALUES(note),
           updated_by_user_id = VALUES(updated_by_user_id),
           updated_at = CURRENT_TIMESTAMP'
    );
    $upsertPlan->execute([
        'user_id' => $targetUserId,
        'month_key' => $monthKeyDate,
        'planned_hours' => round($plannedHours, 2),
        'worked_hours_override' => $workedHoursOverride === null ? null : round($workedHoursOverride, 2),
        'note' => ($note === '' ? null : mb_substr($note, 0, 255)),
        'updated_by_user_id' => (int) ($user['id'] ?? 0),
    ]);

    $planLookup = $pdo->prepare(
        'SELECT id,
                user_id,
                month_key,
                planned_hours,
                worked_hours_override,
                note,
                updated_by_user_id,
                updated_at
         FROM user_month_hours_plans
         WHERE user_id = :user_id
           AND month_key = :month_key
         LIMIT 1'
    );
    $planLookup->execute([
        'user_id' => $targetUserId,
        'month_key' => $monthKeyDate,
    ]);
    $savedPlan = $planLookup->fetch(PDO::FETCH_ASSOC) ?: null;

    jsonResponse([
        'success' => true,
        'ok' => true,
        'plan' => $savedPlan,
    ]);
}

if (in_array($action, ['assign_shift', 'move_shift', 'unassign_shift', 'employee_assignments', 'record_attendance_signature', 'update_attendance', 'cancel_attendance'], true)) {
    $allowedRoles = in_array($action, ['record_attendance_signature', 'update_attendance', 'cancel_attendance'], true)
        ? ['super_admin', 'admin', 'department_manager']
        : ['super_admin', 'admin', 'department_manager'];
    if (!in_array($role, $allowedRoles, true)) {
        jsonResponse(['success' => false, 'error' => t('common.unauthorized')], 403);
    }

    $assignmentId = (int) ($input['assignment_id'] ?? 0);
    $userId = (int) ($input['user_id'] ?? 0);
    $shiftId = (int) ($input['shift_id'] ?? 0);
    $workDate = trim((string) ($input['work_date'] ?? ''));
    $status = trim((string) ($input['status'] ?? 'assigned'));
    $forceOverride = !empty($input['force_override']);

    $attendanceScopeWhere = '1=1';
    $attendanceScopeParams = [];
    if ($role === 'department_manager') {
        $attendanceScopeWhere = 'd.id = :department_id';
        $attendanceScopeParams['department_id'] = (int) ($profile['department_id'] ?? 0);
    } elseif ($role === 'admin') {
        $attendanceScopeWhere = 'd.company_id = :company_id';
        $attendanceScopeParams['company_id'] = (int) ($profile['company_id'] ?? 0);
    }

    $normalizeTimeOrNull = static function ($rawValue): ?string {
        $value = trim((string) $rawValue);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        return null;
    };

    if ($action === 'update_attendance' || $action === 'cancel_attendance') {
        $attendanceId = (int) ($input['attendance_id'] ?? 0);
        if ($attendanceId <= 0) {
            jsonResponse(['success' => false, 'error' => 'attendance_id is required'], 400);
        }

        $attendanceLookup = $pdo->prepare(
            'SELECT a.id, a.user_id, a.user_shift_id, d.id AS department_id, d.company_id
             FROM attendances a
             LEFT JOIN user_shifts us ON us.id = a.user_shift_id
             LEFT JOIN shifts s ON s.id = us.shift_id
             LEFT JOIN departments d ON d.id = s.department_id
             WHERE a.id = :attendance_id
               AND ' . $attendanceScopeWhere . '
             LIMIT 1'
        );
        $attendanceLookup->execute(array_merge([
            'attendance_id' => $attendanceId,
        ], $attendanceScopeParams));
        $attendanceRow = $attendanceLookup->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$attendanceRow) {
            jsonResponse(['success' => false, 'error' => 'Attendance not found or out of scope'], 404);
        }

        if ($action === 'cancel_attendance') {
            $deleteAttendance = $pdo->prepare('DELETE FROM attendances WHERE id = :attendance_id LIMIT 1');
            $deleteAttendance->execute(['attendance_id' => $attendanceId]);

            jsonResponse([
                'success' => true,
                'ok' => true,
                'attendance_id' => $attendanceId,
            ]);
        }

        $attendanceStatus = trim((string) ($input['attendance_status'] ?? 'present'));
        if (!in_array($attendanceStatus, ['present', 'absent', 'late', 'early_departure'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid attendance status'], 400);
        }

        $checkInTime = $normalizeTimeOrNull($input['check_in_time'] ?? '');
        $checkOutTime = $normalizeTimeOrNull($input['check_out_time'] ?? '');
        $signatureData = trim((string) ($input['signature_data'] ?? ''));

        $signatureClause = '';
        $signatureParams = [];
        $digitalSignatureId = null;
        if ($signatureData !== '') {
            $insertSignature = $pdo->prepare(
                'INSERT INTO digital_signatures (user_id, signature_type, signature_data)
                 VALUES (:user_id, :signature_type, :signature_data)'
            );
            $insertSignature->execute([
                'user_id' => (int) ($attendanceRow['user_id'] ?? 0),
                'signature_type' => 'touchscreen',
                'signature_data' => $signatureData,
            ]);
            $digitalSignatureId = (int) $pdo->lastInsertId();
            $signatureClause = ', digital_signature_id = :digital_signature_id';
            $signatureParams['digital_signature_id'] = $digitalSignatureId;
        }

        $updateAttendance = $pdo->prepare(
            'UPDATE attendances
             SET status = :status,
                 check_in_time = :check_in_time,
                 check_out_time = :check_out_time,
                 ' . ltrim($signatureClause, ', ') . ($signatureClause !== '' ? ',' : '') . '
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :attendance_id'
        );
        $updateParams = [
            'status' => $attendanceStatus,
            'check_in_time' => $checkInTime,
            'check_out_time' => $checkOutTime,
            'attendance_id' => $attendanceId,
        ];
        foreach ($signatureParams as $paramKey => $paramValue) {
            $updateParams[$paramKey] = $paramValue;
        }
        $updateAttendance->execute($updateParams);

        jsonResponse([
            'success' => true,
            'ok' => true,
            'attendance_id' => $attendanceId,
            'status' => $attendanceStatus,
            'check_in_time' => $checkInTime,
            'check_out_time' => $checkOutTime,
            'digital_signature_id' => $digitalSignatureId,
        ]);
    }

    if ($action === 'record_attendance_signature') {
        $currentAppNow = appNow();
        $currentAppTime = $currentAppNow->format('H:i:s');
        $targetUserId = (int) ($input['user_id'] ?? 0);
        $targetUserShiftId = (int) ($input['user_shift_id'] ?? 0);
        $signatureData = trim((string) ($input['signature_data'] ?? ''));
        $checkInOverride = $normalizeTimeOrNull($input['check_in_time'] ?? '');
        $checkOutOverride = $normalizeTimeOrNull($input['check_out_time'] ?? '');
        $attendanceStatus = trim((string) ($input['attendance_status'] ?? 'present'));
        if (!in_array($attendanceStatus, ['present', 'absent', 'late', 'early_departure'], true)) {
            $attendanceStatus = 'present';
        }

        if ($targetUserId <= 0 || $targetUserShiftId <= 0 || $signatureData === '') {
            jsonResponse(['success' => false, 'error' => 'user_id, user_shift_id and signature_data are required'], 400);
        }

        $assignmentLookup = $pdo->prepare(
            'SELECT us.id, us.user_id, us.work_date, us.shift_id, s.start_time, d.id AS department_id
             FROM user_shifts us
             INNER JOIN shifts s ON s.id = us.shift_id
             INNER JOIN departments d ON d.id = s.department_id
             WHERE us.id = :user_shift_id
               AND us.user_id = :user_id
               AND ' . $attendanceScopeWhere . '
             LIMIT 1'
        );
        $assignmentLookup->execute(array_merge([
            'user_shift_id' => $targetUserShiftId,
            'user_id' => $targetUserId,
        ], $attendanceScopeParams));
        $assignment = $assignmentLookup->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$assignment) {
            jsonResponse(['success' => false, 'error' => 'Assignment not found or out of scope'], 404);
        }

        $workDate = (string) ($assignment['work_date'] ?? '');
        if ($workDate === '' || $workDate > date('Y-m-d')) {
            jsonResponse(['success' => false, 'error' => 'Attendance cannot be recorded for future dates'], 400);
        }

        $shiftStartTime = trim((string) ($assignment['start_time'] ?? ''));
        if ($attendanceStatus === 'present' && $workDate === $currentAppNow->format('Y-m-d') && $shiftStartTime !== '') {
            try {
                $shiftStartAt = new DateTimeImmutable($workDate . ' ' . $shiftStartTime, appTimezone());
                if ($currentAppNow > $shiftStartAt) {
                    $attendanceStatus = 'late';
                }
            } catch (Throwable $e) {
                // Keep requested status when shift time cannot be parsed.
            }
        }

        $insertSignature = $pdo->prepare(
            'INSERT INTO digital_signatures (user_id, signature_type, signature_data)
             VALUES (:user_id, :signature_type, :signature_data)'
        );
        $insertSignature->execute([
            'user_id' => $targetUserId,
            'signature_type' => 'touchscreen',
            'signature_data' => $signatureData,
        ]);
        $digitalSignatureId = (int) $pdo->lastInsertId();

        $attendanceLookup = $pdo->prepare(
            'SELECT id
             FROM attendances
             WHERE user_id = :user_id
               AND user_shift_id = :user_shift_id
               AND work_date = :work_date
             LIMIT 1'
        );
        $attendanceLookup->execute([
            'user_id' => $targetUserId,
            'user_shift_id' => $targetUserShiftId,
            'work_date' => $workDate,
        ]);
        $attendanceId = (int) ($attendanceLookup->fetchColumn() ?: 0);

        if ($attendanceId > 0) {
            $updateAttendance = $pdo->prepare(
                'UPDATE attendances
                 SET status = :status,
                     digital_signature_id = :digital_signature_id,
                     check_in_time = COALESCE(:check_in_time, check_in_time),
                     check_out_time = COALESCE(:check_out_time, check_out_time),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $updateAttendance->execute([
                'status' => $attendanceStatus,
                'digital_signature_id' => $digitalSignatureId,
                'check_in_time' => $checkInOverride ?: $currentAppTime,
                'check_out_time' => $checkOutOverride,
                'id' => $attendanceId,
            ]);
        } else {
            $insertAttendance = $pdo->prepare(
                'INSERT INTO attendances (user_id, user_shift_id, digital_signature_id, work_date, check_in_time, check_out_time, status)
                 VALUES (:user_id, :user_shift_id, :digital_signature_id, :work_date, :check_in_time, :check_out_time, :status)'
            );
            $insertAttendance->execute([
                'user_id' => $targetUserId,
                'user_shift_id' => $targetUserShiftId,
                'digital_signature_id' => $digitalSignatureId,
                'work_date' => $workDate,
                'check_in_time' => $checkInOverride ?: $currentAppTime,
                'check_out_time' => $checkOutOverride,
                'status' => $attendanceStatus,
            ]);
            $attendanceId = (int) $pdo->lastInsertId();
        }

        jsonResponse([
            'success' => true,
            'ok' => true,
            'attendance_id' => $attendanceId,
            'digital_signature_id' => $digitalSignatureId,
            'work_date' => $workDate,
            'status' => $attendanceStatus,
            'check_in_time' => $checkInOverride ?: $currentAppTime,
            'check_out_time' => $checkOutOverride,
        ]);
    }

    $validateSingleShiftPerDay = static function (PDO $pdo, int $targetUserId, string $targetDate, int $excludeAssignmentId = 0): ?string {
        if ($targetUserId <= 0 || $targetDate === '') {
            return null;
        }
        $check = $pdo->prepare(
            'SELECT id FROM user_shifts
             WHERE user_id = :user_id
               AND work_date = :work_date
               AND id <> :exclude_id
               AND status <> "cancelled"
             LIMIT 1'
        );
        $check->execute([
            'user_id' => $targetUserId,
            'work_date' => $targetDate,
            'exclude_id' => $excludeAssignmentId,
        ]);

        return $check->fetchColumn() ? 'Employee already has an assigned shift on this date.' : null;
    };

    $isPastWorkDate = static function (string $date): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        return $date < date('Y-m-d');
    };

    $loadUserDepartmentIdsMap = static function (PDO $pdo, array $userIds): array {
        $map = [];
        $normalizedUserIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if (empty($normalizedUserIds)) {
            return $map;
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedUserIds), '?'));

        $primaryStmt = $pdo->prepare(
            'SELECT id AS user_id, department_id
             FROM users
             WHERE id IN (' . $placeholders . ')'
        );
        $primaryStmt->execute($normalizedUserIds);
        foreach ($primaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $did = (int) ($row['department_id'] ?? 0);
            if ($uid <= 0 || $did <= 0) {
                continue;
            }
            if (!isset($map[$uid])) {
                $map[$uid] = [];
            }
            $map[$uid][$did] = $did;
        }

        try {
            $linkStmt = $pdo->prepare(
                'SELECT user_id, department_id
                 FROM user_department_links
                 WHERE user_id IN (' . $placeholders . ')'
            );
            $linkStmt->execute($normalizedUserIds);
            foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $uid = (int) ($row['user_id'] ?? 0);
                $did = (int) ($row['department_id'] ?? 0);
                if ($uid <= 0 || $did <= 0) {
                    continue;
                }
                if (!isset($map[$uid])) {
                    $map[$uid] = [];
                }
                $map[$uid][$did] = $did;
            }
        } catch (Throwable $e) {
            // Legacy schemas may not have link table.
        }

        foreach ($map as $uid => $departmentIds) {
            $map[$uid] = array_values(array_map('intval', array_keys($departmentIds)));
        }

        return $map;
    };

    if ($action === 'employee_assignments') {
        $targetUserId = max(0, (int) ($input['target_user_id'] ?? 0));
        $targetMonth = trim((string) ($input['target_month'] ?? date('Y-m')));
        if ($targetUserId <= 0) {
            jsonResponse(['success' => false, 'ok' => false, 'error' => 'target_user_id is required'], 400);
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $targetMonth)) {
            $targetMonth = date('Y-m');
        }

        $monthStart = $targetMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $scopeWhere = '1=1';
        $scopeParams = [
            'target_user_id' => $targetUserId,
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
        ];

        if ($role === 'department_manager') {
            $scopeWhere = 'd.id = :department_id';
            $scopeParams['department_id'] = (int) ($profile['department_id'] ?? 0);
        } elseif ($role === 'admin') {
            $scopeWhere = 'd.company_id = :company_id';
            $scopeParams['company_id'] = (int) ($profile['company_id'] ?? 0);
        }

        $assignmentsStmt = $pdo->prepare(
            'SELECT us.id AS assignment_id,
                    us.work_date,
                    us.status,
                    us.shift_id,
                    s.name AS shift_name,
                    s.icon AS shift_icon,
                    s.kind AS shift_kind,
                    s.start_time,
                    s.end_time,
                    d.id AS department_id,
                    d.name AS department_name
             FROM user_shifts us
             INNER JOIN shifts s ON s.id = us.shift_id
             INNER JOIN departments d ON d.id = s.department_id
             WHERE us.user_id = :target_user_id
               AND us.status <> "cancelled"
               AND us.work_date BETWEEN :month_start AND :month_end
               AND ' . $scopeWhere . '
             ORDER BY us.work_date ASC, s.start_time ASC, us.id ASC'
        );
        $assignmentsStmt->execute($scopeParams);
        $assignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'success' => true,
            'ok' => true,
            'target_user_id' => $targetUserId,
            'target_month' => $targetMonth,
            'assignments' => $assignments,
        ]);
    }


    $assignmentUserId = $userId;
    if ($action === 'move_shift' && $assignmentId > 0) {
        $assignmentLookup = $pdo->prepare('SELECT user_id, shift_id FROM user_shifts WHERE id = :id LIMIT 1');
        $assignmentLookup->execute(['id' => $assignmentId]);
        $assignmentRow = $assignmentLookup->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($assignmentUserId <= 0 && array_key_exists('user_id', $assignmentRow)) {
            $assignmentUserId = (int) ($assignmentRow['user_id'] ?? 0);
        }
        if ($shiftId <= 0) {
            $shiftId = (int) ($assignmentRow['shift_id'] ?? 0);
        }
    }

    if ($action === 'unassign_shift') {
        if ($assignmentId <= 0) {
            jsonResponse(['success' => false, 'error' => 'assignment_id is required'], 400);
        }

        // Read the employee before the update clears user_id, so the removal can be emailed.
        $unassignLookup = $pdo->prepare(
            'SELECT us.work_date, us.user_id, s.name AS shift_name, s.start_time, s.end_time, d.name AS department_name
             FROM user_shifts us
             INNER JOIN shifts s ON s.id = us.shift_id
             INNER JOIN departments d ON d.id = s.department_id
             WHERE us.id = :id LIMIT 1'
        );
        $unassignLookup->execute(['id' => $assignmentId]);
        $unassignRow = $unassignLookup->fetch(PDO::FETCH_ASSOC) ?: [];
        $unassignDate = (string) ($unassignRow['work_date'] ?? '');
        if ($isPastWorkDate($unassignDate)) {
            jsonResponse(['success' => false, 'error' => 'Past dates are read-only and cannot be modified.'], 400);
        }

        $update = $pdo->prepare('UPDATE user_shifts SET user_id = NULL, status = "open", updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute(['id' => $assignmentId]);

        notifyShiftChange($pdo, (int) ($unassignRow['user_id'] ?? 0), $unassignRow, 'removed');

        jsonResponse([
            'success' => true,
            'ok' => true,
            'assignment' => [
                'assignment_id' => $assignmentId,
                'status' => 'open',
                'user_id' => 0,
                'user_name' => '',
            ],
        ]);
    }

    if ($action !== 'unassign_shift' && ($workDate === '' || $shiftId <= 0 || ($action === 'assign_shift' && $userId <= 0))) {
        jsonResponse(['success' => false, 'error' => 'Missing required fields'], 400);
    }
    if ($action !== 'unassign_shift' && $isPastWorkDate($workDate)) {
        jsonResponse(['success' => false, 'error' => 'Past dates are read-only and cannot be modified.'], 400);
    }

    $shiftSelect = 's.id, s.department_id, s.name, s.icon, s.color, s.kind, s.start_time, s.end_time, d.company_id, d.name AS department_name, d.color AS department_color';

    $shift = null;
    if ($shiftId > 0) {
        $shiftCheck = $pdo->prepare(
            'SELECT ' . $shiftSelect . ' FROM shifts s INNER JOIN departments d ON d.id = s.department_id WHERE s.id = :shift_id LIMIT 1'
        );
        $shiftCheck->execute(['shift_id' => $shiftId]);
        $shift = $shiftCheck->fetch(PDO::FETCH_ASSOC);
        if (!$shift) {
            jsonResponse(['success' => false, 'error' => 'Shift not found'], 404);
        }
    }

    $userCheck = $pdo->prepare(
        'SELECT u.id, u.department_id, d.company_id
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = :id
         LIMIT 1'
    );
    if ($assignmentUserId > 0) {
        $userCheck->execute(['id' => $assignmentUserId]);
        $targetUser = $userCheck->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            jsonResponse(['success' => false, 'error' => 'User not found'], 404);
        }
        $targetUserDepartmentIdsMap = $loadUserDepartmentIdsMap($pdo, [$assignmentUserId]);
        $targetUserDepartmentIds = $targetUserDepartmentIdsMap[$assignmentUserId] ?? [];

        if ($role === 'department_manager' && !in_array((int) ($profile['department_id'] ?? 0), $targetUserDepartmentIds, true)) {
            jsonResponse(['success' => false, 'error' => 'Target user is outside your department'], 403);
        }
        if ($role === 'admin' && (int) $targetUser['company_id'] !== (int) ($profile['company_id'] ?? 0)) {
            jsonResponse(['success' => false, 'error' => 'Target user is outside your company'], 403);
        }

        if ($shift && !in_array((int) ($shift['department_id'] ?? 0), $targetUserDepartmentIds, true)) {
            jsonResponse(['success' => false, 'error' => 'Employee and shift must belong to the same department'], 400);
        }
    }

    if ($shift && $role === 'department_manager' && (int) $shift['department_id'] !== (int) ($profile['department_id'] ?? 0)) {
        jsonResponse(['success' => false, 'error' => 'Shift is outside your department'], 403);
    }
    if ($shift && $role === 'admin' && (int) $shift['company_id'] !== (int) ($profile['company_id'] ?? 0)) {
        jsonResponse(['success' => false, 'error' => 'Shift is outside your company'], 403);
    }

    if ($action === 'assign_shift') {
        $conflict = $validateSingleShiftPerDay($pdo, $assignmentUserId, $workDate);
        if ($conflict !== null && !$forceOverride) {
            jsonResponse(['success' => false, 'error' => $conflict], 400);
        }

        if ($conflict !== null && $forceOverride) {
            $existingByDay = $pdo->prepare(
                'SELECT id
                 FROM user_shifts
                 WHERE user_id = :user_id
                   AND work_date = :work_date
                   AND status <> "cancelled"
                 ORDER BY id ASC
                 LIMIT 1'
            );
            $existingByDay->execute([
                'user_id' => $assignmentUserId,
                'work_date' => $workDate,
            ]);
            $existingByDayId = (int) ($existingByDay->fetchColumn() ?: 0);
            if ($existingByDayId > 0) {
                $forceUpdate = $pdo->prepare(
                    'UPDATE user_shifts
                     SET shift_id = :shift_id,
                         user_id = :user_id,
                         status = :status,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $forceUpdate->execute([
                    'shift_id' => $shiftId,
                    'user_id' => $assignmentUserId,
                    'status' => $status ?: 'assigned',
                    'id' => $existingByDayId,
                ]);
                $assignmentId = $existingByDayId;
            }
        }

        if (!isset($assignmentId) || (int) $assignmentId <= 0) {

        $openExisting = $pdo->prepare(
            'SELECT id FROM user_shifts WHERE shift_id = :shift_id AND work_date = :work_date AND user_id IS NULL LIMIT 1'
        );
        $openExisting->execute([
            'shift_id' => $shiftId,
            'work_date' => $workDate,
        ]);
        $existingOpenId = (int) ($openExisting->fetchColumn() ?: 0);
        $existing = $pdo->prepare(
            'SELECT id FROM user_shifts WHERE user_id = :user_id AND shift_id = :shift_id AND work_date = :work_date LIMIT 1'
        );
        $existing->execute([
            'user_id' => $assignmentUserId,
            'shift_id' => $shiftId,
            'work_date' => $workDate,
        ]);
        $existingId = (int) ($existing->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $update = $pdo->prepare('UPDATE user_shifts SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $update->execute(['status' => $status, 'id' => $existingId]);
            $assignmentId = $existingId;
        } elseif ($existingOpenId > 0) {
            $update = $pdo->prepare('UPDATE user_shifts SET user_id = :user_id, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $update->execute([
                'user_id' => $assignmentUserId,
                'status' => $status ?: 'assigned',
                'id' => $existingOpenId,
            ]);
            $assignmentId = $existingOpenId;
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO user_shifts (shift_id, user_id, work_date, status)
                 VALUES (:shift_id, :user_id, :work_date, :status)'
            );
            $insert->execute([
                'shift_id' => $shiftId,
                'user_id' => $assignmentUserId,
                'work_date' => $workDate,
                'status' => $status,
            ]);
            $assignmentId = (int) $pdo->lastInsertId();
        }
        }
    } else {
        if ($assignmentId <= 0) {
            jsonResponse(['success' => false, 'error' => 'assignment_id is required'], 400);
        }

        if ($assignmentUserId > 0) {
            $conflict = $validateSingleShiftPerDay($pdo, $assignmentUserId, $workDate, $assignmentId);
            if ($conflict !== null) {
                jsonResponse(['success' => false, 'error' => $conflict], 400);
            }
        }

        $update = $pdo->prepare('UPDATE user_shifts SET shift_id = :shift_id, user_id = :user_id, work_date = :work_date, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute([
            'shift_id' => $shiftId,
            'user_id' => $assignmentUserId > 0 ? $assignmentUserId : null,
            'work_date' => $workDate,
            'status' => ($assignmentUserId > 0 ? ($status ?: 'assigned') : 'open'),
            'id' => $assignmentId,
        ]);
    }

    $assignmentSelect = [
        'us.id AS assignment_id',
        'us.work_date',
        'us.status',
        'us.notes',
        's.id AS shift_id',
        's.name AS shift_name',
        's.icon AS shift_icon',
        's.color AS shift_color',
        's.description AS shift_description',
        's.kind AS shift_kind',
        's.start_time',
        's.end_time',
        'd.id AS department_id',
        'd.name AS department_name',
        'd.color AS department_color',
        'u.id AS user_id',
        'CONCAT(u.first_name, " ", u.last_name) AS user_name',
        'CASE WHEN us.user_id IS NULL THEN "open" ELSE "assigned" END AS assignment_source',
    ];

    $assignmentLookup = $pdo->prepare(
        'SELECT ' . implode(', ', $assignmentSelect) . ' FROM user_shifts us INNER JOIN shifts s ON s.id = us.shift_id INNER JOIN departments d ON d.id = s.department_id LEFT JOIN users u ON u.id = us.user_id WHERE us.id = :id LIMIT 1'
    );
    $assignmentLookup->execute(['id' => $assignmentId]);
    $assignment = $assignmentLookup->fetch(PDO::FETCH_ASSOC);

    if (is_array($assignment)) {
        notifyShiftChange(
            $pdo,
            (int) ($assignment['user_id'] ?? 0),
            $assignment,
            $action === 'move_shift' ? 'moved' : 'assigned'
        );
    }

    jsonResponse(['success' => true, 'ok' => true, 'assignment' => $assignment]);
}

$payload = [
    'success' => true,
    'user' => [
        'id' => (int) $user['id'],
        'first_name' => $user['first_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'email' => $user['email'] ?? '',
        'role' => $role,
    ],
    'profile' => $profile,
    'dashboard_route' => 'dashboard',
];

if ($role === 'super_admin') {
    $payload['stats'] = [
        'users' => $userModel->count(),
        'companies' => $companyModel->count(),
        'departments' => $departmentModel->count(),
    ];
}

if ($role === 'admin' && !empty($profile['company_id'])) {
    $payload['stats'] = [
        'users' => $userModel->countByCompanyId((int) $profile['company_id']),
        'departments' => $departmentModel->countByCompanyId((int) $profile['company_id']),
    ];
}

if ($role === 'employee') {
    $payload['items'] = [
        'shifts' => $userModel->employeeShifts((int) $user['id']),
        'requests' => $userModel->employeeRequests((int) $user['id']),
    ];
}

jsonResponse($payload);