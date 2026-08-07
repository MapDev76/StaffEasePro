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

if ($action === 'change_password') {
    $currentUserId = (int) ($user['id'] ?? 0);
    $currentPassword = (string) ($input['current_password'] ?? '');
    $newPassword = (string) ($input['new_password'] ?? '');
    $newPasswordConfirm = (string) ($input['new_password_confirm'] ?? '');

    $currentUserRow = $userModel->findById($currentUserId);
    if (!$currentUserRow || !password_verify($currentPassword, $currentUserRow['password'])) {
        jsonResponse(['success' => false, 'error' => t('auth.current_password_incorrect')], 400);
    }

    $passwordStrengthError = validatePasswordStrength($newPassword);
    if ($passwordStrengthError !== null) {
        jsonResponse(['success' => false, 'error' => $passwordStrengthError], 400);
    }

    if ($newPassword !== $newPasswordConfirm) {
        jsonResponse(['success' => false, 'error' => t('auth.password_mismatch')], 400);
    }

    $updateStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
    $updateStmt->execute([
        'password' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $currentUserId,
    ]);

    jsonResponse(['success' => true]);
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

if (in_array($action, ['assign_shift', 'move_shift', 'unassign_shift', 'auto_assign_open', 'clear_assignments_scope', 'auto_assign_forecast', 'employee_assignments', 'record_attendance_signature', 'update_attendance', 'cancel_attendance'], true)) {
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

    if ($action === 'auto_assign_open' || $action === 'clear_assignments_scope' || $action === 'auto_assign_forecast') {
        $scopeShiftId = max(0, (int) ($input['scope_shift_id'] ?? 0));
        $targetUserId = max(0, (int) ($input['target_user_id'] ?? 0));
        $rangeMode = strtolower(trim((string) ($input['range_mode'] ?? 'custom')));
        if (!in_array($rangeMode, ['custom', 'current', 'future', 'month'], true)) {
            $rangeMode = 'custom';
        }
        $targetMonth = trim((string) ($input['target_month'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $targetMonth)) {
            $targetMonth = date('Y-m');
        }
        $rangeStart = trim((string) ($input['range_start'] ?? date('Y-m-01')));
        $rangeEnd = trim((string) ($input['range_end'] ?? date('Y-m-t')));
        $currentMonthStart = date('Y-m-01');

        if ($rangeMode === 'current') {
            $rangeStart = date('Y-m-01');
            $rangeEnd = date('Y-m-t');
        } elseif ($rangeMode === 'future') {
            $rangeStart = $currentMonthStart;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEnd)) {
                $rangeEnd = date('Y-m-t', strtotime('+12 months'));
            }
        } elseif ($rangeMode === 'month') {
            $rangeStart = $targetMonth . '-01';
            $rangeEnd = date('Y-m-t', strtotime($rangeStart));
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeStart)) {
            $rangeStart = $currentMonthStart;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEnd)) {
            $rangeEnd = $rangeStart;
        }

        $rangeStart = max($rangeStart, $currentMonthStart);
        $rangeEnd = max($rangeStart, $rangeEnd);

        $allowedShiftIdsRaw = $input['allowed_shift_ids'] ?? [];
        if (is_string($allowedShiftIdsRaw)) {
            $decodedAllowed = json_decode($allowedShiftIdsRaw, true);
            $allowedShiftIdsRaw = is_array($decodedAllowed) ? $decodedAllowed : [];
        }
        $allowedShiftIds = [];
        if ($scopeShiftId <= 0 && is_array($allowedShiftIdsRaw)) {
            foreach ($allowedShiftIdsRaw as $allowedShiftIdRaw) {
                $allowedShiftId = (int) $allowedShiftIdRaw;
                if ($allowedShiftId > 0) {
                    $allowedShiftIds[$allowedShiftId] = $allowedShiftId;
                }
            }
            $allowedShiftIds = array_values($allowedShiftIds);
        }

        if ($scopeShiftId > 0) {
            $scopeShiftKindStmt = $pdo->prepare('SELECT kind FROM shifts WHERE id = :id LIMIT 1');
            $scopeShiftKindStmt->execute(['id' => $scopeShiftId]);
            $scopeShiftKind = strtolower(trim((string) ($scopeShiftKindStmt->fetchColumn() ?: '')));
            if ($scopeShiftKind === '' || $scopeShiftKind !== 'work') {
                jsonResponse(['success' => false, 'ok' => false, 'error' => 'Only work shifts can be auto-assigned.'], 400);
            }
        }

        if (!empty($allowedShiftIds)) {
            $allowedShiftPlaceholders = [];
            $allowedShiftParams = [];
            foreach ($allowedShiftIds as $idx => $allowedShiftId) {
                $placeholder = ':allowed_shift_' . $idx;
                $allowedShiftPlaceholders[] = $placeholder;
                $allowedShiftParams[$placeholder] = (int) $allowedShiftId;
            }
        } else {
            $allowedShiftPlaceholders = [];
            $allowedShiftParams = [];
        }

        if ($action === 'clear_assignments_scope') {
            $includeRestAssignments = !empty($input['include_rest_assignments']);
            $scopeWhere = '1=1';
            $scopeParams = [
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
            ];
            if ($role === 'department_manager') {
                $scopeWhere = 'd.id = :department_id';
                $scopeParams['department_id'] = (int) ($profile['department_id'] ?? 0);
            } elseif ($role === 'admin') {
                $scopeWhere = 'd.company_id = :company_id';
                $scopeParams['company_id'] = (int) ($profile['company_id'] ?? 0);
            }

            $clearShiftFilter = $scopeShiftId > 0 ? ' AND s.id = :scope_shift_id' : '';
            if ($scopeShiftId > 0) {
                $scopeParams['scope_shift_id'] = $scopeShiftId;
            } elseif (!empty($allowedShiftPlaceholders)) {
                $clearShiftFilter .= ' AND s.id IN (' . implode(', ', $allowedShiftPlaceholders) . ')';
                foreach ($allowedShiftParams as $placeholder => $value) {
                    $scopeParams[ltrim($placeholder, ':')] = $value;
                }
            }

            $clearUserFilter = '';
            if ($targetUserId > 0) {
                $clearUserFilter .= ' AND us.user_id = :target_user_id';
                $scopeParams['target_user_id'] = $targetUserId;
            }

            $clearKindFilter = ' AND s.kind = "work"' . $clearShiftFilter;
            if ($includeRestAssignments && $scopeShiftId <= 0) {
                // When requested from global clear action, include all rest template assignments too.
                $clearKindFilter = ' AND ((s.kind = "work"' . $clearShiftFilter . ') OR s.kind = "rest")';
            }

            $clearStmt = $pdo->prepare(
                'UPDATE user_shifts us
                 INNER JOIN shifts s ON s.id = us.shift_id
                 INNER JOIN departments d ON d.id = s.department_id
                 SET us.user_id = NULL,
                     us.status = "open",
                     us.updated_at = CURRENT_TIMESTAMP
                 WHERE ' . $scopeWhere . '
                   AND us.work_date BETWEEN :range_start AND :range_end
                   AND us.user_id IS NOT NULL
                   AND us.status <> "cancelled"
                                     ' . $clearUserFilter . '
                                     ' . $clearKindFilter
            );
            $clearStmt->execute($scopeParams);

            jsonResponse([
                'success' => true,
                'ok' => true,
                'cleared_count' => (int) $clearStmt->rowCount(),
            ]);
        }

        $minEmployeesPerShiftDay = max(0, (int) ($input['min_employees_per_shift_day'] ?? 1));
        $maxEmployeesPerShiftDay = max(1, (int) ($input['max_employees_per_shift_day'] ?? 3));
        if ($minEmployeesPerShiftDay > $maxEmployeesPerShiftDay) {
            $minEmployeesPerShiftDay = $maxEmployeesPerShiftDay;
        }
        $legacyRestDaysPerWeek = max(0, min(6, (int) ($input['rest_days_per_week'] ?? 1)));
        $legacyMaxWorkDaysPerWeek = max(1, min(7, (int) ($input['max_work_days_per_week'] ?? 6)));
        $minRestDaysPerWeek = max(0, min(6, (int) ($input['min_rest_days_per_week'] ?? $legacyRestDaysPerWeek)));
        $maxRestDaysPerWeek = max(0, min(6, (int) ($input['max_rest_days_per_week'] ?? $minRestDaysPerWeek)));
        if ($minRestDaysPerWeek > $maxRestDaysPerWeek) {
            [$minRestDaysPerWeek, $maxRestDaysPerWeek] = [$maxRestDaysPerWeek, $minRestDaysPerWeek];
        }
        $minWorkDaysPerWeek = max(1, min(7, (int) ($input['min_work_days_per_week'] ?? 1)));
        $maxWorkDaysPerWeek = max(1, min(7, (int) ($input['max_work_days_per_week'] ?? $legacyMaxWorkDaysPerWeek)));
        if ($minWorkDaysPerWeek > $maxWorkDaysPerWeek) {
            [$minWorkDaysPerWeek, $maxWorkDaysPerWeek] = [$maxWorkDaysPerWeek, $minWorkDaysPerWeek];
        }
        $effectiveMaxWorkDaysPerWeek = min($maxWorkDaysPerWeek, max(1, 7 - $minRestDaysPerWeek));
        $effectiveMinWorkDaysPerWeek = min($minWorkDaysPerWeek, $effectiveMaxWorkDaysPerWeek);
        $effectiveMaxRestDaysPerWeek = min($maxRestDaysPerWeek, max(0, 7 - $effectiveMinWorkDaysPerWeek));
        $restDaysPerWeek = min($minRestDaysPerWeek, $effectiveMaxRestDaysPerWeek);
        $allowReassignConflicts = !array_key_exists('allow_reassign_conflicts', $input)
            || !in_array(strtolower(trim((string) $input['allow_reassign_conflicts'])), ['0', 'false', 'no', 'off'], true);
        $allowCrossDepartmentFallback = false;
        if (array_key_exists('allow_cross_department_fallback', $input)) {
            $allowCrossDepartmentFallback = in_array(
                strtolower(trim((string) $input['allow_cross_department_fallback'])),
                ['1', 'true', 'yes', 'on'],
                true
            );
        }
        $priorityDepartmentId = max(0, (int) ($input['priority_department_id'] ?? 0));
        $priorityDepartmentStrictInternal = !array_key_exists('priority_department_strict_internal', $input)
            || !in_array(strtolower(trim((string) $input['priority_department_strict_internal'])), ['0', 'false', 'no', 'off'], true);
        $assignmentMode = strtolower(trim((string) ($input['assignment_mode'] ?? 'multiple')));
        if (!in_array($assignmentMode, ['single', 'multiple'], true)) {
            $assignmentMode = 'multiple';
        }
        $restDistributionMode = strtolower(trim((string) ($input['rest_distribution_mode'] ?? 'fixed')));
        if (!in_array($restDistributionMode, ['fixed', 'staggered', 'random'], true)) {
            $restDistributionMode = 'fixed';
        }
        $employeeRulesRaw = $input['employee_rules'] ?? [];

        if (is_string($employeeRulesRaw)) {
            $decodedRules = json_decode($employeeRulesRaw, true);
            $employeeRulesRaw = is_array($decodedRules) ? $decodedRules : [];
        }

        $employeeRules = [];
        if (is_array($employeeRulesRaw)) {
            foreach ($employeeRulesRaw as $rawUserId => $rawRule) {
                $normalizedUserId = (int) $rawUserId;
                if ($normalizedUserId <= 0 || !is_array($rawRule)) {
                    continue;
                }

                $scope = (string) ($rawRule['scope'] ?? 'all');
                if (!in_array($scope, ['all', 'current', 'next'], true)) {
                    $scope = 'all';
                }

                $offWeekdays = [];
                if (is_array($rawRule['off_weekdays'] ?? null)) {
                    foreach ($rawRule['off_weekdays'] as $weekday) {
                        $weekdayInt = (int) $weekday;
                        if ($weekdayInt >= 0 && $weekdayInt <= 6) {
                            $offWeekdays[$weekdayInt] = true;
                        }
                    }
                }

                $specialDates = [];
                if (is_array($rawRule['special_dates'] ?? null)) {
                    foreach ($rawRule['special_dates'] as $specialDate) {
                        if (!is_array($specialDate)) {
                            continue;
                        }
                        $dateValue = trim((string) ($specialDate['date'] ?? ''));
                        $reasonValue = strtolower(trim((string) ($specialDate['reason'] ?? 'special')));
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
                            continue;
                        }
                        $specialDates[$dateValue] = $reasonValue !== '' ? $reasonValue : 'special';
                    }
                }

                $monthlyOverrides = [];
                if (is_array($rawRule['monthly_overrides'] ?? null)) {
                    foreach ($rawRule['monthly_overrides'] as $overrideMonth => $overrideDays) {
                        $overrideMonth = (string) $overrideMonth;
                        if (!preg_match('/^\d{4}-\d{2}$/', $overrideMonth) || !is_array($overrideDays)) {
                            continue;
                        }
                        $validDays = [];
                        foreach ($overrideDays as $wd) {
                            $wdInt = (int) $wd;
                            if ($wdInt >= 0 && $wdInt <= 6) {
                                $validDays[$wdInt] = true;
                            }
                        }
                        $monthlyOverrides[$overrideMonth] = $validDays;
                    }
                }

                $rotating = ['enabled' => false, 'start_month' => date('Y-m')];
                if (is_array($rawRule['rotating'] ?? null)) {
                    $rotating['enabled'] = !empty($rawRule['rotating']['enabled']);
                    $rotStart = trim((string) ($rawRule['rotating']['start_month'] ?? ''));
                    if (preg_match('/^\d{4}-\d{2}$/', $rotStart)) {
                        $rotating['start_month'] = $rotStart;
                    }
                }

                $employeeRules[$normalizedUserId] = [
                    'scope' => $scope,
                    'off_weekdays' => $offWeekdays,
                    'special_dates' => $specialDates,
                    'monthly_overrides' => $monthlyOverrides,
                    'rotating' => $rotating,
                ];
            }
        }

        $currentMonth = date('Y-m');
        $nextMonth = date('Y-m', strtotime('first day of next month'));
        $implicitFixedOffWeekdaysByUser = [];

        $hasExplicitRestPlan = static function (int $userId) use ($employeeRules): bool {
            if ($userId <= 0 || empty($employeeRules[$userId]) || !is_array($employeeRules[$userId])) {
                return false;
            }
            $rule = $employeeRules[$userId];
            if (!empty($rule['off_weekdays']) || !empty($rule['monthly_overrides'])) {
                return true;
            }
            if (!empty($rule['rotating']['enabled'])) {
                return true;
            }
            if (!empty($rule['special_dates']) && is_array($rule['special_dates'])) {
                return count($rule['special_dates']) > 0;
            }
            return false;
        };

        $isImplicitFixedOffDay = static function (int $userId, string $slotDate) use (&$implicitFixedOffWeekdaysByUser): bool {
            if ($userId <= 0 || $slotDate === '' || empty($implicitFixedOffWeekdaysByUser[$userId])) {
                return false;
            }
            $weekday = (int) date('w', strtotime($slotDate));
            return !empty($implicitFixedOffWeekdaysByUser[$userId][$weekday]);
        };

        $isBlockedByRule = static function (int $userId, string $slotDate) use ($employeeRules, $currentMonth, $nextMonth, $isImplicitFixedOffDay): bool {
            if ($userId <= 0 || $slotDate === '' || empty($employeeRules[$userId])) {
                return $isImplicitFixedOffDay($userId, $slotDate);
            }

            $rule = $employeeRules[$userId];
            $slotMonth = substr($slotDate, 0, 7);
            $scope = (string) ($rule['scope'] ?? 'all');
            if ($scope === 'current' && $slotMonth !== $currentMonth) {
                return false;
            }
            if ($scope === 'next' && $slotMonth < $nextMonth) {
                return false;
            }

            if ($isImplicitFixedOffDay($userId, $slotDate)) {
                return true;
            }

            if (!empty($rule['special_dates'][$slotDate])) {
                return true;
            }

            // Determine effective weekdays: per-month override > rotating > default
            $effectiveWeekdays = $rule['off_weekdays'] ?? [];
            if (!empty($rule['monthly_overrides'][$slotMonth])) {
                $effectiveWeekdays = $rule['monthly_overrides'][$slotMonth];
            } elseif (!empty($rule['rotating']['enabled']) && !empty($effectiveWeekdays)) {
                $startMonth = (string) ($rule['rotating']['start_month'] ?? date('Y-m'));
                [$sy, $sm] = array_pad(explode('-', $startMonth), 2, '01');
                [$ty, $tm] = array_pad(explode('-', $slotMonth), 2, '01');
                $shift = ((int) $ty - (int) $sy) * 12 + ((int) $tm - (int) $sm);
                if ($shift > 0) {
                    $rotated = [];
                    foreach (array_keys($effectiveWeekdays) as $w) {
                        $rotated[(($w + $shift) % 7)] = true;
                    }
                    $effectiveWeekdays = $rotated;
                }
            }

            $weekday = (int) date('w', strtotime($slotDate));
            if (!empty($effectiveWeekdays[$weekday])) {
                return true;
            }

            return false;
        };

        $getRuleReasonByDate = static function (int $userId, string $slotDate) use ($employeeRules, $currentMonth, $nextMonth, $isImplicitFixedOffDay): ?string {
            if ($userId <= 0 || $slotDate === '' || empty($employeeRules[$userId])) {
                return $isImplicitFixedOffDay($userId, $slotDate) ? 'rest' : null;
            }

            $rule = $employeeRules[$userId];
            $slotMonth = substr($slotDate, 0, 7);
            $scope = (string) ($rule['scope'] ?? 'all');
            if ($scope === 'current' && $slotMonth !== $currentMonth) {
                return null;
            }
            if ($scope === 'next' && $slotMonth < $nextMonth) {
                return null;
            }

            if ($isImplicitFixedOffDay($userId, $slotDate)) {
                return 'rest';
            }

            if (!empty($rule['special_dates'][$slotDate])) {
                $reason = strtolower(trim((string) $rule['special_dates'][$slotDate]));
                if ($reason === 'vacation') {
                    return 'vacation';
                }
                if ($reason === 'sick') {
                    return 'sick';
                }
                if ($reason === 'leave') {
                    return 'vacation';
                }

                return 'rest';
            }

            $effectiveWeekdays = $rule['off_weekdays'] ?? [];
            if (!empty($rule['monthly_overrides'][$slotMonth])) {
                $effectiveWeekdays = $rule['monthly_overrides'][$slotMonth];
            } elseif (!empty($rule['rotating']['enabled']) && !empty($effectiveWeekdays)) {
                $startMonth = (string) ($rule['rotating']['start_month'] ?? date('Y-m'));
                [$sy, $sm] = array_pad(explode('-', $startMonth), 2, '01');
                [$ty, $tm] = array_pad(explode('-', $slotMonth), 2, '01');
                $shift = ((int) $ty - (int) $sy) * 12 + ((int) $tm - (int) $sm);
                if ($shift > 0) {
                    $rotated = [];
                    foreach (array_keys($effectiveWeekdays) as $w) {
                        $rotated[(($w + $shift) % 7)] = true;
                    }
                    $effectiveWeekdays = $rotated;
                }
            }

            $weekday = (int) date('w', strtotime($slotDate));
            if (!empty($effectiveWeekdays[$weekday])) {
                return 'rest';
            }

            return null;
        };

        $scopeWhere = '1=1';
        $scopeParams = [
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
        ];
        if ($role === 'department_manager') {
            $scopeWhere = 'd.id = :department_id';
            $scopeParams['department_id'] = (int) ($profile['department_id'] ?? 0);
        } elseif ($role === 'admin') {
            $scopeWhere = 'd.company_id = :company_id';
            $scopeParams['company_id'] = (int) ($profile['company_id'] ?? 0);
        }

        $openShiftFilter = $scopeShiftId > 0 ? ' AND s.id = :scope_shift_id' : '';
        if ($scopeShiftId <= 0 && !empty($allowedShiftPlaceholders)) {
            $openShiftFilter .= ' AND s.id IN (' . implode(', ', $allowedShiftPlaceholders) . ')';
            foreach ($allowedShiftParams as $placeholder => $value) {
                $scopeParams[ltrim($placeholder, ':')] = $value;
            }
        }

        if ($action === 'auto_assign_forecast') {
            $forecastParams = $scopeParams;
            if ($scopeShiftId > 0) {
                $forecastParams['scope_shift_id'] = $scopeShiftId;
            }

            $forecastCoverageStmt = $pdo->prepare(
                'SELECT us.shift_id, us.work_date,
                        COUNT(*) AS slots_total,
                        SUM(CASE WHEN us.user_id IS NOT NULL AND us.status <> "cancelled" THEN 1 ELSE 0 END) AS assigned_count,
                        SUM(CASE WHEN us.user_id IS NULL AND us.status = "open" THEN 1 ELSE 0 END) AS open_count
                 FROM user_shifts us
                 INNER JOIN shifts s ON s.id = us.shift_id
                 INNER JOIN departments d ON d.id = s.department_id
                 WHERE ' . $scopeWhere . '
                   AND us.work_date BETWEEN :range_start AND :range_end
                   AND s.kind = "work"' . $openShiftFilter . '
                 GROUP BY us.shift_id, us.work_date'
            );
            $forecastCoverageStmt->execute($forecastParams);

            $groupCount = 0;
            $slotsTotal = 0;
            $slotsAssigned = 0;
            $slotsOpen = 0;
            $requiredAtMin = 0;
            $coveredAtMinGroups = 0;
            $uncoveredByOpenGroups = 0;
            foreach ($forecastCoverageStmt->fetchAll(PDO::FETCH_ASSOC) as $forecastRow) {
                $groupCount++;
                $totalForGroup = (int) ($forecastRow['slots_total'] ?? 0);
                $assignedForGroup = (int) ($forecastRow['assigned_count'] ?? 0);
                $openForGroup = (int) ($forecastRow['open_count'] ?? 0);
                $slotsTotal += $totalForGroup;
                $slotsAssigned += $assignedForGroup;
                $slotsOpen += $openForGroup;

                $missingToMin = max($minEmployeesPerShiftDay - $assignedForGroup, 0);
                $requiredAtMin += $missingToMin;
                if ($missingToMin === 0) {
                    $coveredAtMinGroups++;
                }
                if ($openForGroup > 0) {
                    $uncoveredByOpenGroups++;
                }
            }

            $scopeOnlyParams = $scopeParams;
            unset($scopeOnlyParams['range_start'], $scopeOnlyParams['range_end']);
            unset($scopeOnlyParams['scope_shift_id']);
            foreach (array_keys($scopeOnlyParams) as $paramKey) {
                if (strpos((string) $paramKey, 'allowed_shift_') === 0) {
                    unset($scopeOnlyParams[$paramKey]);
                }
            }
            if ($targetUserId > 0) {
                $scopeOnlyParams['target_user_id'] = $targetUserId;
            }

            $activeUsersStmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT u.id)
                 FROM users u
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.status = "active" AND ' . $scopeWhere . ($targetUserId > 0 ? ' AND u.id = :target_user_id' : '')
            );
            $activeUsersStmt->execute($scopeOnlyParams);
            $activeUsers = (int) ($activeUsersStmt->fetchColumn() ?: 0);

            $assignedScopedStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM user_shifts us
                 INNER JOIN shifts s ON s.id = us.shift_id
                 INNER JOIN users u ON u.id = us.user_id
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE us.status <> "cancelled"
                   AND us.work_date BETWEEN :range_start AND :range_end
                   AND s.kind = "work"
                   AND us.user_id IS NOT NULL
                   AND ' . $scopeWhere . ($targetUserId > 0 ? ' AND u.id = :target_user_id' : '')
            );
            $assignedScopedParams = $scopeParams;
            unset($assignedScopedParams['scope_shift_id']);
            foreach (array_keys($assignedScopedParams) as $paramKey) {
                if (strpos((string) $paramKey, 'allowed_shift_') === 0) {
                    unset($assignedScopedParams[$paramKey]);
                }
            }
            if ($targetUserId > 0) {
                $assignedScopedParams['target_user_id'] = $targetUserId;
            }
            $assignedScopedStmt->execute($assignedScopedParams);
            $currentAssignedByUsers = (int) ($assignedScopedStmt->fetchColumn() ?: 0);

            $startDt = new DateTimeImmutable($rangeStart);
            $endDt = new DateTimeImmutable($rangeEnd);
            $daysInRange = max(1, ((int) $startDt->diff($endDt)->format('%a')) + 1);
            $weekBlocks = max(1, (int) ceil($daysInRange / 7));
            
            // Calculate capacity per week more accurately
            $weeklyCapacityStmt = $pdo->prepare(
                'SELECT 
                    WEEK(us.work_date, 1) AS week_num,
                    YEAR(us.work_date) AS year_num,
                                     COUNT(DISTINCT CASE WHEN s.kind = "work" THEN u.id END) AS assigned_work_users,
                    COUNT(DISTINCT u.id) AS total_active_users
                 FROM user_shifts us
                 INNER JOIN shifts s ON s.id = us.shift_id
                 INNER JOIN users u ON u.id = us.user_id
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.status = "active"
                   AND us.status <> "cancelled"
                   AND us.work_date BETWEEN :range_start AND :range_end
                                    AND s.kind = "work"' . $openShiftFilter . '
                                    AND ' . $scopeWhere . ($targetUserId > 0 ? ' AND u.id = :target_user_id' : '') . '
                 GROUP BY YEAR(us.work_date), WEEK(us.work_date, 1)'
            );
                     $weeklyCapacityParams = $forecastParams;
            $weeklyCapacityStmt->execute($weeklyCapacityParams);
            $weeklyCapacityData = $weeklyCapacityStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Build conservative capacity estimate
            $totalRealCapacity = 0;
            foreach ($weeklyCapacityData as $weekRow) {
                $totalActiveInWeek = (int) ($weekRow['total_active_users'] ?? 0);
                $alreadyAssignedUsers = (int) ($weekRow['assigned_work_users'] ?? 0);
                
                // Conservative: assume max 70% of available slots can actually be filled
                // remaining 30% are lost to conflicts, rules, availability, rest days
                $weekCapacity = max(0, ($totalActiveInWeek - $alreadyAssignedUsers) * $effectiveMaxWorkDaysPerWeek * 0.7);
                $totalRealCapacity += $weekCapacity;
            }
            
            // If no weekly data (no existing assignments), use conservative estimate
            if (empty($weeklyCapacityData) || $totalRealCapacity <= 0) {
                $estimatedCapacity = $activeUsers * $effectiveMaxWorkDaysPerWeek * $weekBlocks * 0.65;
            } else {
                $estimatedCapacity = $totalRealCapacity;
            }
            
            $potentialAdditional = max(0, $estimatedCapacity - $currentAssignedByUsers);
            $predictedCoverableToMin = min($requiredAtMin, $potentialAdditional, $slotsOpen);
            $predictedRemainingAtMin = max(0, $requiredAtMin - $predictedCoverableToMin);
            $predictedSurplus = max(0, $potentialAdditional - $requiredAtMin);

            $status = 'balanced';
            if ($predictedRemainingAtMin > 0) {
                $status = 'shortage';
            } elseif ($predictedSurplus > 0) {
                $status = 'surplus';
            }

            $crossDeptSuggestions = [];
                $uncoveredDays = [];
                $shiftCreationSuggestions = [];
            if ($slotsOpen > 0) {
                $openGroupsStmt = $pdo->prepare(
                    'SELECT us.work_date, s.department_id, d.name AS department_name,
                            SUM(CASE WHEN us.user_id IS NULL AND us.status = "open" THEN 1 ELSE 0 END) AS open_count
                     FROM user_shifts us
                     INNER JOIN shifts s ON s.id = us.shift_id
                     INNER JOIN departments d ON d.id = s.department_id
                     WHERE ' . $scopeWhere . '
                       AND us.work_date BETWEEN :range_start AND :range_end
                       AND s.kind = "work"' . $openShiftFilter . '
                     GROUP BY us.work_date, s.department_id, d.name
                     HAVING open_count > 0
                     ORDER BY open_count DESC, us.work_date ASC
                     LIMIT 6'
                );
                $openGroupsStmt->execute($forecastParams);
                $openGroups = $openGroupsStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($openGroups)) {
                    $dayTotals = [];
                    foreach ($openGroups as $openGroupRow) {
                        $d = (string) ($openGroupRow['work_date'] ?? '');
                        $c = (int) ($openGroupRow['open_count'] ?? 0);
                        if ($d !== '' && $c > 0) {
                            $dayTotals[$d] = (int) ($dayTotals[$d] ?? 0) + $c;
                        }
                    }
                    foreach ($dayTotals as $dateValue => $openCount) {
                        $uncoveredDays[] = [
                            'work_date' => $dateValue,
                            'open_count' => $openCount,
                        ];
                    }
                    usort($uncoveredDays, static function (array $a, array $b): int {
                        if ((int) ($a['open_count'] ?? 0) === (int) ($b['open_count'] ?? 0)) {
                            return strcmp((string) ($a['work_date'] ?? ''), (string) ($b['work_date'] ?? ''));
                        }
                        return ((int) ($b['open_count'] ?? 0)) <=> ((int) ($a['open_count'] ?? 0));
                    });
                    $uncoveredDays = array_slice($uncoveredDays, 0, 8);
                }

                $openShiftTypeStmt = $pdo->prepare(
                    'SELECT s.department_id, d.name AS department_name, s.id AS shift_id, s.name AS shift_name,
                            SUM(CASE WHEN us.user_id IS NULL AND us.status = "open" THEN 1 ELSE 0 END) AS open_slots
                     FROM user_shifts us
                     INNER JOIN shifts s ON s.id = us.shift_id
                     INNER JOIN departments d ON d.id = s.department_id
                     WHERE ' . $scopeWhere . '
                       AND us.work_date BETWEEN :range_start AND :range_end
                       AND s.kind = "work"' . $openShiftFilter . '
                     GROUP BY s.department_id, d.name, s.id, s.name
                     HAVING open_slots > 0
                     ORDER BY open_slots DESC, d.name ASC, s.name ASC
                     LIMIT 6'
                );
                $openShiftTypeStmt->execute($forecastParams);
                foreach ($openShiftTypeStmt->fetchAll(PDO::FETCH_ASSOC) as $openShiftTypeRow) {
                    $shiftCreationSuggestions[] = [
                        'department_id' => (int) ($openShiftTypeRow['department_id'] ?? 0),
                        'department_name' => (string) ($openShiftTypeRow['department_name'] ?? 'Department'),
                        'shift_id' => (int) ($openShiftTypeRow['shift_id'] ?? 0),
                        'shift_name' => (string) ($openShiftTypeRow['shift_name'] ?? 'Shift'),
                        'open_slots' => (int) ($openShiftTypeRow['open_slots'] ?? 0),
                    ];
                }

                if (!empty($openGroups)) {
                    $usersByScopeStmt = $pdo->prepare(
                        'SELECT u.id, u.first_name, u.last_name, u.department_id
                         FROM users u
                         LEFT JOIN departments d ON d.id = u.department_id
                         WHERE u.status = "active" AND ' . $scopeWhere . ($targetUserId > 0 ? ' AND u.id = :target_user_id' : '') . '
                         ORDER BY u.id ASC'
                    );
                    $usersByScopeStmt->execute($scopeOnlyParams);
                    $usersByScope = $usersByScopeStmt->fetchAll(PDO::FETCH_ASSOC);
                    $usersByScopeDepartmentIds = $loadUserDepartmentIdsMap(
                        $pdo,
                        array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $usersByScope)
                    );

                    $busyStmt = $pdo->prepare(
                        'SELECT us.user_id, us.work_date
                         FROM user_shifts us
                         WHERE us.user_id IS NOT NULL
                           AND us.status <> "cancelled"
                           AND us.work_date BETWEEN :range_start AND :range_end'
                    );
                    $busyStmt->execute([
                        'range_start' => $rangeStart,
                        'range_end' => $rangeEnd,
                    ]);
                    $busyByUserDate = [];
                    foreach ($busyStmt->fetchAll(PDO::FETCH_ASSOC) as $busyRow) {
                        $busyUid = (int) ($busyRow['user_id'] ?? 0);
                        $busyDate = (string) ($busyRow['work_date'] ?? '');
                        if ($busyUid > 0 && $busyDate !== '') {
                            $busyByUserDate[$busyUid . '|' . $busyDate] = true;
                        }
                    }

                    foreach ($openGroups as $openGroupRow) {
                        $targetDate = (string) ($openGroupRow['work_date'] ?? '');
                        $targetDeptId = (int) ($openGroupRow['department_id'] ?? 0);
                        $targetDeptName = (string) ($openGroupRow['department_name'] ?? 'Department');
                        $openCount = (int) ($openGroupRow['open_count'] ?? 0);
                        if ($targetDate === '' || $targetDeptId <= 0 || $openCount <= 0) {
                            continue;
                        }

                        $candidates = [];
                        foreach ($usersByScope as $scopeUserRow) {
                            $uid = (int) ($scopeUserRow['id'] ?? 0);
                            if ($uid <= 0) {
                                continue;
                            }
                            $candidateDeptIds = $usersByScopeDepartmentIds[$uid] ?? [];
                            if (empty($candidateDeptIds)) {
                                $fallbackDeptId = (int) ($scopeUserRow['department_id'] ?? 0);
                                if ($fallbackDeptId > 0) {
                                    $candidateDeptIds = [$fallbackDeptId];
                                }
                            }
                            if (empty($candidateDeptIds) || in_array($targetDeptId, $candidateDeptIds, true)) {
                                continue;
                            }
                            if (!empty($busyByUserDate[$uid . '|' . $targetDate])) {
                                continue;
                            }
                            $fullName = trim(((string) ($scopeUserRow['first_name'] ?? '')) . ' ' . ((string) ($scopeUserRow['last_name'] ?? '')));
                            if ($fullName === '') {
                                $fullName = 'User #' . $uid;
                            }
                            $candidates[] = [
                                'user_id' => $uid,
                                'name' => $fullName,
                                'department_id' => (int) ($candidateDeptIds[0] ?? 0),
                            ];
                            if (count($candidates) >= 3) {
                                break;
                            }
                        }

                        if (!empty($candidates)) {
                            $crossDeptSuggestions[] = [
                                'work_date' => $targetDate,
                                'department_id' => $targetDeptId,
                                'department_name' => $targetDeptName,
                                'open_count' => $openCount,
                                'candidates' => $candidates,
                            ];
                        }
                    }
                }
            }

            jsonResponse([
                'success' => true,
                'ok' => true,
                'status' => $status,
                'rest_distribution_mode' => $restDistributionMode,
                'min_employees_per_shift_day' => $minEmployeesPerShiftDay,
                'max_employees_per_shift_day' => $maxEmployeesPerShiftDay,
                'forecast' => [
                    'range_start' => $rangeStart,
                    'range_end' => $rangeEnd,
                    'slots_total' => $slotsTotal,
                    'slots_assigned' => $slotsAssigned,
                    'slots_open' => $slotsOpen,
                    'shift_days_total' => $groupCount,
                    'covered_at_min_groups' => $coveredAtMinGroups,
                    'uncovered_days_open' => $uncoveredByOpenGroups,
                    'uncovered_days_min' => max($groupCount - $coveredAtMinGroups, 0),
                    'employees_in_scope' => $activeUsers,
                    'capacity_estimate' => $estimatedCapacity,
                    'required_to_minimum' => $requiredAtMin,
                    'predicted_coverable_to_min' => $predictedCoverableToMin,
                    'predicted_remaining_at_min' => $predictedRemainingAtMin,
                    'predicted_surplus_capacity' => $predictedSurplus,
                ],
                'cross_department_suggestions' => $crossDeptSuggestions,
                'uncovered_days' => $uncoveredDays,
                'shift_creation_suggestions' => $shiftCreationSuggestions,
            ]);
        }

        $openStmt = $pdo->prepare(
            'SELECT us.id, us.work_date, s.id AS shift_id, s.start_time, s.end_time, s.kind AS shift_kind, d.id AS department_id, d.name AS department_name
             FROM user_shifts us
             INNER JOIN shifts s ON s.id = us.shift_id
             INNER JOIN departments d ON d.id = s.department_id
             WHERE ' . $scopeWhere . ' AND us.work_date BETWEEN :range_start AND :range_end AND us.user_id IS NULL AND us.status = "open" AND s.kind = "work"' . $openShiftFilter . '
             ORDER BY us.work_date ASC, s.start_time ASC, us.id ASC'
        );
        if ($scopeShiftId > 0) {
            $scopeParams['scope_shift_id'] = $scopeShiftId;
        }
        $openStmt->execute($scopeParams);
        $openRows = $openStmt->fetchAll(PDO::FETCH_ASSOC);

        $scopeOnlyParams = $scopeParams;
        unset($scopeOnlyParams['range_start'], $scopeOnlyParams['range_end']);
        unset($scopeOnlyParams['scope_shift_id']);
        foreach (array_keys($scopeOnlyParams) as $paramKey) {
            if (strpos((string) $paramKey, 'allowed_shift_') === 0) {
                unset($scopeOnlyParams[$paramKey]);
            }
        }
        if ($targetUserId > 0) {
            $scopeOnlyParams['target_user_id'] = $targetUserId;
        }
        $userStmt = $pdo->prepare(
            'SELECT u.id, u.department_id
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.status = "active" AND ' . $scopeWhere . ($targetUserId > 0 ? ' AND u.id = :target_user_id' : '') . '
             ORDER BY u.id ASC'
        );
        $userStmt->execute($scopeOnlyParams);
        $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        $userDepartmentIdsById = $loadUserDepartmentIdsMap($pdo, array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $users));
        $userDepartmentById = [];
        foreach ($users as $userRow) {
            $userDepartmentById[(int) ($userRow['id'] ?? 0)] = (int) ($userRow['department_id'] ?? 0);
        }

        $userIdsInScope = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $users), static fn (int $id): bool => $id > 0));
        if (!empty($userIdsInScope)) {
            // Keep implicit fixed off map empty unless explicitly derived from user rules.
        }

        $nonWorkTemplateParams = $scopeOnlyParams;
        unset($nonWorkTemplateParams['target_user_id']);
        $nonWorkTemplateStmt = $pdo->prepare(
            'SELECT s.id, s.department_id, s.kind
             FROM shifts s
             INNER JOIN departments d ON d.id = s.department_id
             WHERE s.kind IN ("rest", "vacation", "sick")
               AND ' . $scopeWhere
        );
        $nonWorkTemplateStmt->execute($nonWorkTemplateParams);
        $nonWorkShiftByDepartmentKind = [];
        foreach ($nonWorkTemplateStmt->fetchAll(PDO::FETCH_ASSOC) as $templateRow) {
            $deptId = (int) ($templateRow['department_id'] ?? 0);
            $kind = strtolower(trim((string) ($templateRow['kind'] ?? '')));
            $templateId = (int) ($templateRow['id'] ?? 0);
            if ($deptId > 0 && $templateId > 0 && in_array($kind, ['rest', 'vacation', 'sick'], true)) {
                $nonWorkShiftByDepartmentKind[$deptId][$kind] = $templateId;
            }
        }

        $hoursByUserMonth = [];
        $dayBusy = [];
        $busyCountByUserDate = [];
        $assignmentByUserDate = [];
        $workAssignmentByUserDate = [];
        $workDaysByUserWeek = [];
        $blockedDaysByUserWeek = [];
        $protectedAbsenceDaysByUserWeek = [];
        $restDaysByUserWeek = [];
        $restAssignedByDepartmentDate = [];
        $snapshot = $pdo->prepare(
            'SELECT us.id AS assignment_id, us.user_id, us.work_date, us.shift_id, s.department_id, s.start_time, s.end_time, s.kind AS shift_kind
             FROM user_shifts us
             INNER JOIN shifts s ON s.id = us.shift_id
             WHERE us.user_id IS NOT NULL AND us.work_date BETWEEN :range_start AND :range_end AND us.status <> "cancelled"'
        );
        $snapshot->execute([
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
        ]);
        foreach ($snapshot->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $date = (string) ($row['work_date'] ?? '');
            $departmentId = (int) ($row['department_id'] ?? 0);
            $month = substr($date, 0, 7);
            $week = date('o-W', strtotime($date));
            $kind = strtolower(trim((string) ($row['shift_kind'] ?? 'work')));
            $startParts = explode(':', (string) ($row['start_time'] ?? '00:00:00'));
            $endParts = explode(':', (string) ($row['end_time'] ?? '00:00:00'));
            $startMinutes = ((int) ($startParts[0] ?? 0) * 60) + (int) ($startParts[1] ?? 0);
            $endMinutes = ((int) ($endParts[0] ?? 0) * 60) + (int) ($endParts[1] ?? 0);
            $delta = $endMinutes - $startMinutes;
            if ($delta <= 0) {
                $delta += 24 * 60;
            }
            $hours = ($delta / 60);
            $hoursByUserMonth[$uid][$month] = ($hoursByUserMonth[$uid][$month] ?? 0.0) + ($delta / 60);
            $dayBusy[$uid][$date] = true;
            $busyCountByUserDate[$uid][$date] = (int) ($busyCountByUserDate[$uid][$date] ?? 0) + 1;
            $assignmentByUserDate[$uid][$date] = [
                'assignment_id' => (int) ($row['assignment_id'] ?? 0),
                'shift_id' => (int) ($row['shift_id'] ?? 0),
                'department_id' => $departmentId,
                'shift_kind' => $kind,
                'hours' => $hours,
                'week' => $week,
                'work_date' => $date,
            ];
            if ($kind === 'work') {
                $workDaysByUserWeek[$uid][$week][$date] = true;
                $workAssignmentByUserDate[$uid][$date] = [
                    'assignment_id' => (int) ($row['assignment_id'] ?? 0),
                    'shift_id' => (int) ($row['shift_id'] ?? 0),
                    'department_id' => $departmentId,
                    'work_date' => $date,
                    'hours' => $hours,
                    'week' => $week,
                ];
            } elseif (in_array($kind, ['rest', 'vacation', 'sick'], true)) {
                $blockedDaysByUserWeek[$uid][$week][$date] = true;
                if ($kind === 'rest') {
                    $restDaysByUserWeek[$uid][$week][$date] = true;
                    if ($departmentId > 0 && $date !== '') {
                        $restAssignedByDepartmentDate[$departmentId . '|' . $date] = (int) ($restAssignedByDepartmentDate[$departmentId . '|' . $date] ?? 0) + 1;
                    }
                } elseif (in_array($kind, ['vacation', 'sick'], true)) {
                    $protectedAbsenceDaysByUserWeek[$uid][$week][$date] = true;
                }
            }
        }

        $dateKeysInRange = [];
        $cursorDate = new DateTimeImmutable($rangeStart);
        $endDate = new DateTimeImmutable($rangeEnd);
        while ($cursorDate <= $endDate) {
            $dateKeysInRange[] = $cursorDate->format('Y-m-d');
            $cursorDate = $cursorDate->modify('+1 day');
        }

        $releaseWorkAssignmentStmt = $pdo->prepare(
            'UPDATE user_shifts
             SET user_id = NULL,
                 status = "open",
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $assignExistingByIdStmt = $pdo->prepare(
            'UPDATE user_shifts
             SET shift_id = :shift_id,
                 user_id = :user_id,
                 status = "assigned",
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $findOpenSlotForShiftStmt = $pdo->prepare(
            'SELECT id
             FROM user_shifts
             WHERE shift_id = :shift_id
               AND work_date = :work_date
               AND user_id IS NULL
               AND status = "open"
             ORDER BY id ASC
             LIMIT 1'
        );
        $findAssignedForUserDateStmt = $pdo->prepare(
            'SELECT us.id, us.shift_id, s.kind AS shift_kind
             FROM user_shifts us
             INNER JOIN shifts s ON s.id = us.shift_id
             WHERE us.user_id = :user_id
               AND us.work_date = :work_date
               AND us.status <> "cancelled"
             ORDER BY us.id ASC
             LIMIT 1'
        );
        $insertAssignedShiftStmt = $pdo->prepare(
            'INSERT INTO user_shifts (shift_id, user_id, work_date, status)
             VALUES (:shift_id, :user_id, :work_date, "assigned")'
        );

        $assignNonWorkDay = static function (int $uid, int $departmentId, string $slotDate, string $reasonKind) use (
            &$assignmentByUserDate,
            &$workAssignmentByUserDate,
            &$workDaysByUserWeek,
            &$blockedDaysByUserWeek,
            &$restDaysByUserWeek,
            &$restAssignedByDepartmentDate,
            &$hoursByUserMonth,
            &$dayBusy,
            &$busyCountByUserDate,
            $nonWorkShiftByDepartmentKind,
            $releaseWorkAssignmentStmt,
            $assignExistingByIdStmt,
            $findOpenSlotForShiftStmt,
            $findAssignedForUserDateStmt,
            $insertAssignedShiftStmt,
            $effectiveMinWorkDaysPerWeek,
            $pdo
        ): bool {
            if ($uid <= 0 || $departmentId <= 0 || $slotDate === '' || !isset($nonWorkShiftByDepartmentKind[$departmentId][$reasonKind])) {
                return false;
            }

            $targetShiftId = (int) $nonWorkShiftByDepartmentKind[$departmentId][$reasonKind];
            $slotWeek = date('o-W', strtotime($slotDate));
            $slotMonth = substr($slotDate, 0, 7);
            $slotDeptDateKey = $departmentId . '|' . $slotDate;

            $currentAssignment = $assignmentByUserDate[$uid][$slotDate] ?? null;
            if ($currentAssignment && (string) ($currentAssignment['shift_kind'] ?? '') === $reasonKind) {
                return true;
            }
            if ($reasonKind === 'rest' && $currentAssignment && in_array((string) ($currentAssignment['shift_kind'] ?? ''), ['vacation', 'sick'], true)) {
                return false;
            }
            if ($reasonKind === 'rest' && (int) ($restAssignedByDepartmentDate[$slotDeptDateKey] ?? 0) >= 1) {
                return false;
            }
            if ($reasonKind === 'rest' && $currentAssignment && (string) ($currentAssignment['shift_kind'] ?? '') === 'work') {
                $existingWorkCount = count($workDaysByUserWeek[$uid][$slotWeek] ?? []);
                if ($existingWorkCount <= $effectiveMinWorkDaysPerWeek) {
                    return false;
                }
            }

            if ($currentAssignment && (string) ($currentAssignment['shift_kind'] ?? '') === 'work') {
                $releaseWorkAssignmentStmt->execute(['id' => (int) ($currentAssignment['assignment_id'] ?? 0)]);
            }

            if ($currentAssignment && (float) ($currentAssignment['hours'] ?? 0.0) > 0) {
                $hoursByUserMonth[$uid][$slotMonth] = max(0.0, (float) ($hoursByUserMonth[$uid][$slotMonth] ?? 0.0) - (float) ($currentAssignment['hours'] ?? 0.0));
            }

            if ($currentAssignment && isset($workDaysByUserWeek[$uid][$slotWeek][$slotDate])) {
                unset($workDaysByUserWeek[$uid][$slotWeek][$slotDate]);
            }
            if ($currentAssignment && (string) ($currentAssignment['shift_kind'] ?? '') === 'rest' && isset($restDaysByUserWeek[$uid][$slotWeek][$slotDate])) {
                unset($restDaysByUserWeek[$uid][$slotWeek][$slotDate]);
                $restAssignedByDepartmentDate[$slotDeptDateKey] = max(0, (int) ($restAssignedByDepartmentDate[$slotDeptDateKey] ?? 1) - 1);
            }

            $findOpenSlotForShiftStmt->execute([
                'shift_id' => $targetShiftId,
                'work_date' => $slotDate,
            ]);
            $openSlotId = (int) ($findOpenSlotForShiftStmt->fetchColumn() ?: 0);

            if ($openSlotId > 0) {
                $assignExistingByIdStmt->execute([
                    'shift_id' => $targetShiftId,
                    'user_id' => $uid,
                    'id' => $openSlotId,
                ]);
                $newAssignmentId = $openSlotId;
            } elseif ($currentAssignment && (int) ($currentAssignment['assignment_id'] ?? 0) > 0) {
                $assignExistingByIdStmt->execute([
                    'shift_id' => $targetShiftId,
                    'user_id' => $uid,
                    'id' => (int) ($currentAssignment['assignment_id'] ?? 0),
                ]);
                $newAssignmentId = (int) ($currentAssignment['assignment_id'] ?? 0);
            } else {
                $findAssignedForUserDateStmt->execute([
                    'user_id' => $uid,
                    'work_date' => $slotDate,
                ]);
                $existingAny = $findAssignedForUserDateStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($existingAny && (int) ($existingAny['id'] ?? 0) > 0) {
                    $assignExistingByIdStmt->execute([
                        'shift_id' => $targetShiftId,
                        'user_id' => $uid,
                        'id' => (int) ($existingAny['id'] ?? 0),
                    ]);
                    $newAssignmentId = (int) ($existingAny['id'] ?? 0);
                } else {
                    $insertAssignedShiftStmt->execute([
                        'shift_id' => $targetShiftId,
                        'user_id' => $uid,
                        'work_date' => $slotDate,
                    ]);
                    $newAssignmentId = (int) $pdo->lastInsertId();
                }
            }

            $assignmentByUserDate[$uid][$slotDate] = [
                'assignment_id' => $newAssignmentId,
                'shift_id' => $targetShiftId,
                'shift_kind' => $reasonKind,
                'hours' => 0.0,
                'week' => $slotWeek,
                'work_date' => $slotDate,
            ];
            $blockedDaysByUserWeek[$uid][$slotWeek][$slotDate] = true;
            if ($reasonKind === 'rest') {
                $restDaysByUserWeek[$uid][$slotWeek][$slotDate] = true;
                $restAssignedByDepartmentDate[$slotDeptDateKey] = (int) ($restAssignedByDepartmentDate[$slotDeptDateKey] ?? 0) + 1;
            }
            $dayBusy[$uid][$slotDate] = true;
            $busyCountByUserDate[$uid][$slotDate] = 1;
            unset($workAssignmentByUserDate[$uid][$slotDate]);

            return true;
        };

        $restCandidatesByDepartmentDate = [];
        $autoRestCandidatesByUserWeek = [];
        foreach ($users as $candidateUser) {
            $uid = (int) ($candidateUser['id'] ?? 0);
            $candidateDepartmentIds = $userDepartmentIdsById[$uid] ?? [];
            $departmentId = (int) ($candidateDepartmentIds[0] ?? ($candidateUser['department_id'] ?? 0));
            if ($uid <= 0 || $departmentId <= 0) {
                continue;
            }
            $hasExplicitRules = $hasExplicitRestPlan($uid);

            foreach ($dateKeysInRange as $slotDate) {
                $reasonKind = $getRuleReasonByDate($uid, $slotDate);
                if (!$reasonKind || !isset($nonWorkShiftByDepartmentKind[$departmentId][$reasonKind])) {
                    if (!$hasExplicitRules && $restDaysPerWeek > 0) {
                        $slotWeek = date('o-W', strtotime($slotDate));
                        $autoRestCandidatesByUserWeek[$uid][$slotWeek][] = $slotDate;
                    }
                    continue;
                }

                if ($reasonKind === 'rest' && $restDistributionMode !== 'fixed') {
                    $restCandidatesByDepartmentDate[$departmentId . '|' . $slotDate][] = $uid;
                    continue;
                }

                $assignNonWorkDay($uid, $departmentId, $slotDate, $reasonKind);
            }
        }

        if ($restDistributionMode !== 'fixed') {
            foreach ($restCandidatesByDepartmentDate as $departmentDateKey => $candidateUserIds) {
                [$departmentIdRaw, $slotDate] = array_pad(explode('|', $departmentDateKey, 2), 2, '');
                $departmentId = (int) $departmentIdRaw;
                if ($departmentId <= 0 || $slotDate === '') {
                    continue;
                }
                if ((int) ($restAssignedByDepartmentDate[$departmentDateKey] ?? 0) > 0) {
                    continue;
                }

                $slotWeek = date('o-W', strtotime($slotDate));
                $pool = [];
                foreach (array_values(array_unique(array_map('intval', $candidateUserIds))) as $uid) {
                    if ($uid <= 0 || (string) ($getRuleReasonByDate($uid, $slotDate) ?? '') !== 'rest') {
                        continue;
                    }
                    $currentAssignment = $assignmentByUserDate[$uid][$slotDate] ?? null;
                    $currentKind = strtolower(trim((string) ($currentAssignment['shift_kind'] ?? '')));
                    if (in_array($currentKind, ['vacation', 'sick', 'rest'], true)) {
                        continue;
                    }
                    $restCount = count($restDaysByUserWeek[$uid][$slotWeek] ?? []);
                    if ($effectiveMaxRestDaysPerWeek > 0 && $restCount >= $effectiveMaxRestDaysPerWeek) {
                        continue;
                    }
                    $pool[] = [
                        'user_id' => $uid,
                        'rest_count' => $restCount,
                    ];
                }

                if (empty($pool)) {
                    continue;
                }

                if ($restDistributionMode === 'random') {
                    shuffle($pool);
                } else {
                    usort($pool, static function (array $a, array $b): int {
                        if ((int) ($a['rest_count'] ?? 0) === (int) ($b['rest_count'] ?? 0)) {
                            return ((int) ($a['user_id'] ?? 0)) <=> ((int) ($b['user_id'] ?? 0));
                        }
                        return ((int) ($a['rest_count'] ?? 0)) <=> ((int) ($b['rest_count'] ?? 0));
                    });
                }

                $chosenUserId = (int) ($pool[0]['user_id'] ?? 0);
                if ($chosenUserId > 0) {
                    $assignNonWorkDay($chosenUserId, $departmentId, $slotDate, 'rest');
                }
            }
        }

        if ($restDaysPerWeek > 0) {
            foreach ($autoRestCandidatesByUserWeek as $uid => $weeks) {
                $uid = (int) $uid;
                if ($uid <= 0) {
                    continue;
                }
                $candidateDepartmentIds = $userDepartmentIdsById[$uid] ?? [];
                $departmentId = (int) ($candidateDepartmentIds[0] ?? ($userDepartmentById[$uid] ?? 0));
                if ($departmentId <= 0) {
                    continue;
                }

                foreach ($weeks as $weekKey => $candidateDates) {
                    $existingBlockedCount = count($blockedDaysByUserWeek[$uid][$weekKey] ?? []);
                    $neededRest = max(0, $restDaysPerWeek - $existingBlockedCount);
                    if ($neededRest <= 0) {
                        continue;
                    }

                    $dates = array_values(array_unique(array_filter(array_map(static fn($d) => (string) $d, $candidateDates))));
                    if (empty($dates)) {
                        continue;
                    }

                    if ($restDistributionMode === 'random') {
                        shuffle($dates);
                    } elseif ($restDistributionMode === 'staggered') {
                        usort($dates, static function (string $a, string $b) use ($restAssignedByDepartmentDate, $departmentId): int {
                            $aKey = $departmentId . '|' . $a;
                            $bKey = $departmentId . '|' . $b;
                            $aRestLoad = (int) ($restAssignedByDepartmentDate[$aKey] ?? 0);
                            $bRestLoad = (int) ($restAssignedByDepartmentDate[$bKey] ?? 0);
                            if ($aRestLoad === $bRestLoad) {
                                return $a <=> $b;
                            }
                            return $aRestLoad <=> $bRestLoad;
                        });
                    } else {
                        usort($dates, static function (string $a, string $b): int {
                            $wa = (int) date('w', strtotime($a));
                            $wb = (int) date('w', strtotime($b));
                            $pa = in_array($wa, [6, 0], true) ? 0 : 1;
                            $pb = in_array($wb, [6, 0], true) ? 0 : 1;
                            if ($pa === $pb) {
                                return $a <=> $b;
                            }
                            return $pa <=> $pb;
                        });
                    }

                    foreach ($dates as $slotDate) {
                        if ($neededRest <= 0) {
                            break;
                        }
                        $assigned = $assignNonWorkDay($uid, $departmentId, $slotDate, 'rest');
                        if ($assigned) {
                            $neededRest--;
                        }
                    }
                }
            }
        }

        $coverageParams = $scopeParams;
        $coverageStmt = $pdo->prepare(
            'SELECT us.shift_id, us.work_date,
                    SUM(CASE WHEN us.user_id IS NOT NULL AND us.status <> "cancelled" THEN 1 ELSE 0 END) AS assigned_count
             FROM user_shifts us
             INNER JOIN shifts s ON s.id = us.shift_id
             INNER JOIN departments d ON d.id = s.department_id
             WHERE ' . $scopeWhere . '
               AND us.work_date BETWEEN :range_start AND :range_end
               AND s.kind = "work"' . $openShiftFilter . '
             GROUP BY us.shift_id, us.work_date'
        );
        $coverageStmt->execute($coverageParams);
        $coverageByShiftDate = [];
        foreach ($coverageStmt->fetchAll(PDO::FETCH_ASSOC) as $coverageRow) {
            $coverageKey = ((int) ($coverageRow['shift_id'] ?? 0)) . '|' . (string) ($coverageRow['work_date'] ?? '');
            $coverageByShiftDate[$coverageKey] = (int) ($coverageRow['assigned_count'] ?? 0);
        }

        $openRowsByShiftDate = [];
        foreach ($openRows as $openRow) {
            $groupKey = ((int) ($openRow['shift_id'] ?? 0)) . '|' . (string) ($openRow['work_date'] ?? '');
            if (!isset($openRowsByShiftDate[$groupKey])) {
                $openRowsByShiftDate[$groupKey] = [];
            }
            $openRowsByShiftDate[$groupKey][] = $openRow;
            if (!array_key_exists($groupKey, $coverageByShiftDate)) {
                $coverageByShiftDate[$groupKey] = 0;
            }
        }

        $isEssentialDepartment = static function (string $departmentName): bool {
            $normalized = strtolower(trim($departmentName));
            if ($normalized === '') {
                return false;
            }
            $normalized = str_replace([' ', "'", '-', '_'], '', $normalized);
            foreach (['ricevimento', 'reception', 'recepcion', 'accueil', 'frontoffice', 'frontdesk'] as $needle) {
                if (str_contains($normalized, $needle)) {
                    return true;
                }
            }

            return false;
        };

        $groupOrder = array_keys($openRowsByShiftDate);
        usort($groupOrder, static function (string $a, string $b) use ($coverageByShiftDate, $minEmployeesPerShiftDay, $openRowsByShiftDate, $isEssentialDepartment, $priorityDepartmentId): int {
            [$aShift, $aDate] = array_pad(explode('|', $a, 2), 2, '');
            [$bShift, $bDate] = array_pad(explode('|', $b, 2), 2, '');
            $aDepartmentId = (int) (($openRowsByShiftDate[$a][0]['department_id'] ?? 0) ?: 0);
            $bDepartmentId = (int) (($openRowsByShiftDate[$b][0]['department_id'] ?? 0) ?: 0);
            $aPriority = ($priorityDepartmentId > 0 && $aDepartmentId === $priorityDepartmentId) ? 1 : 0;
            $bPriority = ($priorityDepartmentId > 0 && $bDepartmentId === $priorityDepartmentId) ? 1 : 0;
            if ($aPriority !== $bPriority) {
                return $bPriority <=> $aPriority;
            }
            $aDepartmentName = (string) (($openRowsByShiftDate[$a][0]['department_name'] ?? '') ?: '');
            $bDepartmentName = (string) (($openRowsByShiftDate[$b][0]['department_name'] ?? '') ?: '');
            $aEssential = $isEssentialDepartment($aDepartmentName) ? 1 : 0;
            $bEssential = $isEssentialDepartment($bDepartmentName) ? 1 : 0;
            if ($aEssential !== $bEssential) {
                return $bEssential <=> $aEssential;
            }
            $aAssigned = (int) ($coverageByShiftDate[$a] ?? 0);
            $bAssigned = (int) ($coverageByShiftDate[$b] ?? 0);
            $aDeficit = max($minEmployeesPerShiftDay - $aAssigned, 0);
            $bDeficit = max($minEmployeesPerShiftDay - $bAssigned, 0);
            if ($aDeficit !== $bDeficit) {
                return $bDeficit <=> $aDeficit;
            }
            if ($aDate !== $bDate) {
                return $aDate <=> $bDate;
            }

            return ((int) $aShift) <=> ((int) $bShift);
        });

        $updateAssignment = $pdo->prepare(
            'UPDATE user_shifts SET user_id = :user_id, status = "assigned", updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $releaseAssignment = $pdo->prepare(
            'UPDATE user_shifts SET user_id = NULL, status = "open", updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $assignedCount = 0;
        $reassignedCount = 0;
        $crossDepartmentAssignedCount = 0;
        $skippedByRules = 0;
        $assignedForTargetUser = false;
        $userMetaById = [];
        $departmentNameById = [];
        foreach ($users as $userRowMeta) {
            $metaUserId = (int) ($userRowMeta['id'] ?? 0);
            if ($metaUserId <= 0) {
                continue;
            }
            $fallbackDepartmentId = (int) ($userRowMeta['department_id'] ?? 0);
            $primaryDepartmentId = (int) (($userDepartmentIdsById[$metaUserId][0] ?? 0) ?: $fallbackDepartmentId);
            $userMetaById[$metaUserId] = [
                'id' => $metaUserId,
                'name' => trim(((string) ($userRowMeta['first_name'] ?? '')) . ' ' . ((string) ($userRowMeta['last_name'] ?? ''))) ?: ('User #' . $metaUserId),
                'department_id' => $primaryDepartmentId,
                'department_name' => '',
            ];
        }
        $departmentIdsForMeta = [];
        foreach ($userMetaById as $metaRow) {
            $metaDepartmentId = (int) ($metaRow['department_id'] ?? 0);
            if ($metaDepartmentId > 0) {
                $departmentIdsForMeta[$metaDepartmentId] = $metaDepartmentId;
            }
        }
        if (!empty($departmentIdsForMeta)) {
            $departmentMetaPlaceholders = implode(', ', array_fill(0, count($departmentIdsForMeta), '?'));
            $departmentMetaStmt = $pdo->prepare(
                'SELECT id, name FROM departments WHERE id IN (' . $departmentMetaPlaceholders . ')'
            );
            $departmentMetaStmt->execute(array_values($departmentIdsForMeta));
            foreach ($departmentMetaStmt->fetchAll(PDO::FETCH_ASSOC) as $departmentRowMeta) {
                $metaDepartmentId = (int) ($departmentRowMeta['id'] ?? 0);
                if ($metaDepartmentId <= 0) {
                    continue;
                }
                $departmentNameById[$metaDepartmentId] = (string) ($departmentRowMeta['name'] ?? ('Department #' . $metaDepartmentId));
            }
        }
        foreach ($userMetaById as $metaUserId => $metaRow) {
            $resolvedDepartmentId = (int) ($metaRow['department_id'] ?? 0);
            $userMetaById[$metaUserId]['department_name'] = (string) ($departmentNameById[$resolvedDepartmentId] ?? '');
        }
        $priorityOpenSlotsByDate = [];
        if ($priorityDepartmentId > 0) {
            foreach ($openRowsByShiftDate as $priorityGroupRows) {
                $firstPriorityRow = $priorityGroupRows[0] ?? null;
                if (!$firstPriorityRow) {
                    continue;
                }
                $priorityDeptInGroup = (int) ($firstPriorityRow['department_id'] ?? 0);
                if ($priorityDeptInGroup !== $priorityDepartmentId) {
                    continue;
                }
                $priorityDate = (string) ($firstPriorityRow['work_date'] ?? '');
                if ($priorityDate === '') {
                    continue;
                }
                $priorityOpenSlotsByDate[$priorityDate] = (int) ($priorityOpenSlotsByDate[$priorityDate] ?? 0) + count($priorityGroupRows);
            }
        }

        // Hard rule: keep at most one rest assignment per department/day.
        foreach ($restAssignedByDepartmentDate as $deptDateKey => $restCountForDeptDay) {
            $restCountForDeptDay = (int) $restCountForDeptDay;
            if ($restCountForDeptDay <= 1) {
                continue;
            }
            [$deptKeyPart, $dateKeyPart] = array_pad(explode('|', (string) $deptDateKey, 2), 2, '');
            $deptIdForRule = (int) $deptKeyPart;
            $dateForRule = (string) $dateKeyPart;
            if ($deptIdForRule <= 0 || $dateForRule === '') {
                continue;
            }

            $restCandidates = [];
            foreach ($users as $ruleUser) {
                $ruleUserId = (int) ($ruleUser['id'] ?? 0);
                if ($ruleUserId <= 0) {
                    continue;
                }
                if (!in_array($deptIdForRule, $userDepartmentIdsById[$ruleUserId] ?? [], true)) {
                    continue;
                }
                $ruleAssignment = $assignmentByUserDate[$ruleUserId][$dateForRule] ?? null;
                if (!$ruleAssignment || (string) ($ruleAssignment['shift_kind'] ?? '') !== 'rest') {
                    continue;
                }
                $monthKey = substr($dateForRule, 0, 7);
                $restCandidates[] = [
                    'user_id' => $ruleUserId,
                    'assignment' => $ruleAssignment,
                    'month_hours' => (float) ($hoursByUserMonth[$ruleUserId][$monthKey] ?? 0.0),
                ];
            }

            if (count($restCandidates) <= 1) {
                continue;
            }

            usort($restCandidates, static function (array $a, array $b): int {
                if ((float) ($a['month_hours'] ?? 0.0) === (float) ($b['month_hours'] ?? 0.0)) {
                    return ((int) ($a['user_id'] ?? 0)) <=> ((int) ($b['user_id'] ?? 0));
                }
                return ((float) ($a['month_hours'] ?? 0.0)) <=> ((float) ($b['month_hours'] ?? 0.0));
            });

            $keepOne = true;
            foreach ($restCandidates as $restCandidate) {
                if ($keepOne) {
                    $keepOne = false;
                    continue;
                }
                $releaseUserId = (int) ($restCandidate['user_id'] ?? 0);
                $releaseAssignmentRow = $restCandidate['assignment'] ?? [];
                $releaseId = (int) ($releaseAssignmentRow['assignment_id'] ?? 0);
                if ($releaseUserId <= 0 || $releaseId <= 0) {
                    continue;
                }

                $releaseWeek = date('o-W', strtotime($dateForRule));
                $releaseWorkAssignmentStmt->execute(['id' => $releaseId]);

                unset($assignmentByUserDate[$releaseUserId][$dateForRule]);
                unset($blockedDaysByUserWeek[$releaseUserId][$releaseWeek][$dateForRule]);
                unset($restDaysByUserWeek[$releaseUserId][$releaseWeek][$dateForRule]);
                unset($dayBusy[$releaseUserId][$dateForRule]);
                unset($busyCountByUserDate[$releaseUserId][$dateForRule]);
                $restAssignedByDepartmentDate[$deptDateKey] = max(0, ((int) ($restAssignedByDepartmentDate[$deptDateKey] ?? 1)) - 1);
                $reassignedCount++;
            }
        }

        foreach ($groupOrder as $groupKey) {
            $assignedInGroup = (int) ($coverageByShiftDate[$groupKey] ?? 0);
            if ($assignedInGroup >= $maxEmployeesPerShiftDay) {
                continue;
            }

            foreach ($openRowsByShiftDate[$groupKey] as $openRow) {
                if ($assignedInGroup >= $maxEmployeesPerShiftDay) {
                    break;
                }

                $slotDate = (string) ($openRow['work_date'] ?? '');
                $slotDepartmentId = (int) ($openRow['department_id'] ?? 0);
                $slotMonth = substr($slotDate, 0, 7);
                $startParts = explode(':', (string) ($openRow['start_time'] ?? '00:00:00'));
                $endParts = explode(':', (string) ($openRow['end_time'] ?? '00:00:00'));
                $startMinutes = ((int) ($startParts[0] ?? 0) * 60) + (int) ($startParts[1] ?? 0);
                $endMinutes = ((int) ($endParts[0] ?? 0) * 60) + (int) ($endParts[1] ?? 0);
                $delta = $endMinutes - $startMinutes;
                if ($delta <= 0) {
                    $delta += 24 * 60;
                }
                $slotHours = $delta / 60;

                $candidate = null;
                $candidatePreviousAssignment = null;
                $candidateHours = PHP_FLOAT_MAX;
                $candidateDepartmentPriority = -1;
                $candidatePenalty = PHP_FLOAT_MAX;
                $isEssentialSlot = $isEssentialDepartment((string) ($openRow['department_name'] ?? ''));
                $isPrioritySlot = $priorityDepartmentId > 0 && $slotDepartmentId === $priorityDepartmentId;
                foreach ($users as $candidateUser) {
                    $uid = (int) ($candidateUser['id'] ?? 0);
                    if ($uid <= 0) {
                        continue;
                    }
                    $candidateDepartmentIds = $userDepartmentIdsById[$uid] ?? [];
                    $isSameDepartment = in_array($slotDepartmentId, $candidateDepartmentIds, true);
                    if ($isPrioritySlot && $priorityDepartmentStrictInternal && !$isSameDepartment) {
                        continue;
                    }
                    if (!$isSameDepartment && !$allowCrossDepartmentFallback) {
                        continue;
                    }
                    $slotWeek = date('o-W', strtotime($slotDate));
                    $existingAssignment = $assignmentByUserDate[$uid][$slotDate] ?? null;
                    $existingKind = (string) ($existingAssignment['shift_kind'] ?? '');
                    $hasProtectedAbsence = in_array($existingKind, ['vacation', 'sick'], true);

                    if (!$isPrioritySlot && $priorityDepartmentId > 0 && $priorityDepartmentStrictInternal) {
                        $candidateBelongsToPriorityDepartment = in_array($priorityDepartmentId, $candidateDepartmentIds, true);
                        if ($candidateBelongsToPriorityDepartment && (int) ($priorityOpenSlotsByDate[$slotDate] ?? 0) > 0) {
                            continue;
                        }
                        if (
                            $candidateBelongsToPriorityDepartment
                            && $existingAssignment
                            && (string) ($existingAssignment['shift_kind'] ?? '') === 'work'
                            && (int) ($existingAssignment['department_id'] ?? 0) === $priorityDepartmentId
                        ) {
                            continue;
                        }
                    }

                    if (!empty($dayBusy[$uid][$slotDate])) {
                        if (!$allowReassignConflicts || !$existingAssignment || $hasProtectedAbsence) {
                            continue;
                        }
                    }
                    $currentWorkDays = count($workDaysByUserWeek[$uid][$slotWeek] ?? []);
                    $currentProtectedDays = count($protectedAbsenceDaysByUserWeek[$uid][$slotWeek] ?? []);
                    $currentRestDays = count($restDaysByUserWeek[$uid][$slotWeek] ?? []);
                    $existingIsRest = $existingAssignment && (string) ($existingAssignment['shift_kind'] ?? '') === 'rest';
                    $existingIsWork = $existingAssignment && (string) ($existingAssignment['shift_kind'] ?? '') === 'work';
                    $projectedWorkDays = $currentWorkDays + ($existingIsWork ? 0 : 1);
                    $projectedRestDays = max(0, $currentRestDays - ($existingIsRest ? 1 : 0));
                    $wouldExceedWorkDays = $projectedWorkDays > $effectiveMaxWorkDaysPerWeek;
                    $wouldDropBelowMinRest = $minRestDaysPerWeek > 0 && $projectedRestDays < $minRestDaysPerWeek;
                    $wouldDropBelowMinWork = $existingIsRest && $currentWorkDays <= $effectiveMinWorkDaysPerWeek;
                    if ($isBlockedByRule($uid, $slotDate)) {
                        $skippedByRules++;
                        continue;
                    }
                    $monthHours = (float) ($hoursByUserMonth[$uid][$slotMonth] ?? 0.0);
                    $departmentPriority = $isSameDepartment ? 1 : 0;
                    $violationPenalty = 0;
                    if ($wouldExceedWorkDays) {
                        $violationPenalty += 1000 * ($projectedWorkDays - $effectiveMaxWorkDaysPerWeek);
                    }
                    if ($wouldDropBelowMinRest) {
                        $violationPenalty += 500 * ($minRestDaysPerWeek - $projectedRestDays);
                    }
                    if ($wouldDropBelowMinWork) {
                        $violationPenalty += 250;
                    }
                    if (!$isSameDepartment) {
                        $violationPenalty += 40;
                    }

                    if (
                        $departmentPriority > $candidateDepartmentPriority
                        || ($departmentPriority === $candidateDepartmentPriority && $violationPenalty < $candidatePenalty)
                        || ($departmentPriority === $candidateDepartmentPriority && $violationPenalty === $candidatePenalty && $monthHours < $candidateHours)
                    ) {
                        $candidate = $uid;
                        $candidatePreviousAssignment = !empty($dayBusy[$uid][$slotDate]) ? $existingAssignment : null;
                        $candidateHours = $monthHours;
                        $candidateDepartmentPriority = $departmentPriority;
                        $candidatePenalty = $violationPenalty;
                    }
                }

                // For essential departments, run a relaxed second pass to maximize coverage.
                // Still same-department only; never uses automatic cross-department fallback.
                if (!$candidate && $isEssentialSlot) {
                    foreach ($users as $candidateUser) {
                        $uid = (int) ($candidateUser['id'] ?? 0);
                        if ($uid <= 0) {
                            continue;
                        }
                        $candidateDepartmentIds = $userDepartmentIdsById[$uid] ?? [];
                        if (!in_array($slotDepartmentId, $candidateDepartmentIds, true)) {
                            continue;
                        }

                        $existingAssignment = $assignmentByUserDate[$uid][$slotDate] ?? null;
                        $existingKind = (string) ($existingAssignment['shift_kind'] ?? '');
                        if (in_array($existingKind, ['vacation', 'sick'], true)) {
                            continue;
                        }

                        $ruleReason = $getRuleReasonByDate($uid, $slotDate);
                        if (in_array($ruleReason, ['vacation', 'sick'], true)) {
                            continue;
                        }

                        if (!empty($dayBusy[$uid][$slotDate])) {
                            if (!$allowReassignConflicts || !$existingAssignment || in_array($existingKind, ['vacation', 'sick'], true)) {
                                continue;
                            }
                        }

                        $monthHours = (float) ($hoursByUserMonth[$uid][$slotMonth] ?? 0.0);
                        if ($monthHours < $candidateHours) {
                            $candidate = $uid;
                            $candidatePreviousAssignment = !empty($dayBusy[$uid][$slotDate]) ? $existingAssignment : null;
                            $candidateHours = $monthHours;
                            $candidateDepartmentPriority = 1;
                            $candidatePenalty = min($candidatePenalty, 0);
                        }
                    }
                }

                if ($candidate) {
                    if ($candidateDepartmentPriority === 0) {
                        $crossDepartmentAssignedCount++;
                    }
                    if ($candidatePreviousAssignment && (int) ($candidatePreviousAssignment['assignment_id'] ?? 0) > 0) {
                        $oldAssignmentId = (int) ($candidatePreviousAssignment['assignment_id'] ?? 0);
                        $oldShiftId = (int) ($candidatePreviousAssignment['shift_id'] ?? 0);
                        $oldKind = (string) ($candidatePreviousAssignment['shift_kind'] ?? 'work');
                        $oldWorkDate = (string) ($candidatePreviousAssignment['work_date'] ?? $slotDate);
                        $oldMonth = substr($oldWorkDate, 0, 7);
                        $oldHours = (float) ($candidatePreviousAssignment['hours'] ?? 0.0);
                        $oldGroupKey = $oldShiftId . '|' . $oldWorkDate;
                        $oldWeek = (string) ($candidatePreviousAssignment['week'] ?? date('o-W', strtotime($oldWorkDate)));
                        $oldDepartmentId = (int) ($candidatePreviousAssignment['department_id'] ?? 0);

                        $releaseAssignment->execute(['id' => $oldAssignmentId]);
                        $workAssignmentByUserDate[$candidate][$oldWorkDate] = null;
                        unset($assignmentByUserDate[$candidate][$oldWorkDate]);

                        if ($oldKind === 'work' && isset($coverageByShiftDate[$oldGroupKey])) {
                            $coverageByShiftDate[$oldGroupKey] = max(0, ((int) $coverageByShiftDate[$oldGroupKey]) - 1);
                        }

                        if ($oldKind === 'work' && $oldHours > 0) {
                            $hoursByUserMonth[$candidate][$oldMonth] = max(0.0, (float) ($hoursByUserMonth[$candidate][$oldMonth] ?? 0.0) - $oldHours);
                        }

                        $busyCountByUserDate[$candidate][$oldWorkDate] = max(0, ((int) ($busyCountByUserDate[$candidate][$oldWorkDate] ?? 1)) - 1);
                        if ((int) ($busyCountByUserDate[$candidate][$oldWorkDate] ?? 0) <= 0) {
                            unset($busyCountByUserDate[$candidate][$oldWorkDate]);
                            unset($dayBusy[$candidate][$oldWorkDate]);
                            if ($oldKind === 'work' && isset($workDaysByUserWeek[$candidate][$oldWeek][$oldWorkDate])) {
                                unset($workDaysByUserWeek[$candidate][$oldWeek][$oldWorkDate]);
                            }
                            if ($oldKind === 'rest') {
                                unset($blockedDaysByUserWeek[$candidate][$oldWeek][$oldWorkDate]);
                                unset($restDaysByUserWeek[$candidate][$oldWeek][$oldWorkDate]);
                                if ($oldDepartmentId > 0) {
                                    $oldRestKey = $oldDepartmentId . '|' . $oldWorkDate;
                                    $restAssignedByDepartmentDate[$oldRestKey] = max(0, ((int) ($restAssignedByDepartmentDate[$oldRestKey] ?? 1)) - 1);
                                }
                            }
                        }
                        $reassignedCount++;
                    }

                    $updateAssignment->execute([
                        'user_id' => $candidate,
                        'id' => (int) ($openRow['id'] ?? 0),
                    ]);
                    $hoursByUserMonth[$candidate][$slotMonth] = ($hoursByUserMonth[$candidate][$slotMonth] ?? 0.0) + $slotHours;
                    $dayBusy[$candidate][$slotDate] = true;
                    $busyCountByUserDate[$candidate][$slotDate] = (int) ($busyCountByUserDate[$candidate][$slotDate] ?? 0) + 1;
                    $slotWeek = date('o-W', strtotime($slotDate));
                    $workDaysByUserWeek[$candidate][$slotWeek][$slotDate] = true;
                    $workAssignmentByUserDate[$candidate][$slotDate] = [
                        'assignment_id' => (int) ($openRow['id'] ?? 0),
                        'shift_id' => (int) ($openRow['shift_id'] ?? 0),
                        'department_id' => (int) ($openRow['department_id'] ?? 0),
                        'shift_kind' => 'work',
                        'work_date' => $slotDate,
                        'hours' => $slotHours,
                        'week' => $slotWeek,
                    ];
                    $assignmentByUserDate[$candidate][$slotDate] = $workAssignmentByUserDate[$candidate][$slotDate];
                    $assignedInGroup++;
                    $coverageByShiftDate[$groupKey] = $assignedInGroup;
                    $assignedCount++;
                    if ($isPrioritySlot && isset($priorityOpenSlotsByDate[$slotDate])) {
                        $priorityOpenSlotsByDate[$slotDate] = max(0, (int) $priorityOpenSlotsByDate[$slotDate] - 1);
                    }

                    if ($targetUserId > 0 && $assignmentMode === 'single' && (int) $candidate === $targetUserId) {
                        $assignedForTargetUser = true;
                        break;
                    }
                }
            }

            if ($assignedForTargetUser) {
                break;
            }
        }

        $groupsBelowMin = 0;
        foreach ($coverageByShiftDate as $assignedInGroup) {
            if ((int) $assignedInGroup < $minEmployeesPerShiftDay) {
                $groupsBelowMin++;
            }
        }

        $overworkedUsers = [];
        foreach ($users as $overworkedUserRow) {
            $overworkedUserId = (int) ($overworkedUserRow['id'] ?? 0);
            if ($overworkedUserId <= 0) {
                continue;
            }

            $userWeeks = array_values(array_unique(array_merge(
                array_keys($workDaysByUserWeek[$overworkedUserId] ?? []),
                array_keys($restDaysByUserWeek[$overworkedUserId] ?? [])
            )));

            foreach ($userWeeks as $overworkedWeekKey) {
                $workDays = array_keys($workDaysByUserWeek[$overworkedUserId][$overworkedWeekKey] ?? []);
                $restDays = array_keys($restDaysByUserWeek[$overworkedUserId][$overworkedWeekKey] ?? []);
                $workDaysCount = count($workDays);
                $restDaysCount = count($restDays);
                $isOverworked = $workDaysCount > $effectiveMaxWorkDaysPerWeek
                    || ($minRestDaysPerWeek > 0 && $restDaysCount < $minRestDaysPerWeek);
                if (!$isOverworked || empty($workDays)) {
                    continue;
                }

                $overworkedDepartmentIds = $userDepartmentIdsById[$overworkedUserId] ?? [];
                $replacementSuggestions = [];
                foreach ($users as $replacementUserRow) {
                    $replacementUserId = (int) ($replacementUserRow['id'] ?? 0);
                    if ($replacementUserId <= 0 || $replacementUserId === $overworkedUserId) {
                        continue;
                    }

                    $replacementDepartmentIds = $userDepartmentIdsById[$replacementUserId] ?? [];
                    if (empty($replacementDepartmentIds) || !empty(array_intersect($overworkedDepartmentIds, $replacementDepartmentIds))) {
                        continue;
                    }

                    $replacementWorkCount = count($workDaysByUserWeek[$replacementUserId][$overworkedWeekKey] ?? []);
                    $replacementRestCount = count($restDaysByUserWeek[$replacementUserId][$overworkedWeekKey] ?? []);
                    $replacementProtectedCount = count($protectedAbsenceDaysByUserWeek[$replacementUserId][$overworkedWeekKey] ?? []);
                    $coverableDates = [];
                    foreach ($workDays as $reliefDate) {
                        $replacementExisting = $assignmentByUserDate[$replacementUserId][$reliefDate] ?? null;
                        $replacementExistingKind = strtolower(trim((string) ($replacementExisting['shift_kind'] ?? '')));
                        if (!empty($dayBusy[$replacementUserId][$reliefDate]) || in_array($replacementExistingKind, ['vacation', 'sick', 'rest'], true)) {
                            continue;
                        }
                        if ($isBlockedByRule($replacementUserId, $reliefDate)) {
                            continue;
                        }

                        $projectedReplacementWork = $replacementWorkCount + 1;
                        $projectedReplacementRest = $replacementRestCount;
                        if ($projectedReplacementWork > ($effectiveMaxWorkDaysPerWeek + 1)) {
                            continue;
                        }
                        if ($minRestDaysPerWeek > 0 && (7 - ($projectedReplacementWork + $replacementProtectedCount)) < max(0, $minRestDaysPerWeek - 1)) {
                            continue;
                        }
                        if ($minRestDaysPerWeek > 0 && $projectedReplacementRest < max(0, $minRestDaysPerWeek - 1)) {
                            continue;
                        }

                        $coverableDates[] = $reliefDate;
                    }

                    if (empty($coverableDates)) {
                        continue;
                    }

                    $replacementMonthKey = substr((string) ($coverableDates[0] ?? ''), 0, 7);
                    $replacementSuggestions[] = [
                        'user_id' => $replacementUserId,
                        'name' => (string) ($userMetaById[$replacementUserId]['name'] ?? ('User #' . $replacementUserId)),
                        'department_id' => (int) ($userMetaById[$replacementUserId]['department_id'] ?? 0),
                        'department_name' => (string) ($userMetaById[$replacementUserId]['department_name'] ?? ''),
                        'coverable_days' => count($coverableDates),
                        'dates' => array_slice(array_values($coverableDates), 0, 3),
                        'month_hours' => (float) ($hoursByUserMonth[$replacementUserId][$replacementMonthKey] ?? 0.0),
                    ];
                }

                usort($replacementSuggestions, static function (array $a, array $b): int {
                    if ((int) ($a['coverable_days'] ?? 0) === (int) ($b['coverable_days'] ?? 0)) {
                        if ((float) ($a['month_hours'] ?? 0.0) === (float) ($b['month_hours'] ?? 0.0)) {
                            return ((int) ($a['user_id'] ?? 0)) <=> ((int) ($b['user_id'] ?? 0));
                        }
                        return ((float) ($a['month_hours'] ?? 0.0)) <=> ((float) ($b['month_hours'] ?? 0.0));
                    }
                    return ((int) ($b['coverable_days'] ?? 0)) <=> ((int) ($a['coverable_days'] ?? 0));
                });

                $primaryDepartmentId = (int) ($userMetaById[$overworkedUserId]['department_id'] ?? 0);
                $overworkedUsers[] = [
                    'user_id' => $overworkedUserId,
                    'name' => (string) ($userMetaById[$overworkedUserId]['name'] ?? ('User #' . $overworkedUserId)),
                    'department_id' => $primaryDepartmentId,
                    'department_name' => (string) ($userMetaById[$overworkedUserId]['department_name'] ?? ''),
                    'week' => (string) $overworkedWeekKey,
                    'work_days' => $workDaysCount,
                    'rest_days' => $restDaysCount,
                    'worked_dates' => array_slice(array_values($workDays), 0, 5),
                    'replacement_suggestions' => array_slice($replacementSuggestions, 0, 3),
                ];
            }
        }

        jsonResponse([
            'success' => true,
            'ok' => true,
            'assigned_count' => $assignedCount,
            'reassigned_count' => $reassignedCount,
            'cross_department_assigned_count' => $crossDepartmentAssignedCount,
            'open_remaining' => max(count($openRows) - $assignedCount + $reassignedCount, 0),
            'skipped_by_rules' => $skippedByRules,
            'groups_below_min' => $groupsBelowMin,
            'min_employees_per_shift_day' => $minEmployeesPerShiftDay,
            'max_employees_per_shift_day' => $maxEmployeesPerShiftDay,
            'rest_days_per_week' => $restDaysPerWeek,
            'min_rest_days_per_week' => $restDaysPerWeek,
            'max_rest_days_per_week' => $effectiveMaxRestDaysPerWeek,
            'min_work_days_per_week' => $effectiveMinWorkDaysPerWeek,
            'max_work_days_per_week' => $effectiveMaxWorkDaysPerWeek,
            'rest_distribution_mode' => $restDistributionMode,
            'overworked_users' => $overworkedUsers,
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

        $unassignLookup = $pdo->prepare('SELECT work_date FROM user_shifts WHERE id = :id LIMIT 1');
        $unassignLookup->execute(['id' => $assignmentId]);
        $unassignRow = $unassignLookup->fetch(PDO::FETCH_ASSOC) ?: [];
        $unassignDate = (string) ($unassignRow['work_date'] ?? '');
        if ($isPastWorkDate($unassignDate)) {
            jsonResponse(['success' => false, 'error' => 'Past dates are read-only and cannot be modified.'], 400);
        }

        $update = $pdo->prepare('UPDATE user_shifts SET user_id = NULL, status = "open", updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute(['id' => $assignmentId]);
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