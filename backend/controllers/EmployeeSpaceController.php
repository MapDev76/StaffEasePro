<?php
/**
 * Employee space controller.
 *
 * Displays the personal area for users. Handles
 * attendance signing and personal requests. Attendance signing can be
 * restricted by company Wi-Fi IP when configured by admin/super admin.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/DocumentSigningService.php';

if (!isLoggedIn()) {
    setFlash('error', t('common.login_required'));
    redirectTo('login');
}

$currentUser = currentUser();
$allowedRoles = ['employee', 'admin', 'department_manager'];
if (!in_array((string) ($currentUser['role'] ?? ''), $allowedRoles, true)) {
    setFlash('error', t('common.access_restricted'));
    redirectTo('dashboard');
}

$pdo = getPDO();
ensureDocumentStorageSchema($pdo);
$pageTitle = 'My Staff Space';
$viewFile = __DIR__ . '/../../public/views/employee/space.php';
$error = null;
$todayDate = appNow()->format('Y-m-d');

$normalizeIp = static function (?string $ip): string {
    $raw = trim((string) $ip);
    if ($raw === '') {
        return '';
    }

    $normalized = strtolower($raw);
    if (str_starts_with($normalized, '::ffff:')) {
        return substr($normalized, 7);
    }

    return $normalized;
};

$detectClientIp = static function (): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }

        if (str_contains($candidate, ',')) {
            $parts = array_map('trim', explode(',', $candidate));
            $candidate = (string) ($parts[0] ?? '');
        }

        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
};

$clientIpRaw = $detectClientIp();
$clientIp = $normalizeIp($clientIpRaw);

$hasCompanySignatureIpColumn = false;
try {
    $signatureIpColumnCheck = $pdo->query("SHOW COLUMNS FROM companies LIKE 'signature_ip'");
    $hasCompanySignatureIpColumn = (bool) $signatureIpColumnCheck->fetch();
} catch (Throwable $e) {
    $hasCompanySignatureIpColumn = false;
}

$companySignatureIpSelect = $hasCompanySignatureIpColumn ? 'c.signature_ip' : 'NULL AS signature_ip';

$signaturePolicyStatement = $pdo->prepare(
    'SELECT ' . $companySignatureIpSelect . ',
            u.department_id,
            c.id AS company_id,
            d.name AS department_name,
            c.name AS company_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN companies c ON c.id = d.company_id
     WHERE u.id = :user_id
     LIMIT 1'
);
$signaturePolicyStatement->execute(['user_id' => (int) $currentUser['id']]);
$profileRow = $signaturePolicyStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$requiredSignatureIpRaw = (string) ($profileRow['signature_ip'] ?? '');
$requiredSignatureIp = $normalizeIp($requiredSignatureIpRaw);
$isSignatureIpRestricted = $requiredSignatureIp !== '';
$isCurrentNetworkAuthorized = !$isSignatureIpRestricted || ($clientIp !== '' && $clientIp === $requiredSignatureIp);

$employeeDisplayName = trim((string) (($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')));
if ($employeeDisplayName === '') {
    $employeeDisplayName = (string) ($currentUser['email'] ?? 'Employee');
}
$employeeDepartmentName = trim((string) ($profileRow['department_name'] ?? ''));
$employeeCompanyName = trim((string) ($profileRow['company_name'] ?? 'StaffEase Pro'));
$employeeCompanyId = (int) ($profileRow['company_id'] ?? 0);
$employeeDepartmentId = (int) ($profileRow['department_id'] ?? 0);
$currentRole = (string) ($currentUser['role'] ?? 'employee');

$documentShareRecipients = [];
try {
    $recipientSql =
        'SELECT u.id,
                u.role,
                CONCAT(u.first_name, " ", u.last_name) AS full_name,
                                d.name AS department_name,
                d.company_id
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.status = "active"
           AND u.id <> :current_user_id';

    $recipientParams = ['current_user_id' => (int) $currentUser['id']];

    if ($employeeCompanyId > 0) {
        $recipientSql .= in_array($currentRole, ['admin', 'department_manager'], true)
            ? ' AND (d.company_id = :company_id OR u.role = "super_admin")'
            : ' AND d.company_id = :company_id';
        $recipientParams['company_id'] = $employeeCompanyId;
    }

    if ($currentRole === 'employee') {
        $recipientSql .= ' AND (u.role = "admin" OR u.role = "department_manager")';
    } elseif ($currentRole === 'department_manager') {
        $recipientSql .= ' AND (u.role IN ("employee", "admin") OR (u.role = "department_manager" AND u.department_id <> :manager_department_id) OR u.role = "super_admin")';
        $recipientParams['manager_department_id'] = $employeeDepartmentId;
    } elseif ($currentRole === 'admin') {
        $recipientSql .= ' AND (u.role IN ("employee", "department_manager", "admin") OR u.role = "super_admin")';
    }

    $recipientSql .= ' ORDER BY FIELD(u.role, "employee", "department_manager", "admin", "super_admin"), full_name ASC';

    $recipientStmt = $pdo->prepare($recipientSql);
    $recipientStmt->execute($recipientParams);
    $documentShareRecipients = $recipientStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $documentShareRecipients = [];
}

$shiftsStatement = $pdo->prepare(
    'SELECT us.id,
            us.work_date,
            us.status AS assignment_status,
            s.name AS shift_name,
            s.kind AS shift_kind,
            s.icon AS shift_icon,
            s.color AS shift_color,
            s.start_time,
            s.end_time,
            d.name AS department_name,
            a.id AS attendance_id,
            a.status AS attendance_status,
            a.check_in_time,
            a.check_out_time
     FROM user_shifts us
     INNER JOIN shifts s ON s.id = us.shift_id
     INNER JOIN departments d ON d.id = s.department_id
     LEFT JOIN attendances a ON a.user_shift_id = us.id AND a.user_id = us.user_id
     WHERE us.user_id = :user_id
     ORDER BY us.work_date DESC, s.start_time ASC, us.id DESC'
);
$shiftsStatement->execute(['user_id' => $currentUser['id']]);
$shifts = $shiftsStatement->fetchAll();

$attendancesStatement = $pdo->prepare(
    'SELECT a.id, a.work_date, a.status, a.check_in_time, a.check_out_time, s.name AS shift_name
     FROM attendances a
     LEFT JOIN user_shifts us ON us.id = a.user_shift_id
     LEFT JOIN shifts s ON s.id = us.shift_id
     WHERE a.user_id = :user_id
     ORDER BY a.work_date DESC, a.id DESC'
);
$attendancesStatement->execute(['user_id' => $currentUser['id']]);
$attendances = $attendancesStatement->fetchAll();

$incomingDocumentsStatement = $pdo->prepare(
    'SELECT r.id AS request_id,
                        r.title,
                        r.message,
                        r.type,
                        r.status,
            r.user_id AS sender_id,
            r.recipient_id,
                        r.created_at,
                        d.id AS document_id,
                        d.file_name,
            d.file_path,
        d.file_blob,
        d.file_mime_type,
            (d.file_blob IS NOT NULL) AS has_db_content,
                        d.upload_date,
                        CONCAT(sender.first_name, " ", sender.last_name) AS sender_name
         FROM requests r
         INNER JOIN documents d ON d.id = r.document_id
         INNER JOIN users sender ON sender.id = r.user_id
         WHERE r.recipient_id = :user_id
             AND r.document_id IS NOT NULL
             AND r.type IN ("notification", "document_signature")
             AND COALESCE(r.status, "") NOT IN ("cancelled", "archived")
         ORDER BY r.created_at DESC, r.id DESC'
);
$incomingDocumentsStatement->execute(['user_id' => (int) $currentUser['id']]);
$incomingDocuments = $incomingDocumentsStatement->fetchAll(PDO::FETCH_ASSOC);

$archivedIncomingDocumentsStatement = $pdo->prepare(
    'SELECT r.id AS request_id,
                        r.title,
                        r.message,
                        r.type,
                        r.status,
            r.user_id AS sender_id,
            r.recipient_id,
                        r.created_at,
                        d.id AS document_id,
                        d.file_name,
            d.file_path,
        d.file_blob,
        d.file_mime_type,
            (d.file_blob IS NOT NULL) AS has_db_content,
                        d.upload_date,
                        CONCAT(sender.first_name, " ", sender.last_name) AS sender_name
         FROM requests r
         INNER JOIN documents d ON d.id = r.document_id
         INNER JOIN users sender ON sender.id = r.user_id
         WHERE r.recipient_id = :user_id
             AND r.document_id IS NOT NULL
             AND r.type IN ("notification", "document_signature")
             AND COALESCE(r.status, "") IN ("cancelled", "archived")
         ORDER BY r.updated_at DESC, r.id DESC'
);
$archivedIncomingDocumentsStatement->execute(['user_id' => (int) $currentUser['id']]);
$archivedIncomingDocuments = $archivedIncomingDocumentsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$archivedOutgoingDocumentsStatement = $pdo->prepare(
    'SELECT r.id AS request_id,
                    r.title,
                    r.message,
                    r.type,
                    r.status,
                    r.user_id AS sender_id,
                    r.recipient_id,
                    r.created_at,
                    d.id AS document_id,
                    d.file_name,
                    CONCAT(recipient.first_name, " ", recipient.last_name) AS recipient_name
     FROM requests r
     INNER JOIN documents d ON d.id = r.document_id
     INNER JOIN users recipient ON recipient.id = r.recipient_id
     WHERE r.user_id = :user_id
         AND r.document_id IS NOT NULL
         AND r.type IN ("notification", "document_signature")
         AND COALESCE(r.status, "") IN ("cancelled", "archived")
     ORDER BY r.updated_at DESC, r.id DESC'
);
$archivedOutgoingDocumentsStatement->execute(['user_id' => (int) $currentUser['id']]);
$archivedOutgoingRows = $archivedOutgoingDocumentsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$archivedOutgoingGroupedByDocument = [];
foreach ($archivedOutgoingRows as $row) {
    $documentId = (int) ($row['document_id'] ?? 0);
    if ($documentId <= 0) {
        continue;
    }

    if (!isset($archivedOutgoingGroupedByDocument[$documentId])) {
        $archivedOutgoingGroupedByDocument[$documentId] = $row;
        $archivedOutgoingGroupedByDocument[$documentId]['recipient_names'] = [];
    }

    $recipientName = trim((string) ($row['recipient_name'] ?? ''));
    if ($recipientName !== '') {
        $archivedOutgoingGroupedByDocument[$documentId]['recipient_names'][] = $recipientName;
    }

    $existingCreatedAt = strtotime((string) ($archivedOutgoingGroupedByDocument[$documentId]['created_at'] ?? '')) ?: 0;
    $candidateCreatedAt = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
    if ($candidateCreatedAt > $existingCreatedAt) {
        $archivedOutgoingGroupedByDocument[$documentId]['created_at'] = $row['created_at'] ?? $archivedOutgoingGroupedByDocument[$documentId]['created_at'];
    }
}

$archivedOutgoingDocuments = [];
foreach ($archivedOutgoingGroupedByDocument as $groupedDocument) {
    $recipientNames = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $groupedDocument['recipient_names'] ?? []
    ))));
    $recipientCount = count($recipientNames);

    $groupedDocument['recipient_count'] = $recipientCount;
    $groupedDocument['recipient_name'] = $recipientCount > 0
        ? ($recipientCount > 1
            ? ($recipientNames[0] . ' +' . ($recipientCount - 1))
            : $recipientNames[0])
        : '-';
    $groupedDocument['recipient_names_label'] = $recipientCount > 0 ? implode(', ', $recipientNames) : '-';

    $archivedOutgoingDocuments[] = $groupedDocument;
}

usort($archivedOutgoingDocuments, static function (array $left, array $right): int {
    $leftStamp = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
    $rightStamp = strtotime((string) ($right['created_at'] ?? '')) ?: 0;
    return $rightStamp <=> $leftStamp;
});

// Signed-return notifications should be treated as already reviewed in sender's sent list.
$normalizeSignedOutgoingStatement = $pdo->prepare(
        'UPDATE requests
         SET status = "read",
                 updated_at = CURRENT_TIMESTAMP
         WHERE user_id = :user_id
             AND type = "notification"
             AND status IN ("pending", "unread")
             AND (
                 LOWER(COALESCE(title, "")) LIKE "%signed document received%"
                 OR LOWER(COALESCE(title, "")) LIKE "%document signe recu%"
             )'
);
$normalizeSignedOutgoingStatement->execute(['user_id' => (int) $currentUser['id']]);

$outgoingDocumentsStatement = $pdo->prepare(
        'SELECT r.id AS request_id,
                        r.title,
                        r.message,
                        r.type,
                        r.status,
                        r.user_id AS sender_id,
                        r.recipient_id,
                        r.created_at,
                        d.id AS document_id,
                        d.file_name,
                        d.file_path,
                        d.file_blob,
                        d.file_mime_type,
                        (d.file_blob IS NOT NULL) AS has_db_content,
                        d.upload_date,
                        CONCAT(recipient.first_name, " ", recipient.last_name) AS recipient_name
         FROM requests r
         INNER JOIN documents d ON d.id = r.document_id
         INNER JOIN users recipient ON recipient.id = r.recipient_id
         WHERE r.user_id = :user_id
             AND r.document_id IS NOT NULL
             AND r.type IN ("notification", "document_signature")
             AND COALESCE(r.status, "") NOT IN ("cancelled", "archived")
         ORDER BY r.created_at DESC, r.id DESC'
);
$outgoingDocumentsStatement->execute(['user_id' => (int) $currentUser['id']]);
$outgoingDocumentRows = $outgoingDocumentsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$outgoingGroupedByDocument = [];
foreach ($outgoingDocumentRows as $row) {
    $documentId = (int) ($row['document_id'] ?? 0);
    if ($documentId <= 0) {
        continue;
    }

    if (!isset($outgoingGroupedByDocument[$documentId])) {
        $outgoingGroupedByDocument[$documentId] = $row;
        $outgoingGroupedByDocument[$documentId]['recipient_names'] = [];
        $outgoingGroupedByDocument[$documentId]['request_ids'] = [];
        $outgoingGroupedByDocument[$documentId]['status_list'] = [];
    }

    $recipientName = trim((string) ($row['recipient_name'] ?? ''));
    if ($recipientName !== '') {
        $outgoingGroupedByDocument[$documentId]['recipient_names'][] = $recipientName;
    }

    $requestId = (int) ($row['request_id'] ?? 0);
    if ($requestId > 0) {
        $outgoingGroupedByDocument[$documentId]['request_ids'][] = $requestId;
    }

    $statusValue = strtolower(trim((string) ($row['status'] ?? '')));
    if ($statusValue !== '') {
        $outgoingGroupedByDocument[$documentId]['status_list'][] = $statusValue;
    }

    $existingCreatedAt = strtotime((string) ($outgoingGroupedByDocument[$documentId]['created_at'] ?? '')) ?: 0;
    $candidateCreatedAt = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
    if ($candidateCreatedAt > $existingCreatedAt) {
        $outgoingGroupedByDocument[$documentId]['created_at'] = $row['created_at'] ?? $outgoingGroupedByDocument[$documentId]['created_at'];
    }
}

$outgoingDocuments = [];
foreach ($outgoingGroupedByDocument as $groupedDocument) {
    $recipientNames = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $groupedDocument['recipient_names'] ?? []
    ))));
    $recipientCount = count($recipientNames);

    $groupedDocument['recipient_count'] = $recipientCount;
    $groupedDocument['recipient_name'] = $recipientCount > 0
        ? ($recipientCount > 1
            ? ($recipientNames[0] . ' +' . ($recipientCount - 1))
            : $recipientNames[0])
        : '-';
    $groupedDocument['recipient_names_label'] = $recipientCount > 0 ? implode(', ', $recipientNames) : '-';

    $statusList = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => strtolower(trim((string) $value)),
        $groupedDocument['status_list'] ?? []
    ))));

    if (in_array('approved', $statusList, true)) {
        $groupedDocument['status_display'] = 'approved';
    } elseif (in_array('read', $statusList, true)) {
        $groupedDocument['status_display'] = 'read';
    } elseif (in_array('unread', $statusList, true)) {
        $groupedDocument['status_display'] = 'unread';
    } elseif (in_array('pending', $statusList, true)) {
        $groupedDocument['status_display'] = 'pending';
    } else {
        $groupedDocument['status_display'] = strtolower(trim((string) ($groupedDocument['status'] ?? 'pending')));
    }

    $outgoingDocuments[] = $groupedDocument;
}

usort($outgoingDocuments, static function (array $left, array $right): int {
    $leftStamp = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
    $rightStamp = strtotime((string) ($right['created_at'] ?? '')) ?: 0;
    return $rightStamp <=> $leftStamp;
});

$resolveStoredDocumentPath = static function (array $row): ?string {
    $filePath = trim((string) ($row['file_path'] ?? ''));
    if ($filePath === '') {
        return null;
    }

    $candidates = [
        $filePath,
        __DIR__ . '/../../' . ltrim($filePath, '/'),
        __DIR__ . '/../../public/' . ltrim($filePath, '/'),
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
};

foreach ($incomingDocuments as &$incomingDocument) {
    $hasDbContent = !empty($incomingDocument['has_db_content']);
    $incomingDocument['is_download_available'] = $hasDbContent || ($resolveStoredDocumentPath($incomingDocument) !== null);
    $incomingStatus = (string) ($incomingDocument['status'] ?? '');
    $incomingDocument['can_sign'] = !empty($incomingDocument['is_download_available'])
        && in_array($incomingStatus, ['pending', 'unread', 'read'], true)
        && (int) ($incomingDocument['recipient_id'] ?? 0) === (int) ($currentUser['id'] ?? 0);
    $incomingDocument['can_archive'] = in_array($incomingStatus, ['read', 'approved'], true);
    $incomingDocument['is_new'] = in_array($incomingStatus, ['pending', 'unread'], true);
    $incomingDocument['is_signed_notification'] = (string) ($incomingDocument['type'] ?? '') === 'notification'
        && str_contains(strtolower((string) ($incomingDocument['title'] ?? '')), 'signed');
}
unset($incomingDocument);

foreach ($outgoingDocuments as &$outgoingDocument) {
    $hasDbContent = !empty($outgoingDocument['has_db_content']);
    $outgoingDocument['is_download_available'] = $hasDbContent || ($resolveStoredDocumentPath($outgoingDocument) !== null);
    $outgoingStatus = strtolower(trim((string) ($outgoingDocument['status_display'] ?? $outgoingDocument['status'] ?? '')));
    $outgoingTitle = strtolower(trim((string) ($outgoingDocument['title'] ?? '')));
    $outgoingDocument['is_signed_notification'] = str_contains($outgoingTitle, 'signed document received')
        || str_contains($outgoingTitle, 'document signe recu');
    $outgoingDocument['is_signed_by_recipient'] = (string) ($outgoingDocument['status'] ?? '') === 'approved'
        || !empty($outgoingDocument['is_signed_notification']);
    $outgoingDocument['status_display'] = !empty($outgoingDocument['is_signed_by_recipient']) ? 'approved' : $outgoingStatus;
    $outgoingDocument['can_archive'] = in_array($outgoingDocument['status_display'], ['pending', 'unread', 'read', 'approved'], true);
}
unset($outgoingDocument);

$archivedDocuments = [];
foreach ($archivedIncomingDocuments as $archivedIncomingDocument) {
    $archivedDocuments[] = [
        'archive_scope' => 'incoming',
        'request_id' => (int) ($archivedIncomingDocument['request_id'] ?? 0),
        'document_id' => (int) ($archivedIncomingDocument['document_id'] ?? 0),
        'title' => (string) ($archivedIncomingDocument['title'] ?? $archivedIncomingDocument['file_name'] ?? t('employee.document_notification')),
        'description' => trim((string) ($archivedIncomingDocument['sender_name'] ?? '-')) . ' • ' . (string) ($archivedIncomingDocument['created_at'] ?? ''),
        'created_at' => (string) ($archivedIncomingDocument['created_at'] ?? ''),
    ];
}

foreach ($archivedOutgoingDocuments as $archivedOutgoingDocument) {
    $archivedDocuments[] = [
        'archive_scope' => 'outgoing',
        'request_id' => 0,
        'document_id' => (int) ($archivedOutgoingDocument['document_id'] ?? 0),
        'title' => (string) ($archivedOutgoingDocument['title'] ?? $archivedOutgoingDocument['file_name'] ?? t('employee.document_notification')),
        'description' => (string) ($archivedOutgoingDocument['recipient_name'] ?? '-') . ' • ' . (string) ($archivedOutgoingDocument['created_at'] ?? ''),
        'created_at' => (string) ($archivedOutgoingDocument['created_at'] ?? ''),
    ];
}

usort($archivedDocuments, static function (array $left, array $right): int {
    $leftStamp = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
    $rightStamp = strtotime((string) ($right['created_at'] ?? '')) ?: 0;
    return $rightStamp <=> $leftStamp;
});

$unreadDocumentsCount = 0;
foreach ($incomingDocuments as $incomingDocument) {
    if (!empty($incomingDocument['is_new'])) {
        $unreadDocumentsCount++;
    }
}

$buildShiftDateTime = static function (string $workDate, ?string $timeValue) use ($todayDate): ?DateTimeImmutable {
    $normalizedTime = trim((string) $timeValue);
    if ($workDate === '' || $normalizedTime === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($workDate . ' ' . $normalizedTime);
    } catch (Throwable $e) {
        return null;
    }
};

$now = appNow();
$todaySignableShifts = [];
$todayTimelineShifts = [];
$upcomingShifts = [];
$currentShiftCard = null;

foreach ($shifts as &$shift) {
    $workDate = (string) ($shift['work_date'] ?? '');
    $assignmentStatus = (string) ($shift['assignment_status'] ?? 'assigned');
    $shiftKind = strtolower(trim((string) ($shift['shift_kind'] ?? 'work')));
    if (!in_array($shiftKind, ['work', 'rest', 'vacation', 'sick', 'overtime'], true)) {
        $shiftKind = 'work';
    }
    $shiftColor = trim((string) ($shift['shift_color'] ?? ''));
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $shiftColor)) {
        $shiftColor = '#b58e14';
    }
    $isWorkShift = $shiftKind === 'work' || $shiftKind === 'overtime';
    $startAt = $buildShiftDateTime($workDate, (string) ($shift['start_time'] ?? ''));
    $endAt = $buildShiftDateTime($workDate, (string) ($shift['end_time'] ?? ''));
    if ($startAt !== null && $endAt !== null && $endAt <= $startAt) {
        $endAt = $endAt->modify('+1 day');
    }

    $opensAt = $startAt?->modify('-5 minutes');
    $hasAttendance = (int) ($shift['attendance_id'] ?? 0) > 0 && trim((string) ($shift['check_in_time'] ?? '')) !== '';
    $isCancelled = $assignmentStatus === 'cancelled';
    $isSignWindowOpen = $isWorkShift
        && !$isCancelled
        && !$hasAttendance
        && $opensAt !== null
        && $endAt !== null
        && $now >= $opensAt
        && $now <= $endAt;
    $isBeforeWindow = $isWorkShift && !$isCancelled && !$hasAttendance && $opensAt !== null && $now < $opensAt;
    $isPastWindow = $isWorkShift && !$isCancelled && !$hasAttendance && $endAt !== null && $now > $endAt;

    $minutesUntilOpen = null;
    if ($isBeforeWindow && $opensAt !== null) {
        $minutesUntilOpen = max(0, (int) ceil(($opensAt->getTimestamp() - $now->getTimestamp()) / 60));
    }

    $shift['status'] = $assignmentStatus;
    $shift['shift_kind'] = $shiftKind;
    $shift['shift_color'] = $shiftColor;
    $shift['attendance_recorded'] = $hasAttendance;
    $shift['attendance_label'] = $hasAttendance ? 'Attendance already recorded' : ($shift['attendance_status'] ?? '');
    $shift['starts_at_iso'] = $startAt?->format(DateTimeInterface::ATOM) ?? null;
    $shift['ends_at_iso'] = $endAt?->format(DateTimeInterface::ATOM) ?? null;
    $shift['sign_open_at_iso'] = $opensAt?->format(DateTimeInterface::ATOM) ?? null;
    $shift['is_sign_window_open'] = $isSignWindowOpen;
    $shift['is_before_window'] = $isBeforeWindow;
    $shift['is_past_window'] = $isPastWindow;
    $shift['minutes_until_open'] = $minutesUntilOpen;

    if ($workDate >= $todayDate && !$isCancelled) {
        $upcomingShifts[] = $shift;
    }

    if ($workDate === $todayDate && in_array($assignmentStatus, ['assigned', 'in_progress', 'completed'], true)) {
        $todayTimelineShifts[] = $shift;
        if ($isWorkShift && $isSignWindowOpen) {
            $todaySignableShifts[] = $shift;
            if ($currentShiftCard === null) {
                $currentShiftCard = $shift;
            }
        } elseif ($currentShiftCard === null && ($startAt !== null && $endAt !== null) && $now <= $endAt) {
            $currentShiftCard = $shift;
        }
    }
}
unset($shift);

usort($upcomingShifts, static function (array $left, array $right): int {
    $leftStamp = strtotime((string) ($left['work_date'] ?? '') . ' ' . (string) ($left['start_time'] ?? '00:00:00')) ?: 0;
    $rightStamp = strtotime((string) ($right['work_date'] ?? '') . ' ' . (string) ($right['start_time'] ?? '00:00:00')) ?: 0;
    return $leftStamp <=> $rightStamp;
});

$upcomingShifts = array_slice($upcomingShifts, 0, 6);

if ($currentShiftCard === null && !empty($todayTimelineShifts)) {
    $currentShiftCard = $todayTimelineShifts[0];
}

$employeeUiState = [
    'server_time' => $now->format(DateTimeInterface::ATOM),
    'can_sign_now' => !empty($todaySignableShifts) && $isCurrentNetworkAuthorized,
    'has_shift_today' => !empty($todayTimelineShifts),
    'unread_documents_count' => $unreadDocumentsCount,
    'share_document_id' => 0,
    'share_document_name' => '',
];

$requestedShareDocumentId = max(0, (int) ($_GET['share_document_id'] ?? 0));
if ($requestedShareDocumentId > 0) {
    $shareDocLookup = $pdo->prepare(
        'SELECT id, file_name
         FROM documents
         WHERE id = :document_id
           AND user_id = :user_id
           AND COALESCE(status, "valid") <> "archived"
         LIMIT 1'
    );
    $shareDocLookup->execute([
        'document_id' => $requestedShareDocumentId,
        'user_id' => (int) $currentUser['id'],
    ]);
    $shareDocumentRow = $shareDocLookup->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($shareDocumentRow) {
        $employeeUiState['share_document_id'] = (int) ($shareDocumentRow['id'] ?? 0);
        $employeeUiState['share_document_name'] = (string) ($shareDocumentRow['file_name'] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectToDocumentsModal = static function (array $extra = []): void {
        $params = array_merge([
            'focus' => 'employee-received-documents',
            'modal' => 'documents',
        ], $extra);
        redirectTo('my-space', $params);
    };

    $action = $_POST['action'] ?? '';

    if ($action === 'archive_received_document') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        if ($requestId <= 0) {
            setFlash('error', t('employee.select_valid_document', ['fallback' => 'Please select a valid document.']));
            $redirectToDocumentsModal();
        }

        $archiveStatement = $pdo->prepare(
            'UPDATE requests
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND recipient_id = :recipient_id
               AND type IN ("notification", "document_signature")
               AND status IN ("read", "approved")
             LIMIT 1'
        );
        $archiveStatement->execute([
            'status' => 'cancelled',
            'id' => $requestId,
            'recipient_id' => (int) $currentUser['id'],
        ]);

        if ($archiveStatement->rowCount() > 0) {
            setFlash('success', t('employee.document_archived', ['fallback' => 'Document archived.']));
        } else {
            setFlash('error', t('employee.archive_requires_read', ['fallback' => 'You can archive only after reading or signing this document.']));
        }
        $redirectToDocumentsModal();
    }

    if ($action === 'mark_document_read') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        if ($requestId <= 0) {
            setFlash('error', t('employee.select_valid_document', ['fallback' => 'Please select a valid document.']));
            $redirectToDocumentsModal();
        }

        $wasUpdated = markRequestReadAndNotifySender($pdo, $requestId, (int) $currentUser['id'], $currentUser);
        if ($wasUpdated) {
            setFlash('success', t('employee.document_marked_read', ['fallback' => 'Document marked as read.']));
        }

        $redirectToDocumentsModal();
    }

    if ($action === 'restore_archived_document') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        if ($requestId <= 0) {
            setFlash('error', t('employee.select_valid_document', ['fallback' => 'Please select a valid document.']));
            $redirectToDocumentsModal();
        }

        $restoreStatement = $pdo->prepare(
            'UPDATE requests
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND recipient_id = :recipient_id
               AND type IN ("notification", "document_signature")
             LIMIT 1'
        );
        $restoreStatement->execute([
            'status' => 'read',
            'id' => $requestId,
            'recipient_id' => (int) $currentUser['id'],
        ]);

        if ($restoreStatement->rowCount() > 0) {
            setFlash('success', t('employee.document_restored', ['fallback' => 'Document restored.']));
        } else {
            setFlash('error', t('common.unauthorized'));
        }
        $redirectToDocumentsModal();
    }

    if ($action === 'delete_archived_document') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        if ($requestId <= 0) {
            setFlash('error', t('employee.select_valid_document', ['fallback' => 'Please select a valid document.']));
            $redirectToDocumentsModal();
        }

        $deleteStatement = $pdo->prepare(
            'DELETE FROM requests
             WHERE id = :id
               AND recipient_id = :recipient_id
               AND type IN ("notification", "document_signature")
               AND COALESCE(status, "") IN ("cancelled", "archived")
             LIMIT 1'
        );
        $deleteStatement->execute([
            'id' => $requestId,
            'recipient_id' => (int) $currentUser['id'],
        ]);

        if ($deleteStatement->rowCount() > 0) {
            setFlash('success', t('employee.document_deleted', ['fallback' => 'Document removed from archived list.']));
        } else {
            setFlash('error', t('common.unauthorized'));
        }

        $redirectToDocumentsModal();
    }

    if ($action === 'archive_outgoing_document') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        if ($documentId <= 0) {
            setFlash('error', t('employee.select_valid_document', ['fallback' => 'Please select a valid document.']));
            $redirectToDocumentsModal();
        }

        $archiveOutgoingStatement = $pdo->prepare(
            'UPDATE requests
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
               AND document_id = :document_id
               AND type IN ("notification", "document_signature")
               AND COALESCE(status, "") NOT IN ("cancelled", "archived")'
        );
        $archiveOutgoingStatement->execute([
            'status' => 'cancelled',
            'user_id' => (int) $currentUser['id'],
            'document_id' => $documentId,
        ]);

        if ($archiveOutgoingStatement->rowCount() > 0) {
            setFlash('success', t('employee.document_archived', ['fallback' => 'Document archived.']));
        } else {
            setFlash('error', t('common.unauthorized'));
        }

        $redirectToDocumentsModal();
    }

    if ($action === 'restore_archived_outgoing_document') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        if ($documentId <= 0) {
            setFlash('error', t('employee.select_valid_document', ['fallback' => 'Please select a valid document.']));
            $redirectToDocumentsModal();
        }

        $restoreOutgoingStatement = $pdo->prepare(
            'UPDATE requests
             SET status = CASE
                              WHEN type = "document_signature" THEN "pending"
                              ELSE "unread"
                          END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
               AND document_id = :document_id
               AND type IN ("notification", "document_signature")
               AND COALESCE(status, "") IN ("cancelled", "archived")'
        );
        $restoreOutgoingStatement->execute([
            'user_id' => (int) $currentUser['id'],
            'document_id' => $documentId,
        ]);

        if ($restoreOutgoingStatement->rowCount() > 0) {
            setFlash('success', t('employee.document_restored', ['fallback' => 'Document restored.']));
        } else {
            setFlash('error', t('common.unauthorized'));
        }

        $redirectToDocumentsModal();
    }

    if ($action === 'delete_outgoing_document') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        if ($documentId <= 0) {
            setFlash('error', t('employee.select_valid_document', ['fallback' => 'Please select a valid document.']));
            $redirectToDocumentsModal();
        }

        $deleteOutgoingStatement = $pdo->prepare(
            'DELETE FROM requests
             WHERE user_id = :user_id
               AND document_id = :document_id
               AND type IN ("notification", "document_signature")'
        );
        $deleteOutgoingStatement->execute([
            'user_id' => (int) $currentUser['id'],
            'document_id' => $documentId,
        ]);

        if ($deleteOutgoingStatement->rowCount() > 0) {
            setFlash('success', t('employee.document_deleted', ['fallback' => 'Document deleted.']));
        } else {
            setFlash('error', t('common.unauthorized'));
        }

        $redirectToDocumentsModal();
    }

    if ($action === 'delete_document_entry') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $scope = strtolower(trim((string) ($_POST['scope'] ?? 'incoming')));
        if ($requestId <= 0) {
            setFlash('error', t('employee.select_valid_document', ['fallback' => 'Please select a valid document.']));
            $redirectToDocumentsModal();
        }

        $deleteIncoming = $pdo->prepare(
            'DELETE FROM requests
             WHERE id = :id
               AND recipient_id = :user_id
               AND type IN ("notification", "document_signature")
             LIMIT 1'
        );
        $deleteOutgoing = $pdo->prepare(
            'DELETE FROM requests
             WHERE id = :id
               AND user_id = :user_id
               AND type IN ("notification", "document_signature")
             LIMIT 1'
        );

        if ($scope === 'outgoing') {
            $deleteOutgoing->execute([
                'id' => $requestId,
                'user_id' => (int) $currentUser['id'],
            ]);
            $deletedRows = $deleteOutgoing->rowCount();
        } else {
            $deleteIncoming->execute([
                'id' => $requestId,
                'user_id' => (int) $currentUser['id'],
            ]);
            $deletedRows = $deleteIncoming->rowCount();
        }

        if ($deletedRows > 0) {
            setFlash('success', t('employee.document_deleted', ['fallback' => 'Document deleted.']));
        } else {
            setFlash('error', t('common.unauthorized'));
        }

        $redirectToDocumentsModal();
    }

    if ($action === 'share_document_no_signature') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $requestType = strtolower(trim((string) ($_POST['request_type'] ?? 'notification')));
        $requireSignature = false;
        $shareNow = true;
        $recipientScope = 'selected';
        $selectedDocumentId = (int) ($_POST['document_id'] ?? 0);
        $recipientIdsRaw = $_POST['recipient_ids'] ?? [];
        $recipientIds = array_values(array_filter(array_map('intval', is_array($recipientIdsRaw) ? $recipientIdsRaw : [$recipientIdsRaw])));

        if (!in_array($requestType, ['notification', 'leave', 'sick'], true)) {
            $requestType = 'notification';
        }
        $requiresDocument = $requestType === 'notification';

        $file = $_FILES['document_file'] ?? null;
        $fileName = trim((string) ($file['name'] ?? ''));
        $tmpPath = trim((string) ($file['tmp_name'] ?? ''));
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $fileSize = (int) ($file['size'] ?? 0);

        $hasUpload = $uploadError !== UPLOAD_ERR_NO_FILE;
        if ($selectedDocumentId > 0 && $hasUpload) {
            setFlash('error', t('employee.upload_choose_existing_or_new', ['fallback' => 'Choose either an existing document or a new upload.']));
            redirectTo('my-space');
        }

        if ($requiresDocument && $selectedDocumentId <= 0) {
            if ($uploadError !== UPLOAD_ERR_OK || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
                setFlash('error', t('employee.upload_missing', ['fallback' => 'Please choose a valid document to upload.']));
                redirectTo('my-space');
            }
            if ($fileSize <= 0 || $fileSize > (8 * 1024 * 1024)) {
                setFlash('error', t('employee.upload_too_large', ['fallback' => 'Document size must be between 1 byte and 8 MB.']));
                redirectTo('my-space');
            }
        }

        $safeBaseName = '';
        $payload = null;
        $detectedMime = 'application/octet-stream';
        if ($requiresDocument && $selectedDocumentId <= 0) {
            $safeBaseName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $fileName ?: 'document');
            $safeBaseName = trim((string) $safeBaseName, '-.');
            if ($safeBaseName === '') {
                $safeBaseName = 'document-' . appNow()->format('Ymd-His');
            }
            if (strlen($safeBaseName) > 180) {
                $safeBaseName = substr($safeBaseName, 0, 180);
            }

            $payload = @file_get_contents($tmpPath);
            if (!is_string($payload) || $payload === '') {
                setFlash('error', t('employee.upload_read_error', ['fallback' => 'Unable to read the uploaded document.']));
                redirectTo('my-space');
            }

            if (function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mimeProbe = @finfo_file($finfo, $tmpPath);
                    if (is_string($mimeProbe) && trim($mimeProbe) !== '') {
                        $detectedMime = trim($mimeProbe);
                    }
                    @finfo_close($finfo);
                }
            }
        }

        $allowedRecipientSet = [];
        foreach ($documentShareRecipients as $recipientRow) {
            $rid = (int) ($recipientRow['id'] ?? 0);
            if ($rid > 0) {
                $allowedRecipientSet[$rid] = true;
            }
        }

        if (!empty($recipientIds)) {
            $recipientIds = array_values(array_filter($recipientIds, static fn (int $id): bool => isset($allowedRecipientSet[$id])));
        }
        if (empty($recipientIds)) {
            $recipientIds = array_map('intval', array_keys($allowedRecipientSet));
        }

        if (empty($recipientIds)) {
            setFlash('error', t('employee.no_recipient_available', ['fallback' => 'No recipient is available for document sharing.']));
            redirectTo('my-space');
        }

        if ($title === '') {
            $title = match ($requestType) {
                'leave' => 'Richiesta congedo',
                'sick' => 'Richiesta malattia',
                default => 'Document shared by employee',
            };
        }
        if ($message === '') {
            $message = match ($requestType) {
                'leave' => 'Invio richiesta di congedo. Resto in attesa di approvazione.',
                'sick' => 'Invio richiesta di malattia con eventuale certificato allegato.',
                default => 'Please review the attached document.',
            };
        }

        $insertRequest = $pdo->prepare(
            'INSERT INTO requests (user_id, recipient_id, type, title, message, status, document_id)
             VALUES (:user_id, :recipient_id, :type, :title, :message, :status, :document_id)'
        );

        $insertDocument = $pdo->prepare(
            'INSERT INTO documents (user_id, document_type, file_name, file_path, file_blob, file_mime_type, status)
             VALUES (:user_id, :document_type, :file_name, :file_path, :file_blob, :file_mime_type, :status)'
        );

                $lookupOwnedDocument = $pdo->prepare(
                        'SELECT id, status
             FROM documents
             WHERE id = :document_id
               AND user_id = :user_id
             LIMIT 1'
        );
                $lookupReceivedDocument = $pdo->prepare(
                        'SELECT d.id, d.status
                         FROM documents d
                         INNER JOIN requests r ON r.document_id = d.id
                         WHERE d.id = :document_id
                             AND r.recipient_id = :user_id
                             AND r.type IN ("notification", "document_signature")
                             AND COALESCE(r.status, "") NOT IN ("cancelled", "archived")
                         ORDER BY r.id DESC
                         LIMIT 1'
                );

        $pdo->beginTransaction();
        try {
            $documentId = $selectedDocumentId;
            if ($requiresDocument && $documentId > 0) {
                $authorizedDocument = null;
                $lookupOwnedDocument->execute([
                    'document_id' => $documentId,
                    'user_id' => (int) $currentUser['id'],
                ]);
                $ownedDocument = $lookupOwnedDocument->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($ownedDocument) {
                    $authorizedDocument = $ownedDocument;
                }

                if ($authorizedDocument === null) {
                    $lookupReceivedDocument->execute([
                        'document_id' => $documentId,
                        'user_id' => (int) $currentUser['id'],
                    ]);
                    $receivedDocument = $lookupReceivedDocument->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($receivedDocument) {
                        $authorizedDocument = $receivedDocument;
                    }
                }

                if (!$authorizedDocument) {
                    throw new RuntimeException((string) t('common.document_not_found'));
                }
                if (strtolower((string) ($authorizedDocument['status'] ?? 'valid')) === 'archived') {
                    throw new RuntimeException((string) t('employee.file_not_available', ['fallback' => 'File not available.']));
                }
            } elseif ($requiresDocument) {
                $insertDocument->execute([
                    'user_id' => (int) $currentUser['id'],
                    'document_type' => 'other',
                    'file_name' => $safeBaseName,
                    'file_path' => '',
                    'file_blob' => $payload,
                    'file_mime_type' => $detectedMime,
                    'status' => 'valid',
                ]);
                $documentId = (int) $pdo->lastInsertId();
            } else {
                $documentId = 0;
            }

            foreach ($recipientIds as $recipientId) {
                $insertRequest->execute([
                    'user_id' => (int) $currentUser['id'],
                    'recipient_id' => (int) $recipientId,
                    'type' => $requestType,
                    'title' => $title,
                    'message' => $message,
                    'status' => in_array($requestType, ['leave', 'sick'], true) ? 'pending' : 'unread',
                    'document_id' => $documentId > 0 ? $documentId : null,
                ]);
            }

            $pdo->commit();
            setFlash('success', in_array($requestType, ['leave', 'sick'], true)
                ? t('crud.send_message', ['fallback' => 'Request sent successfully.'])
                : t('employee.document_shared_success', ['fallback' => 'Document uploaded and sent successfully.']));
            redirectTo('my-space');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', t('employee.document_share_failed', ['fallback' => 'Unable to share this document right now.']));
            redirectTo('my-space');
        }
    }

    if ($action === 'sign_document') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $signatureData = trim((string) ($_POST['signature_data'] ?? ''));
        $signaturePage = max(1, (int) ($_POST['signature_page'] ?? 1));
        $signaturePosX = 88.0;
        $signaturePosY = 92.0;

        if ($requestId <= 0) {
            $error = t('employee.select_valid_document', ['fallback' => 'Please select a valid document to sign.']);
        } elseif ($signatureData === '') {
            $error = t('employee.draw_signature_first');
        } else {
            $requestLookup = $pdo->prepare(
                'SELECT r.id,
                        r.user_id AS sender_id,
                        r.recipient_id,
                        r.type,
                        r.status,
                        r.title,
                        r.message,
                        r.document_id,
                        d.document_type,
                        d.file_name,
                        d.file_path,
                        d.file_blob,
                        d.file_mime_type,
                        sender.first_name AS sender_first_name,
                        sender.last_name AS sender_last_name,
                        dep.company_id AS sender_company_id
                 FROM requests r
                 INNER JOIN documents d ON d.id = r.document_id
                 INNER JOIN users sender ON sender.id = r.user_id
                 LEFT JOIN departments dep ON dep.id = sender.department_id
                 WHERE r.id = :request_id
                   AND r.recipient_id = :recipient_id
                                     AND r.type IN ("document_signature", "notification")
                 LIMIT 1'
            );
            $requestLookup->execute([
                'request_id' => $requestId,
                'recipient_id' => (int) $currentUser['id'],
            ]);
            $signatureRequest = $requestLookup->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$signatureRequest) {
                $error = t('common.unauthorized');
            } elseif (empty($signatureRequest['file_blob']) && $resolveStoredDocumentPath($signatureRequest) === null) {
                $error = t('common.file_not_available');
            } elseif (in_array((string) ($signatureRequest['status'] ?? ''), ['approved', 'rejected'], true)) {
                $error = t('employee.document_already_signed', ['fallback' => 'This signature request has already been processed.']);
            } else {
                $senderName = trim((string) (($signatureRequest['sender_first_name'] ?? '') . ' ' . ($signatureRequest['sender_last_name'] ?? '')));
                if ($senderName === '') {
                    $senderName = 'Manager';
                }

                $signedAt = appNow()->format('Y-m-d H:i:s');
                $employeeName = trim((string) (($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')));
                if ($employeeName === '') {
                    $employeeName = (string) ($currentUser['email'] ?? 'Employee');
                }

                // Resolve source document content.
                $sourceMimeType = strtolower(trim((string) ($signatureRequest['file_mime_type'] ?? '')));
                if ($sourceMimeType === '') {
                    $sourceMimeType = mimeTypeFromFileExtension((string) ($signatureRequest['file_name'] ?? ''));
                }

                $sourceBlob = is_string($signatureRequest['file_blob'] ?? null) ? (string) $signatureRequest['file_blob'] : '';
                if ($sourceBlob === '') {
                    $resolvedPath = $resolveStoredDocumentPath($signatureRequest);
                    if ($resolvedPath !== null) {
                        $fileContent = @file_get_contents($resolvedPath);
                        if (is_string($fileContent) && $fileContent !== '') {
                            $sourceBlob = $fileContent;
                        }
                    }
                }

                if ($sourceBlob === '') {
                    $error = t('common.file_not_available');
                } else {
                    try {
                        $signResult = documentSigningApply(
                            $sourceBlob,
                            $sourceMimeType,
                            $signatureData,
                            $signaturePosX,
                            $signaturePosY,
                            $signaturePage,
                            $employeeName,
                            $signedAt
                        );
                    } catch (Throwable $signingException) {
                        $signResult = null;
                        $error = t('employee.document_sign_failed', ['fallback' => 'Unable to sign this document right now.']) . ' (' . $signingException->getMessage() . ')';
                    }

                    if ($signResult !== null) {
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
                        $updateRequest = $pdo->prepare(
                            'UPDATE requests
                             SET status = :status,
                                 updated_at = CURRENT_TIMESTAMP
                             WHERE id = :id
                               AND recipient_id = :recipient_id
                             LIMIT 1'
                        );
                        $pdo->beginTransaction();
                        try {
                            $insertSignature->execute([
                                'user_id' => (int) $currentUser['id'],
                                'signature_type' => 'touchscreen',
                                'signature_data' => $signatureData,
                            ]);

                            $sourceFileName = trim((string) ($signatureRequest['file_name'] ?? 'document'));
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
                                'user_id' => (int) $currentUser['id'],
                                'document_type' => (string) ($signatureRequest['document_type'] ?? 'other'),
                                'file_name' => $signedFileName,
                                'file_path' => '',
                                'file_blob' => $signResult['blob'],
                                'file_mime_type' => $signResult['mime_type'],
                                'status' => 'valid',
                                'signed_at' => $signedAt,
                                'signed_by_user_id' => (int) $currentUser['id'],
                                'signed_page' => $signResult['page'],
                            ]);
                            $signedDocumentId = (int) $pdo->lastInsertId();

                            $updateRequest->execute([
                                'status' => 'cancelled',
                                'id' => $requestId,
                                'recipient_id' => (int) $currentUser['id'],
                            ]);

                            $pdo->commit();
                            setFlash('success', t('flash.document_signed_success', ['fallback' => 'Document signed successfully. Choose recipients to share it.']));
                            $redirectToDocumentsModal([
                                'share_document_id' => $signedDocumentId,
                            ]);
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $error = t('employee.document_sign_failed', ['fallback' => 'Unable to sign this document right now.']);
                        }
                    }
                }
            }
        }
    }

    if ($action === 'sign_attendance') {
        $userShiftId = (int) ($_POST['user_shift_id'] ?? 0);
        $signatureData = trim((string) ($_POST['signature_data'] ?? ''));
        $shiftStartAt = null;
        $isLateCheckIn = false;
        if ($userShiftId <= 0) {
            $error = t('employee.select_valid_shift');
        } elseif ($signatureData === '') {
            $error = t('employee.draw_signature_first');
        } else {
            $shiftCheck = $pdo->prepare(
                'SELECT us.id,
                        us.work_date,
                    s.kind AS shift_kind,
                        s.start_time,
                        s.end_time,
                        ' . $companySignatureIpSelect . '
                 FROM user_shifts us
                 INNER JOIN shifts s ON s.id = us.shift_id
                 INNER JOIN departments d ON d.id = s.department_id
                 INNER JOIN companies c ON c.id = d.company_id
                 WHERE us.id = :id
                   AND us.user_id = :user_id
                 LIMIT 1'
            );
            $shiftCheck->execute(['id' => $userShiftId, 'user_id' => $currentUser['id']]);
            $assignedShift = $shiftCheck->fetch();

            if (!$assignedShift) {
                $error = t('common.unauthorized_shift');
            } elseif ((string) ($assignedShift['work_date'] ?? '') !== $todayDate) {
                $error = t('employee.sign_today_only');
            } elseif (!in_array(strtolower(trim((string) ($assignedShift['shift_kind'] ?? 'work'))), ['work', 'overtime'], true)) {
                $error = t('employee.sign_work_shifts_only');
            } else {
                $shiftRequiredIp = $normalizeIp((string) ($assignedShift['signature_ip'] ?? ''));
                if ($shiftRequiredIp !== '' && $clientIp !== $shiftRequiredIp) {
                    $error = t('employee.signature_ip_required', ['ip' => $shiftRequiredIp]);
                }

                $shiftStartAt = $buildShiftDateTime((string) ($assignedShift['work_date'] ?? ''), (string) ($assignedShift['start_time'] ?? ''));
                $shiftEndAt = $buildShiftDateTime((string) ($assignedShift['work_date'] ?? ''), (string) ($assignedShift['end_time'] ?? ''));
                if ($shiftStartAt !== null && $shiftEndAt !== null && $shiftEndAt <= $shiftStartAt) {
                    $shiftEndAt = $shiftEndAt->modify('+1 day');
                }

                if ($error === null && $shiftStartAt !== null && $shiftEndAt !== null) {
                    $signWindowStart = $shiftStartAt->modify('-5 minutes');
                    if ($now < $signWindowStart) {
                        $error = t('employee.sign_open_before_shift');
                    } elseif ($now > $shiftEndAt) {
                        $error = t('employee.sign_closed_after_shift');
                    }
                }

                if ($error === null && $shiftStartAt !== null && $now > $shiftStartAt) {
                    $isLateCheckIn = true;
                }
            }

            if ($error === null) {
                $attendanceStatus = $isLateCheckIn ? 'late' : 'present';
                $insertSignature = $pdo->prepare(
                    'INSERT INTO digital_signatures (user_id, signature_type, signature_data)
                     VALUES (:user_id, :signature_type, :signature_data)'
                );
                $insertSignature->execute([
                    'user_id' => (int) $currentUser['id'],
                    'signature_type' => 'touchscreen',
                    'signature_data' => $signatureData,
                ]);
                $digitalSignatureId = (int) $pdo->lastInsertId();

                $attendanceCheck = $pdo->prepare('SELECT id, check_in_time FROM attendances WHERE user_id = :user_id AND user_shift_id = :user_shift_id AND work_date = :work_date LIMIT 1');
                $attendanceCheck->execute([
                    'user_id' => $currentUser['id'],
                    'user_shift_id' => $userShiftId,
                    'work_date' => $todayDate,
                ]);
                $existingAttendance = $attendanceCheck->fetch(PDO::FETCH_ASSOC) ?: null;
                $existingAttendanceId = (int) ($existingAttendance['id'] ?? 0);

                if ($existingAttendanceId > 0 && trim((string) ($existingAttendance['check_in_time'] ?? '')) !== '') {
                    $error = t('employee.attendance_already_recorded');
                }

                if ($error === null && $existingAttendanceId > 0) {
                    $updateAttendance = $pdo->prepare(
                        'UPDATE attendances
                         SET status = :status,
                             digital_signature_id = :digital_signature_id,
                             check_in_time = COALESCE(check_in_time, :check_in_time),
                             updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id'
                    );
                    $updateAttendance->execute([
                        'status' => $attendanceStatus,
                        'digital_signature_id' => $digitalSignatureId,
                        'check_in_time' => $now->format('H:i:s'),
                        'id' => (int) $existingAttendanceId,
                    ]);
                } elseif ($error === null) {
                    $insertAttendance = $pdo->prepare(
                        'INSERT INTO attendances (user_id, user_shift_id, digital_signature_id, work_date, check_in_time, status)
                         VALUES (:user_id, :user_shift_id, :digital_signature_id, :work_date, :check_in_time, :status)'
                    );
                    $insertAttendance->execute([
                        'user_id' => $currentUser['id'],
                        'user_shift_id' => $userShiftId,
                        'digital_signature_id' => $digitalSignatureId,
                        'work_date' => $todayDate,
                        'check_in_time' => $now->format('H:i:s'),
                        'status' => $attendanceStatus,
                    ]);
                }

                if ($error === null) {
                    if ($isLateCheckIn) {
                        setFlash('success', t('flash.attendance_recorded_late'));
                    } else {
                        setFlash('success', t('flash.attendance_recorded'));
                    }
                    redirectTo('my-space');
                }
            }
        }
    }

}