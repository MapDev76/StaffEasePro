<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/ShiftModel.php';
require_once __DIR__ . '/../models/DepartmentModel.php';

$currentUser = currentUser();
$currentRole = (string) ($currentUser['role'] ?? '');

if (!isLoggedIn() || !in_array($currentRole, ['super_admin', 'admin', 'department_manager'], true)) {
            jsonResponse(['error' => t('common.unauthorized')], 403);
}

$pdo = getPDO();
ensureSchedulerSchema($pdo);
$shiftModel = new ShiftModel($pdo);
$departmentModel = new DepartmentModel($pdo);

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;
$action = $input['action'] ?? ($_GET['action'] ?? 'list');

$isProtectedTemplate = static function (?array $shiftRow): bool {
    $kind = strtolower(trim((string) ($shiftRow['kind'] ?? 'work')));
    if (in_array($kind, ['rest', 'vacation', 'sick'], true)) {
        return true;
    }

    // Legacy fallback: some historical rows may still have kind=work.
    $name = strtolower(trim((string) ($shiftRow['name'] ?? '')));
    return in_array($name, ['rest day', 'vacation', 'sick leave'], true);
};

$resolveUserCompanyId = static function (PDO $pdo, array $user): int {
    $companyId = (int) ($user['company_id'] ?? 0);
    if ($companyId > 0) {
        return $companyId;
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return 0;
    }

    $statement = $pdo->prepare(
        'SELECT d.company_id
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = :user_id
         LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);

    return (int) ($statement->fetchColumn() ?: 0);
};

$resolveShiftCompanyId = static function (PDO $pdo, array $shift): int {
    $departmentId = (int) ($shift['department_id'] ?? 0);
    if ($departmentId <= 0) {
        return 0;
    }

    $statement = $pdo->prepare('SELECT company_id FROM departments WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $departmentId]);

    return (int) ($statement->fetchColumn() ?: 0);
};

$adminCompanyId = $currentRole === 'admin' ? $resolveUserCompanyId($pdo, $currentUser) : 0;

$normalizeWeekdays = static function ($rawValue): array {
    if (is_string($rawValue)) {
        $decoded = json_decode($rawValue, true);
        $rawValue = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($rawValue)) {
        $rawValue = [];
    }

    $weekdays = [];
    foreach ($rawValue as $candidate) {
        $weekday = (int) $candidate;
        if ($weekday >= 0 && $weekday <= 6) {
            $weekdays[$weekday] = $weekday;
        }
    }

    return array_values($weekdays);
};

$normalizeMonths = static function ($rawValue): array {
    if (is_string($rawValue)) {
        $decoded = json_decode($rawValue, true);
        $rawValue = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($rawValue)) {
        $rawValue = [];
    }

    $months = [];
    foreach ($rawValue as $candidate) {
        $month = (int) $candidate;
        if ($month >= 1 && $month <= 12) {
            $months[$month] = $month;
        }
    }

    if (empty($months)) {
        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = $month;
        }
    }

    return array_values($months);
};

$buildDateRange = static function (string $rangeStart, string $rangeEnd): array {
    $start = new DateTimeImmutable($rangeStart);
    $end = new DateTimeImmutable($rangeEnd);
    if ($end < $start) {
        [$start, $end] = [$end, $start];
    }
    return [$start, $end];
};

$collectRestDates = static function (
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    array $restWeekdays,
    string $repeatMode,
    string $scaleMode,
    array $monthNumbers
): array {
    if (empty($restWeekdays)) {
        return [];
    }

    $restWeekdaySet = array_fill_keys(array_map('intval', $restWeekdays), true);
    $monthSet = array_fill_keys(array_map('intval', $monthNumbers), true);
    $result = [];

    if ($repeatMode === 'weekly') {
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day')) as $date) {
            $weekday = (int) $date->format('w');
            $monthNumber = (int) $date->format('n');
            if (!isset($restWeekdaySet[$weekday]) || !isset($monthSet[$monthNumber])) {
                continue;
            }
            $result[$date->format('Y-m-d')] = true;
        }
        return array_keys($result);
    }

    $monthCursor = new DateTimeImmutable($start->format('Y-m-01'));
    $monthIndex = 0;
    while ($monthCursor <= $end) {
        $monthNumber = (int) $monthCursor->format('n');
        if (!isset($monthSet[$monthNumber])) {
            $monthCursor = $monthCursor->modify('+1 month');
            $monthIndex++;
            continue;
        }

        $monthEnd = $monthCursor->modify('last day of this month');
        $windowStart = $monthCursor > $start ? $monthCursor : $start;
        $windowEnd = $monthEnd < $end ? $monthEnd : $end;

        foreach (array_keys($restWeekdaySet) as $weekday) {
            $candidates = [];
            foreach (new DatePeriod($windowStart, new DateInterval('P1D'), $windowEnd->modify('+1 day')) as $date) {
                if ((int) $date->format('w') === (int) $weekday) {
                    $candidates[] = $date;
                }
            }

            if (empty($candidates)) {
                continue;
            }

            if ($scaleMode === 'monthly') {
                $picked = $candidates[0];
            } else {
                $pickIndex = $monthIndex % count($candidates);
                $picked = $candidates[$pickIndex];
            }

            $result[$picked->format('Y-m-d')] = true;
        }

        $monthCursor = $monthCursor->modify('+1 month');
        $monthIndex++;
    }

    return array_keys($result);
};

$generateScheduleSlots = static function (
    PDO $pdo,
    array $workShiftIdsByDepartment,
    string $rangeStart,
    string $rangeEnd,
    array $workWeekdays,
    array $monthNumbers,
    array $weeklyRestWeekdays,
    bool $includeRestday,
    array $restdayWeekdays,
    string $restdayRepeatMode,
    string $restdayScaleMode,
    bool $replaceWorkSlots
) use ($buildDateRange, $collectRestDates): void {
    if (empty($workShiftIdsByDepartment)) {
        return;
    }

    [$start, $end] = $buildDateRange($rangeStart, $rangeEnd);
    $workWeekdaySet = array_fill_keys(array_map('intval', $workWeekdays), true);
    if (empty($workWeekdaySet)) {
        $workWeekdaySet = array_fill_keys([0, 1, 2, 3, 4, 5, 6], true);
    }
    $monthSet = array_fill_keys(array_map('intval', $monthNumbers), true);
    $weeklyRestSet = array_fill_keys(array_map('intval', $weeklyRestWeekdays), true);

    $workDates = [];
    foreach (new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day')) as $date) {
        $weekday = (int) $date->format('w');
        $monthNumber = (int) $date->format('n');
        if (!isset($monthSet[$monthNumber])) {
            continue;
        }
        if (!isset($workWeekdaySet[$weekday])) {
            continue;
        }
        if (isset($weeklyRestSet[$weekday])) {
            continue;
        }
        $workDates[] = $date->format('Y-m-d');
    }

    $deleteWorkStmt = $pdo->prepare(
        'DELETE FROM user_shifts
         WHERE shift_id = :shift_id
           AND user_id IS NULL
           AND status = "open"
           AND work_date BETWEEN :range_start AND :range_end'
    );
    $existingStmt = $pdo->prepare(
        'SELECT id FROM user_shifts
         WHERE shift_id = :shift_id
           AND work_date = :work_date
         LIMIT 1'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO user_shifts (shift_id, user_id, work_date, status)
         VALUES (:shift_id, NULL, :work_date, "open")'
    );

    foreach ($workShiftIdsByDepartment as $departmentId => $shiftIds) {
        $departmentShiftIds = array_values(array_unique(array_filter(array_map('intval', is_array($shiftIds) ? $shiftIds : []))));
        if (empty($departmentShiftIds)) {
            continue;
        }

        foreach ($departmentShiftIds as $shiftId) {
            if ($replaceWorkSlots) {
                $deleteWorkStmt->execute([
                    'shift_id' => $shiftId,
                    'range_start' => $start->format('Y-m-d'),
                    'range_end' => $end->format('Y-m-d'),
                ]);
            }

            foreach ($workDates as $workDate) {
                $existingStmt->execute([
                    'shift_id' => $shiftId,
                    'work_date' => $workDate,
                ]);
                if ($existingStmt->fetchColumn()) {
                    continue;
                }

                $insertStmt->execute([
                    'shift_id' => $shiftId,
                    'work_date' => $workDate,
                ]);
            }
        }

        if (!$includeRestday) {
            continue;
        }

        $restDates = $collectRestDates($start, $end, $restdayWeekdays, $restdayRepeatMode, $restdayScaleMode, $monthNumbers);
        if (empty($restDates)) {
            continue;
        }

        $templateIds = ensureDepartmentAbsenceShiftTemplates($pdo, (int) $departmentId);
        $restShiftId = (int) ($templateIds['rest'] ?? 0);
        if ($restShiftId <= 0) {
            continue;
        }

        foreach ($restDates as $restDate) {
            $existingStmt->execute([
                'shift_id' => $restShiftId,
                'work_date' => $restDate,
            ]);
            if ($existingStmt->fetchColumn()) {
                continue;
            }

            $insertStmt->execute([
                'shift_id' => $restShiftId,
                'work_date' => $restDate,
            ]);
        }
    }
};

try {
    switch ($action) {
        case 'list':
            $departmentId = (int) ($input['department_id'] ?? 0);
            if ($departmentId > 0) {
                $rows = $shiftModel->byDepartmentId($departmentId);
                jsonResponse(['ok' => true, 'shifts' => $rows]);
            }
            jsonResponse(['ok' => false, 'error' => t('common.department_required')], 400);
            break;

        case 'create':
            if (!in_array($currentRole, ['admin', 'super_admin'], true)) {
                jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
            }
            $requestedKind = strtolower(trim((string) ($input['kind'] ?? 'work')));
            if (!in_array($requestedKind, ['work', 'rest', 'vacation', 'sick'], true)) {
                $requestedKind = 'work';
            }
            if ($requestedKind !== 'work') {
                jsonResponse(['ok' => false, 'error' => t('settings.system_shift_auto_managed')], 400);
            }

            $required = ['department_id', 'start_time', 'end_time'];
            foreach ($required as $r) {
                if (empty($input[$r]) && $input[$r] !== '0') {
                    jsonResponse(['ok' => false, 'error' => $r . ' required'], 400);
                }
            }

            if (trim((string) ($input['name'] ?? '')) === '') {
                jsonResponse(['ok' => false, 'error' => t('settings.shift_name_required')], 400);
            }

            $createDepartmentIds = [];
            $rawDepartmentIds = $input['department_ids'] ?? null;
            if (is_array($rawDepartmentIds)) {
                foreach ($rawDepartmentIds as $candidateId) {
                    $id = (int) $candidateId;
                    if ($id > 0) {
                        $createDepartmentIds[] = $id;
                    }
                }
            }

            if (empty($createDepartmentIds)) {
                $fallbackDepartmentId = (int) ($input['department_id'] ?? 0);
                if ($fallbackDepartmentId > 0) {
                    $createDepartmentIds[] = $fallbackDepartmentId;
                }
            }

            $createDepartmentIds = array_values(array_unique($createDepartmentIds));
            if (empty($createDepartmentIds)) {
                jsonResponse(['ok' => false, 'error' => t('common.department_required')], 400);
            }

            foreach ($createDepartmentIds as $createDepartmentId) {
                $createDepartment = $departmentModel->findById($createDepartmentId);
                if (!$createDepartment) {
                    jsonResponse(['ok' => false, 'error' => 'Department not found'], 404);
                }

                if ($currentRole === 'admin') {
                    $departmentCompanyId = (int) ($createDepartment['company_id'] ?? 0);
                    if ($adminCompanyId <= 0 || $departmentCompanyId <= 0 || $departmentCompanyId !== $adminCompanyId) {
                        jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
                    }
                }

            }

            $createdShiftIds = [];
            $name = trim((string) $input['name']);
            $icon = $input['icon'] ?? null;
            $color = $input['color'] ?? null;
            $description = $input['description'] ?? null;
            $startTime = $input['start_time'];
            $endTime = $input['end_time'];
            $rangeStart = trim((string) ($input['range_start'] ?? ''));
            $rangeEnd = trim((string) ($input['range_end'] ?? ''));
            $workWeekdays = $normalizeWeekdays($input['work_weekdays'] ?? [0, 1, 2, 3, 4, 5, 6]);
            if (empty($workWeekdays)) {
                $workWeekdays = [0, 1, 2, 3, 4, 5, 6];
            }
            $monthNumbers = $normalizeMonths($input['month_numbers'] ?? []);
            $weeklyRestWeekdays = $normalizeWeekdays($input['weekly_rest_weekdays'] ?? []);
            $includeRestdayRaw = strtolower(trim((string) ($input['include_restday'] ?? '0')));
            $includeRestday = in_array($includeRestdayRaw, ['1', 'true', 'yes', 'on'], true);
            $restdayWeekdays = $normalizeWeekdays($input['restday_weekdays'] ?? []);
            $restdayRepeatMode = strtolower(trim((string) ($input['restday_repeat_mode'] ?? 'weekly')));
            if (!in_array($restdayRepeatMode, ['weekly', 'monthly'], true)) {
                $restdayRepeatMode = 'weekly';
            }
            $restdayScaleMode = strtolower(trim((string) ($input['restday_scale_mode'] ?? 'weekly')));
            if (!in_array($restdayScaleMode, ['weekly', 'monthly'], true)) {
                $restdayScaleMode = 'weekly';
            }

            $lookupTemplateShift = $pdo->prepare(
                'SELECT id
                 FROM shifts
                 WHERE department_id = :department_id
                   AND kind = :kind
                 ORDER BY id ASC
                 LIMIT 1'
            );

            foreach ($createDepartmentIds as $createDepartmentId) {
                if (in_array($requestedKind, ['rest', 'vacation', 'sick'], true)) {
                    $lookupTemplateShift->execute([
                        'department_id' => $createDepartmentId,
                        'kind' => $requestedKind,
                    ]);
                    $existingTemplateId = (int) ($lookupTemplateShift->fetchColumn() ?: 0);
                    if ($existingTemplateId > 0) {
                        $shiftModel->update($existingTemplateId, [
                            'department_id' => $createDepartmentId,
                            'name' => $name,
                            'icon' => $icon,
                            'color' => $color,
                            'description' => $description,
                            'kind' => $requestedKind,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                        ]);
                        $createdShiftIds[] = $existingTemplateId;
                        continue;
                    }
                }

                $createdShiftIds[] = $shiftModel->create([
                    'department_id' => $createDepartmentId,
                    'name' => $name,
                    'icon' => $icon,
                    'color' => $color,
                    'description' => $description,
                    'kind' => $requestedKind,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ]);
            }

            if ($requestedKind === 'work') {
                if ($rangeStart === '' || $rangeEnd === '') {
                    jsonResponse(['ok' => false, 'error' => 'range_start and range_end required'], 400);
                }

                $workShiftIdsByDepartment = [];
                foreach ($createDepartmentIds as $index => $departmentId) {
                    $shiftId = (int) ($createdShiftIds[$index] ?? 0);
                    if ($shiftId <= 0) {
                        continue;
                    }
                    $workShiftIdsByDepartment[(int) $departmentId] = [$shiftId];
                }

                $generateScheduleSlots(
                    $pdo,
                    $workShiftIdsByDepartment,
                    $rangeStart,
                    $rangeEnd,
                    $workWeekdays,
                    $monthNumbers,
                    $weeklyRestWeekdays,
                    $includeRestday,
                    $restdayWeekdays,
                    $restdayRepeatMode,
                    $restdayScaleMode,
                    false
                );
            }

            $firstShiftId = (int) ($createdShiftIds[0] ?? 0);
            $shift = $firstShiftId > 0 ? $shiftModel->findById($firstShiftId) : null;
            jsonResponse([
                'ok' => true,
                'shift' => $shift,
                'shift_ids' => $createdShiftIds,
                'department_ids' => $createDepartmentIds,
                'weekly_rest_weekdays' => $weeklyRestWeekdays,
                'work_weekdays' => $workWeekdays,
                'month_numbers' => $monthNumbers,
            ]);
            break;

        case 'update':
            if (!in_array($currentRole, ['admin', 'super_admin'], true)) {
                jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
            }
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) jsonResponse(['ok' => false, 'error' => 'id required'], 400);
            $existingShift = $shiftModel->findById($id);
            if (!$existingShift) {
                jsonResponse(['ok' => false, 'error' => 'Shift not found'], 404);
            }

            if ($currentRole === 'admin' && $isProtectedTemplate($existingShift)) {
                jsonResponse(['ok' => false, 'error' => 'System absence templates cannot be modified.'], 400);
            }

            if ($currentRole === 'admin') {
                $shiftCompanyId = $resolveShiftCompanyId($pdo, $existingShift);
                if ($adminCompanyId <= 0 || $shiftCompanyId <= 0 || $shiftCompanyId !== $adminCompanyId) {
                    jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
                }
            }

            $normalizeTime = static function ($value, $fallback) {
                $candidate = trim((string) $value);
                if ($candidate === '') {
                    return trim((string) $fallback);
                }
                return strlen($candidate) >= 5 ? substr($candidate, 0, 5) : $candidate;
            };

            $resolvedDepartmentId = (int) ($input['department_id'] ?? ($existingShift['department_id'] ?? 0));
            if ($resolvedDepartmentId <= 0) {
                jsonResponse(['ok' => false, 'error' => t('common.department_required')], 400);
            }

            $resolvedPayload = [
                'department_id' => $resolvedDepartmentId,
                'name' => trim((string) ($input['name'] ?? ($existingShift['name'] ?? ''))),
                'icon' => array_key_exists('icon', $input) ? $input['icon'] : ($existingShift['icon'] ?? null),
                'color' => array_key_exists('color', $input) ? $input['color'] : ($existingShift['color'] ?? null),
                'description' => array_key_exists('description', $input) ? $input['description'] : ($existingShift['description'] ?? null),
                'kind' => (string) ($input['kind'] ?? ($existingShift['kind'] ?? 'work')),
                'start_time' => $normalizeTime($input['start_time'] ?? '', $existingShift['start_time'] ?? ''),
                'end_time' => $normalizeTime($input['end_time'] ?? '', $existingShift['end_time'] ?? ''),
            ];

            if ($resolvedPayload['name'] === '') {
                $resolvedPayload['name'] = trim((string) ($existingShift['name'] ?? ''));
            }
            if ($resolvedPayload['name'] === '') {
                jsonResponse(['ok' => false, 'error' => t('settings.shift_name_required')], 400);
            }
            if ($resolvedPayload['start_time'] === '' || $resolvedPayload['end_time'] === '') {
                jsonResponse(['ok' => false, 'error' => 'start_time and end_time required'], 400);
            }

            $shiftModel->update($id, $resolvedPayload);

            $regenerateSlotsRaw = strtolower(trim((string) ($input['regenerate_slots'] ?? '0')));
            $regenerateSlots = in_array($regenerateSlotsRaw, ['1', 'true', 'yes', 'on'], true);
            if ($regenerateSlots && strtolower((string) ($resolvedPayload['kind'] ?? 'work')) === 'work') {
                $rangeStart = trim((string) ($input['range_start'] ?? ''));
                $rangeEnd = trim((string) ($input['range_end'] ?? ''));
                if ($rangeStart !== '' && $rangeEnd !== '') {
                    $workWeekdays = $normalizeWeekdays($input['work_weekdays'] ?? [0, 1, 2, 3, 4, 5, 6]);
                    if (empty($workWeekdays)) {
                        $workWeekdays = [0, 1, 2, 3, 4, 5, 6];
                    }
                    $monthNumbers = $normalizeMonths($input['month_numbers'] ?? []);
                    $weeklyRestWeekdays = $normalizeWeekdays($input['weekly_rest_weekdays'] ?? []);
                    $includeRestdayRaw = strtolower(trim((string) ($input['include_restday'] ?? '0')));
                    $includeRestday = in_array($includeRestdayRaw, ['1', 'true', 'yes', 'on'], true);
                    $restdayWeekdays = $normalizeWeekdays($input['restday_weekdays'] ?? []);
                    $restdayRepeatMode = strtolower(trim((string) ($input['restday_repeat_mode'] ?? 'weekly')));
                    if (!in_array($restdayRepeatMode, ['weekly', 'monthly'], true)) {
                        $restdayRepeatMode = 'weekly';
                    }
                    $restdayScaleMode = strtolower(trim((string) ($input['restday_scale_mode'] ?? 'weekly')));
                    if (!in_array($restdayScaleMode, ['weekly', 'monthly'], true)) {
                        $restdayScaleMode = 'weekly';
                    }

                    $generateScheduleSlots(
                        $pdo,
                        [$resolvedDepartmentId => [$id]],
                        $rangeStart,
                        $rangeEnd,
                        $workWeekdays,
                        $monthNumbers,
                        $weeklyRestWeekdays,
                        $includeRestday,
                        $restdayWeekdays,
                        $restdayRepeatMode,
                        $restdayScaleMode,
                        true
                    );
                }
            }

            jsonResponse(['ok' => true]);
            break;

        case 'delete':
            if (!in_array($currentRole, ['admin', 'super_admin'], true)) {
                jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
            }
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) jsonResponse(['ok' => false, 'error' => 'id required'], 400);
            $existingShift = $shiftModel->findById($id);
            if (!$existingShift) {
                jsonResponse(['ok' => false, 'error' => 'Shift not found'], 404);
            }

            if ($currentRole === 'admin' && $isProtectedTemplate($existingShift)) {
                jsonResponse(['ok' => false, 'error' => 'System absence templates cannot be deleted.'], 400);
            }

            if ($currentRole === 'admin') {
                $shiftCompanyId = $resolveShiftCompanyId($pdo, $existingShift);
                if ($adminCompanyId <= 0 || $shiftCompanyId <= 0 || $shiftCompanyId !== $adminCompanyId) {
                    jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
                }
            }

            $shiftModel->delete($id);
            jsonResponse(['ok' => true]);
            break;

        case 'ensure_period_coverage': {
            // Read-only report for the planning wizard (phase 2): which dates in the
            // chosen period already have at least one occurrence of each selected
            // shift, and which are still uncovered.
            $departmentId = (int) ($input['department_id'] ?? 0);
            $rangeStart = trim((string) ($input['range_start'] ?? ''));
            $rangeEnd = trim((string) ($input['range_end'] ?? ''));
            $shiftIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($input['shift_ids'] ?? null) ? $input['shift_ids'] : []
            ))));

            if ($departmentId <= 0 || $rangeStart === '' || $rangeEnd === '' || empty($shiftIds)) {
                jsonResponse(['ok' => false, 'error' => 'department_id, range_start, range_end and shift_ids are required'], 400);
            }

            $department = $departmentModel->findById($departmentId);
            if (!$department) {
                jsonResponse(['ok' => false, 'error' => 'Department not found'], 404);
            }
            if ($currentRole === 'admin') {
                $departmentCompanyId = (int) ($department['company_id'] ?? 0);
                if ($adminCompanyId <= 0 || $departmentCompanyId !== $adminCompanyId) {
                    jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
                }
            }

            [$start, $end] = $buildDateRange($rangeStart, $rangeEnd);

            $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
            $coverageStmt = $pdo->prepare(
                "SELECT work_date, shift_id, COUNT(*) AS occurrences
                 FROM user_shifts
                 WHERE shift_id IN ($placeholders)
                   AND work_date BETWEEN ? AND ?
                   AND status <> 'cancelled'
                 GROUP BY work_date, shift_id"
            );
            $coverageStmt->execute(array_merge($shiftIds, [$start->format('Y-m-d'), $end->format('Y-m-d')]));

            $coveredByDate = [];
            foreach ($coverageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $coveredByDate[$row['work_date']][(int) $row['shift_id']] = true;
            }

            $dates = [];
            $datesWithGaps = 0;
            foreach (new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day')) as $date) {
                $dateKey = $date->format('Y-m-d');
                $covered = array_keys($coveredByDate[$dateKey] ?? []);
                $missing = array_values(array_diff($shiftIds, $covered));
                if (!empty($missing)) {
                    $datesWithGaps++;
                }
                $dates[] = [
                    'date' => $dateKey,
                    'covered_shift_ids' => array_values($covered),
                    'missing_shift_ids' => $missing,
                ];
            }

            jsonResponse([
                'ok' => true,
                'dates' => $dates,
                'summary' => [
                    'total_dates' => count($dates),
                    'dates_with_gaps' => $datesWithGaps,
                ],
            ]);
            break;
        }

        case 'auto_assign_period': {
            // Simplified auto-assignment for the planning wizard (phase 4). No
            // presets, no cross-department fallback, no rotating patterns: just
            // conflict-free assignment of work shifts across the chosen period,
            // respecting each employee's rest/work day choices for this run, with
            // simple balancing on hours already assigned in the period.
            if (!in_array($currentRole, ['admin', 'super_admin', 'department_manager'], true)) {
                jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
            }

            $departmentId = (int) ($input['department_id'] ?? 0);
            $rangeStart = trim((string) ($input['range_start'] ?? ''));
            $rangeEnd = trim((string) ($input['range_end'] ?? ''));
            $workShiftIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($input['work_shift_ids'] ?? null) ? $input['work_shift_ids'] : []
            ))));
            $employeeIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($input['employee_ids'] ?? null) ? $input['employee_ids'] : []
            ))));
            $employeeRestWeekdays = is_array($input['employee_rest_weekdays'] ?? null) ? $input['employee_rest_weekdays'] : [];

            if ($departmentId <= 0 || $rangeStart === '' || $rangeEnd === '' || empty($workShiftIds) || empty($employeeIds)) {
                jsonResponse(['ok' => false, 'error' => 'department_id, range_start, range_end, work_shift_ids and employee_ids are required'], 400);
            }

            $department = $departmentModel->findById($departmentId);
            if (!$department) {
                jsonResponse(['ok' => false, 'error' => 'Department not found'], 404);
            }
            if ($currentRole === 'admin') {
                $departmentCompanyId = (int) ($department['company_id'] ?? 0);
                if ($adminCompanyId <= 0 || $departmentCompanyId !== $adminCompanyId) {
                    jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
                }
            }

            [$start, $end] = $buildDateRange($rangeStart, $rangeEnd);

            // Rest-day weekdays per employee for this planning run (0=Sun..6=Sat, matching PHP's DateTime 'w').
            $restWeekdaysByUser = [];
            foreach ($employeeRestWeekdays as $userIdKey => $weekdayList) {
                $restWeekdaysByUser[(int) $userIdKey] = $normalizeWeekdays($weekdayList);
            }

            // Shift templates involved (need start/end time for hour totals and department check).
            $shiftPlaceholders = implode(',', array_fill(0, count($workShiftIds), '?'));
            $shiftLookupStmt = $pdo->prepare("SELECT id, department_id, kind, start_time, end_time FROM shifts WHERE id IN ($shiftPlaceholders)");
            $shiftLookupStmt->execute($workShiftIds);
            $shiftById = [];
            foreach ($shiftLookupStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $shiftById[(int) $row['id']] = $row;
            }

            $shiftHours = static function (array $shift): float {
                $start = strtotime((string) ($shift['start_time'] ?? '00:00:00'));
                $end = strtotime((string) ($shift['end_time'] ?? '00:00:00'));
                if ($start === false || $end === false) {
                    return 0.0;
                }
                $diff = $end - $start;
                if ($diff <= 0) {
                    $diff += 86400; // overnight shift
                }
                return round($diff / 3600, 2);
            };

            // Existing assignments in the period, to detect same-day conflicts and starting hour totals.
            $existingStmt = $pdo->prepare(
                'SELECT us.id, us.shift_id, us.user_id, us.work_date, us.status, s.start_time, s.end_time
                 FROM user_shifts us
                 INNER JOIN shifts s ON s.id = us.shift_id
                 WHERE us.work_date BETWEEN :range_start AND :range_end
                   AND us.status <> "cancelled"'
            );
            $existingStmt->execute(['range_start' => $start->format('Y-m-d'), 'range_end' => $end->format('Y-m-d')]);

            $busyByUserDate = []; // "userId|date" => true (already has a shift that day)
            $hoursByUser = [];    // userId => hours already assigned in this period
            foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rowUserId = (int) ($row['user_id'] ?? 0);
                if ($rowUserId <= 0) {
                    continue;
                }
                $busyByUserDate[$rowUserId . '|' . $row['work_date']] = true;
                $hoursByUser[$rowUserId] = ($hoursByUser[$rowUserId] ?? 0) + $shiftHours($row);
            }
            foreach ($employeeIds as $employeeId) {
                $hoursByUser[$employeeId] = $hoursByUser[$employeeId] ?? 0;
            }

            $findOpenSlotStmt = $pdo->prepare(
                'SELECT id FROM user_shifts
                 WHERE shift_id = :shift_id AND work_date = :work_date AND user_id IS NULL AND status = "open"
                 LIMIT 1'
            );
            $insertAssignedStmt = $pdo->prepare(
                'INSERT INTO user_shifts (shift_id, user_id, work_date, status) VALUES (:shift_id, :user_id, :work_date, "assigned")'
            );
            $claimOpenSlotStmt = $pdo->prepare(
                'UPDATE user_shifts SET user_id = :user_id, status = "assigned" WHERE id = :id'
            );

            $assignedCount = 0;
            $conflicts = [];
            $restAssignedCount = 0;

            foreach (new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day')) as $date) {
                $dateKey = $date->format('Y-m-d');
                $weekday = (int) $date->format('w');

                foreach ($workShiftIds as $shiftId) {
                    $shift = $shiftById[$shiftId] ?? null;
                    if (!$shift || (int) $shift['department_id'] !== $departmentId || $shift['kind'] !== 'work') {
                        continue;
                    }

                    // Already assigned to someone for this shift+date? Nothing to do.
                    $alreadyAssignedStmt = $pdo->prepare(
                        'SELECT id FROM user_shifts WHERE shift_id = :shift_id AND work_date = :work_date AND user_id IS NOT NULL AND status <> "cancelled" LIMIT 1'
                    );
                    $alreadyAssignedStmt->execute(['shift_id' => $shiftId, 'work_date' => $dateKey]);
                    if ($alreadyAssignedStmt->fetchColumn()) {
                        continue;
                    }

                    $bestCandidate = null;
                    $bestHours = null;
                    foreach ($employeeIds as $employeeId) {
                        if (!empty($busyByUserDate[$employeeId . '|' . $dateKey])) {
                            continue; // conflict: already has a shift this day
                        }
                        if (in_array($weekday, $restWeekdaysByUser[$employeeId] ?? [], true)) {
                            continue; // employee asked to rest this weekday
                        }
                        $candidateHours = $hoursByUser[$employeeId] ?? 0;
                        if ($bestHours === null || $candidateHours < $bestHours) {
                            $bestHours = $candidateHours;
                            $bestCandidate = $employeeId;
                        }
                    }

                    if ($bestCandidate === null) {
                        $conflicts[] = [
                            'shift_id' => $shiftId,
                            'work_date' => $dateKey,
                            'reason' => 'no_available_employee',
                        ];
                        continue;
                    }

                    $findOpenSlotStmt->execute(['shift_id' => $shiftId, 'work_date' => $dateKey]);
                    $openSlotId = $findOpenSlotStmt->fetchColumn();
                    if ($openSlotId) {
                        $claimOpenSlotStmt->execute(['user_id' => $bestCandidate, 'id' => $openSlotId]);
                    } else {
                        $insertAssignedStmt->execute(['shift_id' => $shiftId, 'user_id' => $bestCandidate, 'work_date' => $dateKey]);
                    }

                    $busyByUserDate[$bestCandidate . '|' . $dateKey] = true;
                    $hoursByUser[$bestCandidate] = ($hoursByUser[$bestCandidate] ?? 0) + $shiftHours($shift);
                    $assignedCount++;
                }

                // Mark rest days chosen for this run, if a rest-kind template exists for the department
                // and the employee has no other assignment that day.
                foreach ($employeeIds as $employeeId) {
                    if (!in_array($weekday, $restWeekdaysByUser[$employeeId] ?? [], true)) {
                        continue;
                    }
                    if (!empty($busyByUserDate[$employeeId . '|' . $dateKey])) {
                        continue;
                    }
                    $restTemplateStmt = $pdo->prepare('SELECT id FROM shifts WHERE department_id = :department_id AND kind = "rest" ORDER BY id ASC LIMIT 1');
                    $restTemplateStmt->execute(['department_id' => $departmentId]);
                    $restShiftId = (int) ($restTemplateStmt->fetchColumn() ?: 0);
                    if ($restShiftId <= 0) {
                        continue;
                    }
                    $insertAssignedStmt->execute(['shift_id' => $restShiftId, 'user_id' => $employeeId, 'work_date' => $dateKey]);
                    $busyByUserDate[$employeeId . '|' . $dateKey] = true;
                    $restAssignedCount++;
                }
            }

            $employeeSummary = [];
            foreach ($employeeIds as $employeeId) {
                $employeeSummary[] = [
                    'user_id' => $employeeId,
                    'hours_in_period' => $hoursByUser[$employeeId] ?? 0,
                ];
            }

            jsonResponse([
                'ok' => true,
                'assigned_count' => $assignedCount,
                'rest_assigned_count' => $restAssignedCount,
                'conflicts' => $conflicts,
                'employee_summary' => $employeeSummary,
            ]);
            break;
        }

        default:
            jsonResponse(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
