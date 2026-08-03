<?php
/**
 * Settings modal module.
 *
 * This panel groups the management rubrics for departments, users, roles,
 * shifts and assignments inside one modal shell.
 */
$currentUser = currentUser();
$basePath = $basePath ?? (function () {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
$planner = $dashboardPlannerData ?? [];
$departments = is_array($planner['departments'] ?? null) ? $planner['departments'] : [];
$users = is_array($planner['users'] ?? null) ? $planner['users'] : [];
$plannerCompany = is_array($planner['company'] ?? null) ? $planner['company'] : [];
$scopeCompanyId = (int) ($plannerCompany['id'] ?? ($departments[0]['company_id'] ?? ($currentUser['company_id'] ?? 0)));
$scopeCompanyName = (string) ($plannerCompany['name'] ?? ($currentUser['company_name'] ?? 'StaffEase Pro'));
$scopeCompanyLogoPath = (string) ($plannerCompany['logo_path'] ?? '');
$scopeCompanySignatureIp = trim((string) ($plannerCompany['signature_ip'] ?? ''));
$scopeCompanies = is_array($planner['companies'] ?? null) ? $planner['companies'] : [];
$visibleUsers = $users;
$currentRole = $currentUser['role'] ?? '';
$settingsSimpleMode = true;
$commercialVideos = getCommercialVideos();
$canCreateDepartments = in_array($currentRole, ['super_admin', 'admin'], true);
$canCreateShifts = in_array($currentRole, ['super_admin', 'admin'], true);
$canManageDepartments = in_array($currentRole, ['super_admin', 'admin'], true);
$canManageShifts = in_array($currentRole, ['super_admin', 'admin'], true);
if ($currentRole === 'admin') {
    $visibleUsers = array_values(array_filter($users, static function($u) use ($scopeCompanyId) {
        $rowCompanyId = (int) ($u['company_id'] ?? 0);
        if ($scopeCompanyId > 0 && $rowCompanyId > 0 && $rowCompanyId !== $scopeCompanyId) {
            return false;
        }

        return (($u['role'] ?? '') !== 'super_admin');
    }));
}
$shifts = is_array($planner['shifts'] ?? null) ? $planner['shifts'] : [];
$visibleShifts = array_values(array_filter($shifts, static function (array $shift): bool {
    $kind = strtolower(trim((string) ($shift['kind'] ?? 'work')));
    $name = strtolower(trim((string) ($shift['name'] ?? '')));
    $isSystem = in_array($kind, ['rest', 'vacation', 'sick'], true)
        || in_array($name, ['rest day', 'vacation', 'sick leave', 'repos', 'vacances', 'maladie', 'riposo', 'ferie', 'malattia'], true);
    return !$isSystem;
}));
$visibleShiftGroupsMap = [];
foreach ($visibleShifts as $shiftRow) {
    $groupKey = strtolower(trim((string) ($shiftRow['kind'] ?? 'work'))) . '|' . trim((string) ($shiftRow['name'] ?? ''))
        . '|' . trim((string) ($shiftRow['start_time'] ?? '')) . '|' . trim((string) ($shiftRow['end_time'] ?? ''))
        . '|' . trim((string) ($shiftRow['icon'] ?? '')) . '|' . trim((string) ($shiftRow['color'] ?? ''))
        . '|' . trim((string) ($shiftRow['description'] ?? ''));

    if (!isset($visibleShiftGroupsMap[$groupKey])) {
        $visibleShiftGroupsMap[$groupKey] = [
            'shift' => $shiftRow,
            'shift_ids' => [],
            'departments' => [],
        ];
    }

    $visibleShiftGroupsMap[$groupKey]['shift_ids'][] = (int) ($shiftRow['id'] ?? 0);
    $departmentLabel = trim((string) ($shiftRow['department_name'] ?? ''));
    if ($departmentLabel !== '') {
        $visibleShiftGroupsMap[$groupKey]['departments'][$departmentLabel] = true;
    }
}
$visibleShiftGroups = array_values(array_map(static function (array $group): array {
    $group['shift_ids'] = array_values(array_filter(array_unique(array_map('intval', $group['shift_ids'] ?? []))));
    $group['departments'] = array_values(array_keys($group['departments'] ?? []));
    return $group;
}, $visibleShiftGroupsMap));
$assignments = is_array($planner['assignments'] ?? null) ? $planner['assignments'] : [];
$attendances = is_array($planner['attendances'] ?? null) ? $planner['attendances'] : [];
$roleLabels = [
    'super_admin' => t('roles.super_admin'),
    'admin' => t('roles.admin'),
    'department_manager' => t('roles.department_manager'),
    'employee' => t('roles.employee'),
];
$roleCatalog = [
    ['key' => 'super_admin', 'label' => t('roles.super_admin'), 'color' => '#1f2937', 'icon' => '🛡️'],
    ['key' => 'admin', 'label' => t('roles.admin'), 'color' => '#b98b12', 'icon' => '⚙'],
    ['key' => 'department_manager', 'label' => t('roles.department_manager'), 'color' => '#2f6fed', 'icon' => '👔'],
    ['key' => 'employee', 'label' => t('roles.employee'), 'color' => '#5b6472', 'icon' => '👤'],
];
$companyTypeRaw = (string) ($plannerCompany['type'] ?? ($currentUser['company_type'] ?? ''));
if ($companyTypeRaw === '' && $scopeCompanyId > 0 && !empty($scopeCompanies)) {
    foreach ($scopeCompanies as $companyOption) {
        if ((int) ($companyOption['id'] ?? 0) === $scopeCompanyId) {
            $companyTypeRaw = (string) ($companyOption['type'] ?? '');
            break;
        }
    }
}
$companyTypeNormalized = strtolower(trim($companyTypeRaw));
$companyDomain = 'generic';
if (
    str_contains($companyTypeNormalized, 'hospital')
    || str_contains($companyTypeNormalized, 'clinic')
    || str_contains($companyTypeNormalized, 'medical')
    || str_contains($companyTypeNormalized, 'health')
) {
    $companyDomain = 'healthcare';
} elseif (
    str_contains($companyTypeNormalized, 'hotel')
    || str_contains($companyTypeNormalized, 'resort')
    || str_contains($companyTypeNormalized, 'hospitality')
) {
    $companyDomain = 'hospitality';
}

$iconDir = __DIR__ . '/../../assets/icons';
$iconCatalog = [];
if (is_dir($iconDir)) {
    $entries = scandir($iconDir) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!preg_match('/\.(svg|png|jpe?g|gif|webp|ico)$/i', $entry)) {
            continue;
        }
        $iconCatalog[] = $entry;
    }
}
natsort($iconCatalog);
$iconCatalog = array_values($iconCatalog);
$preferredDefaultIcons = ['parasol.svg', 'setting.svg', 'calendar.svg', 'briefcase.svg', 'home.svg'];
$defaultPickerIcon = 'parasol.svg';
foreach ($preferredDefaultIcons as $preferredIcon) {
    if (in_array($preferredIcon, $iconCatalog, true)) {
        $defaultPickerIcon = $preferredIcon;
        break;
    }
}
if (empty($iconCatalog)) {
    $defaultPickerIcon = 'parasol.svg';
}

$iconUrl = static function (string $icon) use ($basePath): string {
    return $basePath . '/assets/icons/' . rawurlencode($icon);
};

$resolveCompanyLogoUrl = static function (?string $rawPath) use ($basePath): string {
    $path = trim((string) $rawPath);
    if ($path === '') {
        return '';
    }
    if (preg_match('/^(https?:)?\/\//i', $path) || str_starts_with($path, 'data:')) {
        return $path;
    }

    if (str_starts_with($path, 'uploads/')) {
        $path = 'public/' . $path;
    }

    return rtrim($basePath, '/') . '/' . ltrim($path, '/');
};

$isIconAsset = static function (string $icon): bool {
    return (bool) preg_match('/\.(svg|png|jpe?g|gif|webp|ico)$/i', $icon);
};

$iconLabel = static function (string $icon): string {
    $name = pathinfo($icon, PATHINFO_FILENAME);
    $name = str_replace(['-', '_'], ' ', $name);
    return ucwords(trim($name));
};

$renderGroupIcon = static function (?int $departmentId, string $iconValue, string $fallbackLabel = '') use ($iconUrl, $isIconAsset, $defaultPickerIcon, $basePath): string {
    $iconValue = trim($iconValue);
    if ($departmentId !== null && $departmentId > 0) {
        if ($iconValue === '') {
            $iconValue = $defaultPickerIcon;
        }
        if ($isIconAsset($iconValue)) {
            return '<img src="' . e($iconUrl($iconValue)) . '" alt="" aria-hidden="true" class="settings-icon-inline-image">';
        }

        return e($iconValue);
    }

    $ticketIcon = 'ticket.svg';
    if ($isIconAsset($ticketIcon)) {
        return '<img src="' . e($iconUrl($ticketIcon)) . '" alt="" aria-hidden="true" class="settings-icon-inline-image">';
    }

    return e($fallbackLabel !== '' ? $fallbackLabel : t('settings.unassigned'));
};

$localizedSystemShiftName = static function (string $kind, string $defaultName = ''): string {
    $kind = strtolower(trim($kind));
    if ($kind === 'rest') {
        return t('settings.rest');
    }
    if ($kind === 'vacation') {
        return t('settings.vacation');
    }
    if ($kind === 'sick') {
        return t('settings.sick');
    }

    return $defaultName;
};

$localeCode = strtolower((string) appLocale());
$isFrLocale = str_starts_with($localeCode, 'fr');
$isItLocale = str_starts_with($localeCode, 'it');
$weekdayLabels = $isFrLocale
    ? [
        0 => 'Dimanche',
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
    ]
    : ($isItLocale
        ? [
            0 => 'Domenica',
            1 => 'Lunedi',
            2 => 'Martedi',
            3 => 'Mercoledi',
            4 => 'Giovedi',
            5 => 'Venerdi',
            6 => 'Sabato',
        ]
    : [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ]);

$monthLabels = $isFrLocale
    ? [
        1 => 'Janvier', 2 => 'Fevrier', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Aout',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Decembre',
    ]
    : ($isItLocale
        ? [
            1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
            5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
            9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
        ]
    : [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ]);

$localizedShiftKindLabel = static function (string $kind): string {
    $kind = strtolower(trim($kind));
    if ($kind === 'rest') {
        return t('settings.rest');
    }
    if ($kind === 'vacation') {
        return t('settings.vacation');
    }
    if ($kind === 'sick') {
        return t('settings.sick');
    }
    if ($kind === 'work') {
        return t('settings.kind_work');
    }

    return ucfirst($kind !== '' ? $kind : 'work');
};

$localizedSystemShiftDescription = static function (string $kind, string $fallback = '') use ($isFrLocale, $isItLocale): string {
    $kind = strtolower(trim($kind));
    if ($kind === 'rest') {
        if ($isFrLocale) {
            return 'Modele systeme pour attribuer un jour de repos.';
        }
        if ($isItLocale) {
            return 'Modello di sistema per assegnare un giorno di riposo.';
        }
        return 'System template for rest day assignment.';
    }
    if ($kind === 'vacation') {
        if ($isFrLocale) {
            return 'Modele systeme pour attribuer des vacances.';
        }
        if ($isItLocale) {
            return 'Modello di sistema per assegnare ferie.';
        }
        return 'System template for vacation assignment.';
    }
    if ($kind === 'sick') {
        if ($isFrLocale) {
            return 'Modele systeme pour attribuer un conge maladie.';
        }
        if ($isItLocale) {
            return 'Modello di sistema per assegnare malattia.';
        }
        return 'System template for sick leave assignment.';
    }

    return $fallback;
};

$departmentIconCatalog = $iconCatalog;
$shiftIconCatalog = $iconCatalog;
$pickerColorCatalogMap = [
    '#fef3c7' => 'Champagne',
    '#fde68a' => 'Buttermilk',
    '#fca5a5' => 'Blush Red',
    '#fb7185' => 'Coral Rose',
    '#fda4af' => 'Soft Rose',
    '#fdba74' => 'Apricot',
    '#fed7aa' => 'Peach Cream',
    '#fcd34d' => 'Sun Gold',
    '#b98b12' => 'Warm Amber',
    '#d97706' => 'Golden Hour',
    '#f59e0b' => 'Sunbeam',
    '#fbbf24' => 'Honey',
    '#facc15' => 'Lemon Glow',
    '#d9f99d' => 'Spring Lime',
    '#84cc16' => 'Lime Spark',
    '#22c55e' => 'Fresh Green',
    '#16a34a' => 'Forest Green',
    '#4ade80' => 'Emerald Light',
    '#86efac' => 'Pistachio',
    '#10b981' => 'Mint',
    '#6ee7b7' => 'Mint Foam',
    '#14b8a6' => 'Teal Mist',
    '#0f766e' => 'Deep Teal',
    '#99f6e4' => 'Seafoam',
    '#5eead4' => 'Aqua Mint',
    '#06b6d4' => 'Sky Tide',
    '#0891b2' => 'Ocean Blue',
    '#0ea5e9' => 'Clear Blue',
    '#38bdf8' => 'Sky Glow',
    '#7dd3fc' => 'Ice Blue',
    '#3b82f6' => 'Bright Blue',
    '#2f6fed' => 'Royal Blue',
    '#60a5fa' => 'Azure',
    '#93c5fd' => 'Powder Blue',
    '#6366f1' => 'Indigo Pulse',
    '#4f46e5' => 'Blue Violet',
    '#7c3aed' => 'Violet Bloom',
    '#a855f7' => 'Lavender',
    '#c4b5fd' => 'Lilac Haze',
    '#ddd6fe' => 'Soft Violet',
    '#d946ef' => 'Orchid',
    '#ec4899' => 'Rose Bloom',
    '#f9a8d4' => 'Pink Silk',
    '#fbcfe8' => 'Petal Pink',
    '#be123c' => 'Crimson',
    '#ef4444' => 'Vivid Red',
    '#f97316' => 'Tangerine',
    '#ea580c' => 'Ember',
    '#c2410c' => 'Burnt Orange',
    '#e7e5e4' => 'Pebble',
    '#d6d3d1' => 'Clay Gray',
    '#374151' => 'Slate',
    '#475569' => 'Steel',
    '#64748b' => 'Mist Gray',
    '#6b7280' => 'Stone Gray',
    '#1f2937' => 'Midnight',
    '#111827' => 'Ink',
];
$pickerColorCatalog = array_keys($pickerColorCatalogMap);
$pickerColorLabel = static function (string $color) use ($pickerColorCatalogMap): string {
    return $pickerColorCatalogMap[$color] ?? $color;
};
$activeDepartment = null;
if (!empty($departments)) {
    foreach ($departments as $department) {
        if ((int) ($department['id'] ?? 0) === (int) ($planner['active_department_id'] ?? 0)) {
            $activeDepartment = $department;
            break;
        }
    }
    if (!$activeDepartment) {
        $activeDepartment = $departments[0];
    }
}
$activeDepartmentAssignments = [];
if ($activeDepartment) {
    $activeDepartmentAssignments = array_values(array_filter(
        $assignments,
        static fn (array $assignment): bool => (int) ($assignment['department_id'] ?? 0) === (int) ($activeDepartment['id'] ?? 0)
    ));
}
$assignmentCountByDepartment = [];
foreach ($assignments as $assignment) {
    $deptId = (int) ($assignment['department_id'] ?? 0);
    $assignmentCountByDepartment[$deptId] = ($assignmentCountByDepartment[$deptId] ?? 0) + 1;
}

$visibleDepartments = $departments;
if (($currentRole ?? '') === 'admin') {
    $visibleDepartments = array_values(array_filter($departments, static fn($d) => (int) ($d['company_id'] ?? 0) === $scopeCompanyId));
}

$activeAssignmentStatuses = ['assigned', 'in_progress', 'completed'];
$durationHoursForTimes = static function (?string $startTime, ?string $endTime): float {
    if (!$startTime || !$endTime) {
        return 0.0;
    }

    $startParts = explode(':', $startTime);
    $endParts = explode(':', $endTime);
    if (count($startParts) < 2 || count($endParts) < 2) {
        return 0.0;
    }

    $startMinutes = ((int) $startParts[0] * 60) + (int) $startParts[1];
    $endMinutes = ((int) $endParts[0] * 60) + (int) $endParts[1];
    $delta = $endMinutes - $startMinutes;
    if ($delta <= 0) {
        $delta += 24 * 60;
    }

    return round($delta / 60, 2);
};

$shiftById = [];
foreach ($shifts as $shift) {
    $shiftById[(int) ($shift['id'] ?? 0)] = $shift;
}

$currentMonthPrefix = date('Y-m');
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');
$currentMonthLabel = date('F Y');
$todayDate = date('Y-m-d');
$assignmentsCurrentMonth = array_values(array_filter(
    $assignments,
    static fn(array $assignment): bool => str_starts_with((string) ($assignment['work_date'] ?? ''), $currentMonthPrefix)
));

$assignmentTotals = [
    'total' => count($assignmentsCurrentMonth),
    'active' => 0,
    'cancelled' => 0,
    'assigned_hours' => 0.0,
    'covered_days' => 0,
    'days_range' => 0,
    'unassigned_shift_templates' => 0,
];
$assignmentRangeStart = '';
$assignmentRangeEnd = '';
$coveredDates = [];
$coverageByDepartment = [];
$departmentMetrics = [];
$userWorkloadMap = [];
$usedShiftIds = [];

foreach ($visibleDepartments as $department) {
    $deptId = (int) ($department['id'] ?? 0);
    if ($deptId <= 0) {
        continue;
    }
    $departmentMetrics[$deptId] = [
        'department_id' => $deptId,
        'department_name' => (string) ($department['name'] ?? t('settings.department_default')),
        'department_icon' => (string) ($department['icon'] ?? $defaultPickerIcon),
        'department_color' => (string) ($department['color'] ?? '#b98b12'),
        'assignments' => 0,
        'active_assignments' => 0,
        'hours' => 0.0,
        'uncovered_days' => 0,
    ];
}

foreach ($assignmentsCurrentMonth as $assignment) {
    $workDate = (string) ($assignment['work_date'] ?? '');
    $status = (string) ($assignment['status'] ?? 'assigned');
    $isActiveAssignment = in_array($status, $activeAssignmentStatuses, true);
    $deptId = (int) ($assignment['department_id'] ?? 0);
    $userId = (int) ($assignment['user_id'] ?? 0);
    $shiftId = (int) ($assignment['shift_id'] ?? 0);
    $shiftName = (string) ($assignment['shift_name'] ?? t('settings.shift_default'));
    $shiftStart = (string) ($assignment['start_time'] ?? ($shiftById[$shiftId]['start_time'] ?? ''));
    $shiftEnd = (string) ($assignment['end_time'] ?? ($shiftById[$shiftId]['end_time'] ?? ''));
    $durationHours = $durationHoursForTimes($shiftStart, $shiftEnd);

    if ($workDate !== '') {
        if ($assignmentRangeStart === '' || $workDate < $assignmentRangeStart) {
            $assignmentRangeStart = $workDate;
        }
        if ($assignmentRangeEnd === '' || $workDate > $assignmentRangeEnd) {
            $assignmentRangeEnd = $workDate;
        }
    }

    if (isset($departmentMetrics[$deptId])) {
        $departmentMetrics[$deptId]['assignments']++;
    }

    if (!$isActiveAssignment) {
        $assignmentTotals['cancelled']++;
        continue;
    }

    $assignmentTotals['active']++;
    $assignmentTotals['assigned_hours'] += $durationHours;
    if ($workDate !== '') {
        $coveredDates[$workDate] = true;
        if (!isset($coverageByDepartment[$deptId])) {
            $coverageByDepartment[$deptId] = [];
        }
        $coverageByDepartment[$deptId][$workDate] = true;
    }

    if ($shiftId > 0) {
        $usedShiftIds[$shiftId] = true;
    }

    if (isset($departmentMetrics[$deptId])) {
        $departmentMetrics[$deptId]['active_assignments']++;
        $departmentMetrics[$deptId]['hours'] += $durationHours;
    }

    if ($userId > 0) {
        if (!isset($userWorkloadMap[$userId])) {
            $userWorkloadMap[$userId] = [
                'user_id' => $userId,
                'user_name' => (string) ($assignment['user_name'] ?? t('settings.user_default')),
                'assignments' => 0,
                'hours' => 0.0,
                'days' => [],
                'shifts' => [],
            ];
        }
        $userWorkloadMap[$userId]['assignments']++;
        $userWorkloadMap[$userId]['hours'] += $durationHours;
        if ($workDate !== '') {
            $userWorkloadMap[$userId]['days'][$workDate] = true;
        }
        if (!isset($userWorkloadMap[$userId]['shifts'][$shiftName])) {
            $userWorkloadMap[$userId]['shifts'][$shiftName] = 0;
        }
        $userWorkloadMap[$userId]['shifts'][$shiftName]++;
    }
}

if ($assignmentRangeStart === '' || $assignmentRangeEnd === '') {
    $assignmentRangeStart = $todayDate;
    $assignmentRangeEnd = $currentMonthEnd;
}

$assignmentRangeStart = max($assignmentRangeStart, $currentMonthStart);
$assignmentRangeEnd = max($assignmentRangeStart, $assignmentRangeEnd);
$autoAssignDefaultStart = max($todayDate, $currentMonthStart);
$autoAssignDefaultEnd = max($autoAssignDefaultStart, $currentMonthEnd);

$periodStart = new DateTimeImmutable($assignmentRangeStart);
$periodEnd = new DateTimeImmutable($assignmentRangeEnd);
$periodEndExclusive = $periodEnd->modify('+1 day');
$periodDays = (int) $periodStart->diff($periodEnd)->days + 1;
$assignmentTotals['days_range'] = max(1, $periodDays);
$assignmentTotals['covered_days'] = count($coveredDates);
$assignmentTotals['unassigned_shift_templates'] = max(0, count($shifts) - count($usedShiftIds));

$dayKeysInRange = [];
foreach (new DatePeriod($periodStart, new DateInterval('P1D'), $periodEndExclusive) as $date) {
    $dayKeysInRange[] = $date->format('Y-m-d');
}
foreach ($departmentMetrics as $deptId => $metric) {
    $uncoveredCount = 0;
    foreach ($dayKeysInRange as $dayKey) {
        if (!isset($coverageByDepartment[$deptId][$dayKey])) {
            $uncoveredCount++;
        }
    }
    $departmentMetrics[$deptId]['uncovered_days'] = $uncoveredCount;
}

$departmentCoverageRows = array_values($departmentMetrics);
usort($departmentCoverageRows, static function (array $a, array $b): int {
    return $b['uncovered_days'] <=> $a['uncovered_days'];
});

$userWorkloadRows = array_values($userWorkloadMap);
foreach ($userWorkloadRows as &$workloadRow) {
    $workloadRow['hours'] = round((float) $workloadRow['hours'], 2);
    $workloadRow['days_count'] = count($workloadRow['days']);
    arsort($workloadRow['shifts']);
    $shiftChunks = [];
    foreach ($workloadRow['shifts'] as $shiftName => $count) {
        $shiftChunks[] = $shiftName . ' (' . $count . ')';
        if (count($shiftChunks) >= 3) {
            break;
        }
    }
    $workloadRow['shift_preview'] = implode(', ', $shiftChunks);
}
unset($workloadRow);
usort($userWorkloadRows, static function (array $a, array $b): int {
    if ($a['hours'] === $b['hours']) {
        return $b['assignments'] <=> $a['assignments'];
    }

    return $b['hours'] <=> $a['hours'];
});

$assignmentTotals['assigned_hours'] = round((float) $assignmentTotals['assigned_hours'], 2);
$openAssignmentsCount = count(array_filter(
    $assignmentsCurrentMonth,
    static fn(array $assignment): bool => (int) ($assignment['user_id'] ?? 0) <= 0 || (($assignment['status'] ?? '') === 'open')
));

$attendanceByUser = [];
foreach ($visibleUsers as $attendanceUser) {
    $attendanceByUser[(int) ($attendanceUser['id'] ?? 0)] = 0;
}
foreach ($attendances as $attendanceRow) {
    $attendanceUserId = (int) ($attendanceRow['user_id'] ?? 0);
    if ($attendanceUserId > 0 && isset($attendanceByUser[$attendanceUserId])) {
        $attendanceByUser[$attendanceUserId]++;
    }
}

$attendanceAssignableShifts = array_values(array_filter(
    $assignmentsCurrentMonth,
    static fn(array $assignment): bool => (int) ($assignment['user_id'] ?? 0) > 0
        && !in_array((string) ($assignment['status'] ?? ''), ['cancelled', 'open'], true)
));

$employeeAssignmentStats = [];
foreach ($visibleUsers as $userStat) {
    $statUserId = (int) ($userStat['id'] ?? 0);
    if ($statUserId <= 0) {
        continue;
    }
    $employeeAssignmentStats[$statUserId] = [
        'assigned' => 0,
        'sick' => 0,
        'vacation' => 0,
        'rest' => 0,
    ];
}

foreach ($assignmentsCurrentMonth as $assignmentStat) {
    $statUserId = (int) ($assignmentStat['user_id'] ?? 0);
    if ($statUserId <= 0 || !isset($employeeAssignmentStats[$statUserId])) {
        continue;
    }

    $statusValue = (string) ($assignmentStat['status'] ?? 'assigned');
    if (in_array($statusValue, ['cancelled', 'open'], true)) {
        continue;
    }

    $employeeAssignmentStats[$statUserId]['assigned']++;
    $kindValue = strtolower((string) ($assignmentStat['shift_kind'] ?? 'work'));
    if ($kindValue === 'sick') {
        $employeeAssignmentStats[$statUserId]['sick']++;
    } elseif ($kindValue === 'vacation') {
        $employeeAssignmentStats[$statUserId]['vacation']++;
    } elseif ($kindValue === 'rest') {
        $employeeAssignmentStats[$statUserId]['rest']++;
    }
}

$totalUncoveredByDepartment = 0;
foreach ($departmentCoverageRows as $coverageRow) {
    $totalUncoveredByDepartment += (int) ($coverageRow['uncovered_days'] ?? 0);
}

$departmentCreateHeadUsers = array_values(array_filter(
    $visibleUsers,
    static fn(array $u): bool => ((int) ($u['company_id'] ?? 0) === $scopeCompanyId) && ((int) ($u['department_id'] ?? 0) === 0)
));
?>
<section class="dashboard-modal dashboard-settings-modal" id="modal-settings" hidden>
    <div class="crud-modal-card">
        <button type="button" class="dashboard-modal-close" data-modal-close aria-label="<?php echo e(t('settings.close')); ?>">&times;</button>

        <div class="crud-modal-head settings-modal-head">
            <div>
                <h2 id="settings-modal-title"><?php echo e(t('settings.title')); ?></h2>
                <p id="settings-modal-subtitle" class="crud-modal-subtitle"><?php echo e(t('settings.subtitle')); ?></p>
            </div>
            <?php $scopeCompanyLogoUrl = $resolveCompanyLogoUrl($scopeCompanyLogoPath); ?>
            <div class="settings-summary settings-summary--compact">
                <div class="settings-summary-card">
                    <span class="settings-summary-label"><?php echo e(t('settings.company')); ?></span>
                    <strong class="settings-company-identity">
                        <?php if ($scopeCompanyLogoUrl !== ''): ?>
                            <img src="<?php echo e($scopeCompanyLogoUrl); ?>" alt="" class="settings-company-logo-inline" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <span><?php echo e($scopeCompanyName); ?></span>
                    </strong>
                </div>
                <div class="settings-summary-card">
                    <span class="settings-summary-label"><?php echo e(t('settings.departments')); ?></span>
                    <strong><?php echo count($departments); ?></strong>
                </div>
                <div class="settings-summary-card">
                    <span class="settings-summary-label"><?php echo e(t('settings.users')); ?></span>
                    <strong><?php echo count($visibleUsers); ?></strong>
                </div>
                <div class="settings-summary-card">
                    <span class="settings-summary-label"><?php echo e(t('settings.shifts')); ?></span>
                    <strong><?php echo count($shifts); ?></strong>
                </div>
            </div>

            <?php if ($currentRole === 'super_admin' && !empty($scopeCompanies)): ?>
                <form method="get" class="settings-company-switcher">
                    <input type="hidden" name="route" value="dashboard">
                    <input type="hidden" name="modal" value="settings">
                    <input type="hidden" name="settings_tab" value="" data-settings-tab-input>
                    <label class="settings-field">
                        <?php echo e(t('settings.company_in_settings')); ?>
                        <select name="settings_company_id" data-settings-company-select>
                            <?php foreach ($scopeCompanies as $company): ?>
                                <option value="<?php echo (int) ($company['id'] ?? 0); ?>" <?php echo ((int) ($company['id'] ?? 0) === $scopeCompanyId) ? 'selected' : ''; ?>>
                                    <?php echo e($company['name'] ?? 'Company'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            <?php endif; ?>
        </div>

        <div class="settings-tabs" role="tablist" aria-label="<?php echo e(t('settings.management_rubrics')); ?>">
            <?php if ($currentRole === 'super_admin'): ?>
                <button type="button" class="settings-tab" data-settings-tab="companies"><?php echo e(t('common.companies')); ?></button>
                <button type="button" class="settings-tab" data-settings-tab="commercial-videos"><?php echo e(t('settings.commercial_videos')); ?></button>
            <?php endif; ?>
            <button type="button" class="settings-tab" data-settings-tab="users"><?php echo e(t('settings.users')); ?></button>
            <?php if ($currentRole !== 'department_manager'): ?>
                <button type="button" class="settings-tab" data-settings-tab="departments"><?php echo e(t('settings.departments')); ?></button>
            <?php endif; ?>
            <button type="button" class="settings-tab" data-settings-tab="shifts"><?php echo e(t('settings.shifts')); ?></button>
            <?php if (!$settingsSimpleMode): ?>
                <button type="button" class="settings-tab" data-settings-tab="assignments"><?php echo e(t('settings.assignments')); ?></button>
            <?php endif; ?>
            <button type="button" class="settings-tab" data-settings-tab="assignment-planner"><?php echo e(t('settings.assignment_planner', ['fallback' => 'Shift Planner'])); ?></button>
            <button type="button" class="settings-tab" data-settings-tab="attendances"><?php echo e(t('settings.attendances')); ?></button>
        </div>

        <div class="crud-modal-body settings-modal-body">
            <?php if ($currentRole === 'super_admin'): ?>
            <section class="crud-panel settings-panel" data-settings-panel="companies" hidden>
                <div class="settings-panel-head">
                    <div>
                        <h3><?php echo e(t('common.companies')); ?></h3>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.companies_hint')); ?></p>
                    </div>
                    <div class="settings-pill-row">
                        <span class="settings-pill"><?php echo count($scopeCompanies); ?> <?php echo e(t('settings.items')); ?></span>
                    </div>
                </div>

                <div class="settings-list-head settings-create-row" data-company-create-row>
                    <div class="settings-list-cols settings-list-cols-company">
                        <label class="settings-field"><?php echo e(t('settings.name_label')); ?><input data-field="name" type="text" value=""></label>
                        <label class="settings-field"><?php echo e(t('crud.type')); ?>
                            <select data-field="type">
                                <option value="hotel"><?php echo e(t('crud.company_type_hotel')); ?></option>
                                <option value="hospital"><?php echo e(t('crud.company_type_hospital')); ?></option>
                                <option value="clinic"><?php echo e(t('crud.company_type_clinic')); ?></option>
                                <option value="elderly_center"><?php echo e(t('crud.company_type_elderly_center')); ?></option>
                                <option value="restaurant"><?php echo e(t('crud.company_type_restaurant')); ?></option>
                                <option value="other" selected><?php echo e(t('crud.company_type_other')); ?></option>
                            </select>
                        </label>
                        <label class="settings-field"><?php echo e(t('crud.address')); ?><input data-field="address" type="text" value=""></label>
                        <label class="settings-field"><?php echo e(t('crud.city')); ?><input data-field="city" type="text" value=""></label>
                        <label class="settings-field"><?php echo e(t('crud.zip_code')); ?><input data-field="zip_code" type="text" value=""></label>
                        <label class="settings-field"><?php echo e(t('crud.phone')); ?><input data-field="phone" type="text" value=""></label>
                        <label class="settings-field"><?php echo e(t('crud.email')); ?><input data-field="email" type="email" value=""></label>
                        <label class="settings-field"><?php echo e(t('settings.logo_file')); ?><input data-field="logo_file" type="file" accept="image/*"></label>
                        <label class="settings-field"><?php echo e(t('settings.logo_path_optional')); ?><input data-field="logo_path" type="text" value=""></label>
                        <label class="settings-field"><?php echo e(t('settings.authorized_wifi_ip')); ?><input data-field="signature_ip" type="text" value=""></label>
                        <div class="settings-inline-actions">
                            <button type="button" class="admin-action-link settings-company-create"><?php echo e(t('crud.create')); ?></button>
                            <button type="button" class="admin-action-link admin-action-link-secondary settings-company-reset"><?php echo e(t('crud.reset')); ?></button>
                        </div>
                    </div>
                </div>

                <div class="settings-list-wrap">
                    <div class="settings-list-row settings-list-header settings-list-cols settings-list-cols-company">
                        <strong><?php echo e(t('settings.name_label')); ?></strong>
                        <span><?php echo e(t('crud.type')); ?></span>
                        <span><?php echo e(t('crud.city')); ?></span>
                        <span><?php echo e(t('crud.email')); ?></span>
                        <span><?php echo e(t('crud.phone')); ?></span>
                        <span><?php echo e(t('common.status')); ?></span>
                        <span><?php echo e(t('settings.action_label')); ?></span>
                    </div>

                    <?php if (empty($scopeCompanies)): ?>
                        <div class="crud-empty-state"><?php echo e(t('settings.no_companies_available')); ?></div>
                    <?php else: ?>
                        <?php foreach ($scopeCompanies as $company): ?>
                            <?php $companyLogoUrl = $resolveCompanyLogoUrl((string) ($company['logo_path'] ?? '')); ?>
                            <article class="settings-list-item-wrap" data-company-id="<?php echo (int) ($company['id'] ?? 0); ?>">
                                <div class="settings-list-row settings-list-cols settings-list-cols-company">
                                    <strong class="settings-company-identity">
                                        <?php if ($companyLogoUrl !== ''): ?>
                                            <img src="<?php echo e($companyLogoUrl); ?>" alt="" class="settings-company-logo-inline" loading="lazy" decoding="async">
                                        <?php endif; ?>
                                        <span><?php echo e($company['name'] ?? 'Company'); ?></span>
                                    </strong>
                                    <?php $companyTypeValue = (string) ($company['type'] ?? 'other'); ?>
                                    <span><?php echo e(in_array($companyTypeValue, ['hotel', 'hospital', 'clinic', 'elderly_center', 'restaurant', 'other'], true) ? t('crud.company_type_' . $companyTypeValue) : $companyTypeValue); ?></span>
                                    <span><?php echo e($company['city'] ?? '--'); ?></span>
                                    <span><?php echo e($company['email'] ?? '--'); ?></span>
                                    <span><?php echo e($company['phone'] ?? '--'); ?></span>
                                    <span><?php echo ((int) ($company['is_active'] ?? 1) === 1) ? e(t('crud.active')) : e(t('crud.inactive')); ?></span>
                                    <div class="settings-inline-actions">
                                        <?php if ($currentRole === 'super_admin'): ?>
                                            <button type="button"
                                                    class="admin-action-link admin-action-link-secondary settings-company-toggle"
                                                    data-company-active="<?php echo (int) ($company['is_active'] ?? 1); ?>">
                                                <?php echo ((int) ($company['is_active'] ?? 1) === 1) ? e(t('settings.company_deactivate')) : e(t('settings.company_activate')); ?>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="admin-action-link admin-action-link-secondary settings-company-edit"><?php echo e(t('settings.edit')); ?></button>
                                        <button type="button" class="admin-action-link admin-action-link-secondary settings-action-icon-danger settings-company-delete"><?php echo e(t('schedule.delete')); ?></button>
                                    </div>
                                </div>
                                <div class="settings-edit-drawer" hidden>
                                    <div class="settings-list-cols settings-list-cols-company-edit">
                                        <label class="settings-field"><?php echo e(t('settings.name_label')); ?><input data-field="name" type="text" value="<?php echo e($company['name'] ?? ''); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('crud.type')); ?>
                                            <select data-field="type">
                                                <?php $companyType = (string) ($company['type'] ?? 'other'); ?>
                                                <option value="hotel" <?php echo $companyType === 'hotel' ? 'selected' : ''; ?>><?php echo e(t('crud.company_type_hotel')); ?></option>
                                                <option value="hospital" <?php echo $companyType === 'hospital' ? 'selected' : ''; ?>><?php echo e(t('crud.company_type_hospital')); ?></option>
                                                <option value="clinic" <?php echo $companyType === 'clinic' ? 'selected' : ''; ?>><?php echo e(t('crud.company_type_clinic')); ?></option>
                                                <option value="elderly_center" <?php echo $companyType === 'elderly_center' ? 'selected' : ''; ?>><?php echo e(t('crud.company_type_elderly_center')); ?></option>
                                                <option value="restaurant" <?php echo $companyType === 'restaurant' ? 'selected' : ''; ?>><?php echo e(t('crud.company_type_restaurant')); ?></option>
                                                <option value="other" <?php echo $companyType === 'other' ? 'selected' : ''; ?>><?php echo e(t('crud.company_type_other')); ?></option>
                                            </select>
                                        </label>
                                        <label class="settings-field"><?php echo e(t('crud.address')); ?><input data-field="address" type="text" value="<?php echo e($company['address'] ?? ''); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('crud.city')); ?><input data-field="city" type="text" value="<?php echo e($company['city'] ?? ''); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('crud.zip_code')); ?><input data-field="zip_code" type="text" value="<?php echo e($company['zip_code'] ?? ''); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('crud.phone')); ?><input data-field="phone" type="text" value="<?php echo e($company['phone'] ?? ''); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('crud.email')); ?><input data-field="email" type="email" value="<?php echo e($company['email'] ?? ''); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('settings.logo_file')); ?><input data-field="logo_file" type="file" accept="image/*"></label>
                                        <label class="settings-field"><?php echo e(t('settings.logo_path_optional')); ?><input data-field="logo_path" type="text" value="<?php echo e($company['logo_path'] ?? ''); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('settings.authorized_wifi_ip')); ?><input data-field="signature_ip" type="text" value="<?php echo e($company['signature_ip'] ?? ''); ?>"></label>
                                        <div class="settings-inline-actions">
                                            <button type="button" class="admin-action-link settings-company-save"><?php echo e(t('settings.save')); ?></button>
                                            <button type="button" class="admin-action-link admin-action-link-secondary settings-company-cancel"><?php echo e(t('employee.cancel')); ?></button>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </section>
            <?php endif; ?>

            <?php if ($currentRole === 'super_admin'): ?>
            <section class="crud-panel settings-panel" data-settings-panel="commercial-videos" hidden>
                <div class="settings-panel-head">
                    <div>
                        <h3><?php echo e(t('settings.commercial_videos')); ?></h3>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.commercial_videos_subtitle')); ?></p>
                    </div>
                    <div class="settings-pill-row">
                        <span class="settings-pill"><?php echo count($commercialVideos); ?> <?php echo e(t('settings.items')); ?></span>
                    </div>
                </div>

                <div class="settings-list-wrap" data-commercial-videos-form>
                    <?php foreach ($commercialVideos as $index => $video): ?>
                        <article class="settings-list-item-wrap" data-commercial-video-row data-commercial-video-index="<?php echo (int) $index; ?>">
                            <div class="settings-list-row settings-list-cols settings-list-cols-company-edit">
                                <strong><?php echo e(t('settings.commercial_video_item', ['fallback' => 'Video']) . ' ' . (string) ((int) $index + 1)); ?></strong>
                                <label class="settings-field"><?php echo e(t('settings.commercial_video_title')); ?><input data-field="title" type="text" value="<?php echo e($video['title'] ?? ''); ?>"></label>
                                <label class="settings-field"><?php echo e(t('settings.commercial_video_url')); ?><input data-field="url" type="url" value="<?php echo e($video['url'] ?? ''); ?>"></label>
                                <a class="admin-action-link admin-action-link-secondary" href="<?php echo e($video['url'] ?? '#'); ?>" target="_blank" rel="noopener noreferrer"><?php echo e(t('settings.commercial_video_preview')); ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="settings-inline-actions" style="margin-top: 0.75rem;">
                    <button type="button" class="admin-action-link settings-commercial-videos-save"><?php echo e(t('settings.save')); ?></button>
                </div>
            </section>
            <?php endif; ?>

            <section class="crud-panel settings-panel" data-settings-panel="assignment-planner" hidden>
                <?php
                    $plannerAutoAssignableShiftOptions = [];
                    foreach ($shifts as $shift) {
                        $shiftOptionKindRaw = strtolower(trim((string) ($shift['kind'] ?? 'work')));
                        $shiftOptionNameRaw = strtolower(trim((string) ($shift['name'] ?? '')));
                                $isWorkAssignable = $shiftOptionKindRaw === 'work'
                                    && !in_array($shiftOptionNameRaw, ['rest day', 'vacation', 'sick leave', 'repos', 'vacances', 'maladie', 'riposo', 'ferie', 'malattia'], true);
                        if (!$isWorkAssignable) {
                            continue;
                        }
                        $plannerAutoAssignableShiftOptions[] = $shift;
                    }

                    $userWorkloadById = [];
                    foreach ($userWorkloadRows as $workloadRow) {
                        $userWorkloadById[(int) ($workloadRow['user_id'] ?? 0)] = $workloadRow;
                    }
                ?>
                <div class="settings-panel-head">
                    <div>
                        <h3><?php echo e(t('settings.assignment_planner_title', ['fallback' => 'Advanced shift assignment planner'])); ?></h3>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.assignment_planner_subtitle', ['fallback' => 'Set scope, rest policies, and shift mix, then run automatic assignment with one action.'])); ?></p>
                    </div>
                    <div class="settings-pill-row">
                        <span class="settings-pill"><?php echo e(t('settings.open_slot')); ?>: <?php echo (int) $openAssignmentsCount; ?></span>
                        <span class="settings-pill"><?php echo e(t('settings.uncovered_days')); ?>: <?php echo (int) $totalUncoveredByDepartment; ?></span>
                        <span class="settings-pill"><?php echo e(t('settings.assignments_metric')); ?>: <?php echo (int) ($assignmentTotals['active'] ?? 0); ?></span>
                    </div>
                </div>

                <div class="settings-create-row settings-auto-assign-row settings-assignment-planner-row" data-assignment-planner-panel>
                    <div class="settings-list-cols settings-list-cols-shift-create">
                        <div class="settings-field settings-field-span-all">
                            <label class="settings-field">
                                <?php echo e(t('settings.assignment_scope', ['fallback' => 'Assignment scope'])); ?>
                                <select data-assignment-planner-scope>
                                    <option value="department"><?php echo e(t('settings.assignment_scope_department', ['fallback' => 'Whole department'])); ?></option>
                                    <option value="employee"><?php echo e(t('settings.assignment_scope_employee', ['fallback' => 'Single employee'])); ?></option>
                                    <option value="company"><?php echo e(t('settings.assignment_scope_company', ['fallback' => 'All accessible departments'])); ?></option>
                                </select>
                            </label>
                        </div>

                        <label class="settings-field" data-assignment-planner-department-wrap>
                            <?php echo e(t('common.department')); ?>
                            <select data-assignment-planner-department>
                                <?php foreach ($visibleDepartments as $department): ?>
                                    <option value="<?php echo (int) ($department['id'] ?? 0); ?>"><?php echo e($department['name'] ?? t('settings.department_default')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="settings-field" data-assignment-planner-user-wrap hidden>
                            <?php echo e(t('settings.employee_label', ['fallback' => 'Employee'])); ?>
                            <select data-assignment-planner-user>
                                <option value=""><?php echo e(t('settings.select_employee_hint', ['fallback' => 'Select employee'])); ?></option>
                                <?php foreach ($visibleUsers as $userOption): ?>
                                    <?php
                                        $plannerUserId = (int) ($userOption['id'] ?? 0);
                                        $plannerUserName = trim((string) (($userOption['first_name'] ?? '') . ' ' . ($userOption['last_name'] ?? '')));
                                        if ($plannerUserName === '') {
                                            $plannerUserName = (string) ($userOption['email'] ?? ('User #' . $plannerUserId));
                                        }
                                    ?>
                                    <option value="<?php echo $plannerUserId; ?>" data-department-id="<?php echo (int) ($userOption['department_id'] ?? 0); ?>"><?php echo e($plannerUserName); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="settings-field">
                            <?php echo e(t('settings.from_date')); ?>
                            <input data-assignment-planner-range-start type="date" value="<?php echo e($currentMonthStart); ?>" min="<?php echo e($currentMonthStart); ?>">
                        </label>
                        <label class="settings-field">
                            <?php echo e(t('settings.to_date')); ?>
                            <input data-assignment-planner-range-end type="date" value="<?php echo e($currentMonthEnd); ?>" min="<?php echo e($currentMonthStart); ?>">
                        </label>
                        <label class="settings-field">
                            <?php echo e(t('settings.minimum_employees')); ?>
                            <input data-assignment-planner-min-employees type="number" min="0" step="1" value="1">
                        </label>
                        <label class="settings-field">
                            <?php echo e(t('settings.maximum_employees')); ?>
                            <input data-assignment-planner-max-employees type="number" min="1" step="1" value="3">
                        </label>

                        <label class="settings-field">
                            <?php echo e(t('settings.rest_distribution_mode', ['fallback' => 'Rest distribution'])); ?>
                            <select data-assignment-planner-rest-mode>
                                <option value="fixed"><?php echo e(t('settings.fixed_rest_days', ['fallback' => 'Fixed weekly rest'])); ?></option>
                                <option value="staggered"><?php echo e(t('settings.staggered_rest_days', ['fallback' => 'Staggered rest'])); ?></option>
                                <option value="random"><?php echo e(t('settings.mixed_rest_days', ['fallback' => 'Mixed/random rest'])); ?></option>
                            </select>
                        </label>
                        <label class="settings-field"><?php echo e(t('settings.min_rest_days_week')); ?><input data-assignment-planner-min-rest-days type="number" min="0" max="6" step="1" value="1"></label>
                        <label class="settings-field"><?php echo e(t('settings.max_rest_days_week')); ?><input data-assignment-planner-max-rest-days type="number" min="0" max="6" step="1" value="2"></label>
                        <label class="settings-field"><?php echo e(t('settings.min_work_days_week')); ?><input data-assignment-planner-min-work-days type="number" min="1" max="7" step="1" value="4"></label>
                        <label class="settings-field"><?php echo e(t('settings.max_work_days_week')); ?><input data-assignment-planner-max-work-days type="number" min="1" max="7" step="1" value="6"></label>

                        <div class="settings-field settings-field-span-all">
                            <span class="settings-summary-label"><?php echo e(t('settings.select_shift_templates', ['fallback' => 'Shift templates to include'])); ?></span>
                            <div class="settings-assignment-open-shift-list settings-assignment-planner-shift-list" data-assignment-planner-shift-list>
                                <?php if (empty($plannerAutoAssignableShiftOptions)): ?>
                                    <div class="crud-empty-state"><?php echo e(t('settings.no_shifts_available')); ?></div>
                                <?php else: ?>
                                    <?php foreach ($plannerAutoAssignableShiftOptions as $shiftOption): ?>
                                        <label class="settings-assignment-open-shift-chip">
                                            <input type="checkbox" data-assignment-planner-shift-id="<?php echo (int) ($shiftOption['id'] ?? 0); ?>" checked>
                                            <span>
                                                <?php echo e((string) ($shiftOption['name'] ?? t('settings.shift_default'))); ?>
                                                <small><?php echo e((string) ($shiftOption['department_name'] ?? t('settings.department_default'))); ?></small>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="settings-inline-actions settings-field-span-all">
                            <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-planner-forecast><?php echo e(t('settings.auto_assign_forecast_title', ['fallback' => 'Preview coverage impact'])); ?></button>
                            <button type="button" class="admin-action-link" data-assignment-planner-run><?php echo e(t('settings.run_auto_assign', ['fallback' => 'Run auto-assignment'])); ?></button>
                        </div>

                        <div class="settings-auto-assign-forecast settings-field-span-all" data-assignment-planner-forecast-box>
                            <strong><?php echo e(t('settings.coverage_overview', ['fallback' => 'Coverage overview'])); ?></strong>
                            <p class="crud-modal-subtitle" data-assignment-planner-forecast-summary><?php echo e(t('settings.auto_assign_forecast_loading', ['fallback' => 'Set parameters and preview the impact.'])); ?></p>
                            <div class="settings-auto-assign-impact" data-assignment-planner-forecast-impact></div>
                        </div>
                    </div>
                </div>

                <div class="settings-analytics-grid">
                    <section class="settings-analytics-card">
                        <h4><?php echo e(t('settings.coverage_by_department')); ?></h4>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.current_month_range', ['month' => $currentMonthLabel, 'start' => $assignmentRangeStart, 'end' => $assignmentRangeEnd])); ?></p>
                        <?php if (empty($departmentCoverageRows)): ?>
                            <div class="crud-empty-state"><?php echo e(t('settings.no_department_data')); ?></div>
                        <?php else: ?>
                            <div class="settings-analytics-list">
                                <?php foreach (array_slice($departmentCoverageRows, 0, 10) as $deptMetric): ?>
                                    <article class="settings-analytics-item">
                                        <div class="settings-analytics-item-head">
                                            <strong><?php echo e((string) ($deptMetric['department_name'] ?? t('settings.department_default'))); ?></strong>
                                        </div>
                                        <div class="settings-analytics-metrics">
                                            <span><?php echo e(t('settings.assignments_metric')); ?>: <?php echo (int) ($deptMetric['active_assignments'] ?? 0); ?></span>
                                            <span><?php echo e(t('settings.uncovered_days')); ?>: <?php echo (int) ($deptMetric['uncovered_days'] ?? 0); ?></span>
                                            <span><?php echo e(t('settings.hours_label')); ?>: <?php echo e(number_format((float) ($deptMetric['hours'] ?? 0), 1)); ?>h</span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="settings-analytics-card">
                        <h4><?php echo e(t('settings.workload_by_user')); ?></h4>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.workload_month_hint', ['month' => $currentMonthLabel])); ?></p>
                        <?php if (empty($visibleUsers)): ?>
                            <div class="crud-empty-state"><?php echo e(t('settings.no_user_workload_data')); ?></div>
                        <?php else: ?>
                            <div class="settings-analytics-list">
                                <?php foreach (array_slice($visibleUsers, 0, 12) as $userItem): ?>
                                    <?php
                                        $plannerUserId = (int) ($userItem['id'] ?? 0);
                                        $plannerUserName = trim((string) (($userItem['first_name'] ?? '') . ' ' . ($userItem['last_name'] ?? '')));
                                        if ($plannerUserName === '') {
                                            $plannerUserName = (string) ($userItem['email'] ?? ('User #' . $plannerUserId));
                                        }
                                        $plannerStats = $employeeAssignmentStats[$plannerUserId] ?? ['assigned' => 0, 'sick' => 0, 'vacation' => 0, 'rest' => 0];
                                        $plannerWorkload = $userWorkloadById[$plannerUserId] ?? ['days_count' => 0, 'hours' => 0.0];
                                    ?>
                                    <article class="settings-analytics-item">
                                        <div class="settings-analytics-item-head">
                                            <strong><?php echo e($plannerUserName); ?></strong>
                                        </div>
                                        <div class="settings-analytics-metrics">
                                            <span><?php echo e(t('settings.days_label')); ?>: <?php echo (int) ($plannerWorkload['days_count'] ?? 0); ?></span>
                                            <span><?php echo e(t('settings.hours_label')); ?>: <?php echo e(number_format((float) ($plannerWorkload['hours'] ?? 0), 1)); ?>h</span>
                                            <span><?php echo e(t('settings.rest')); ?>: <?php echo (int) ($plannerStats['rest'] ?? 0); ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </section>

            <?php if (!$settingsSimpleMode): ?>
            <section class="crud-panel settings-panel" data-settings-panel="assignments" hidden>
                <?php
                    // Shared stats are prepared earlier to be reused by assignment planner and advanced assignment panel.
                ?>
                <div class="settings-panel-head">
                    <div>
                        <h3><?php echo e(t('settings.assignments_head')); ?></h3>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.assignments_subtitle')); ?></p>
                    </div>
                </div>

                <div class="settings-create-row settings-auto-assign-row">
                    <div class="settings-list-cols settings-list-cols-shift-create">
                        <?php
                            $autoAssignableShiftOptions = [];
                            foreach ($shifts as $shift) {
                                $shiftOptionKindRaw = strtolower(trim((string) ($shift['kind'] ?? 'work')));
                                $shiftOptionNameRaw = strtolower(trim((string) ($shift['name'] ?? '')));
                                $isWorkAssignable = $shiftOptionKindRaw === 'work'
                                    && !in_array($shiftOptionNameRaw, ['rest day', 'vacation', 'sick leave', 'repos', 'vacances', 'maladie', 'riposo', 'ferie', 'malattia'], true);
                                if (!$isWorkAssignable) {
                                    continue;
                                }
                                $shiftOptionKind = (string) ($shift['kind'] ?? 'work');
                                $shiftOptionName = (string) ($shift['name'] ?? t('settings.shift_default'));
                                $shiftOptionDisplayName = $localizedSystemShiftName($shiftOptionKind, $shiftOptionName);
                                if ($shiftOptionDisplayName === '') {
                                    $shiftOptionDisplayName = $shiftOptionName;
                                }
                                $autoAssignableShiftOptions[] = [
                                    'id' => (int) ($shift['id'] ?? 0),
                                    'name' => $shiftOptionDisplayName,
                                ];
                            }
                        ?>
                        <div class="settings-field settings-field-span-all" data-auto-assign-shift-selected-wrap>
                            <span><?php echo e(t('settings.auto_assign_selected_shifts', ['fallback' => 'Allowed shifts for auto assignment'])); ?></span>
                            <div class="settings-assignment-open-shift-list settings-auto-assign-shift-list" data-auto-assign-shift-list>
                                <?php foreach ($autoAssignableShiftOptions as $shiftOption): ?>
                                    <label class="settings-assignment-open-shift-chip dashboard-print-department-chip">
                                        <input type="checkbox" data-auto-assign-shift-id="<?php echo (int) ($shiftOption['id'] ?? 0); ?>" checked>
                                        <?php echo e($shiftOption['name'] ?? t('settings.shift_default')); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php
                            $autoAssignDepartmentOptions = [];
                            foreach ($visibleDepartments as $departmentOption) {
                                $departmentOptionId = (int) ($departmentOption['id'] ?? 0);
                                if ($departmentOptionId <= 0) {
                                    continue;
                                }
                                $autoAssignDepartmentOptions[] = [
                                    'id' => $departmentOptionId,
                                    'name' => (string) ($departmentOption['name'] ?? t('settings.department_default')),
                                ];
                            }
                        ?>
                        <div class="settings-field settings-field-span-all" data-auto-assign-priority-wrap>
                            <span><?php echo e(t('settings.auto_assign_department_priority')); ?></span>
                            <div class="settings-auto-assign-priority-list" data-auto-assign-priority-list>
                                <button
                                    type="button"
                                    class="settings-auto-assign-priority-chip"
                                    data-auto-assign-priority-department-id="0"
                                    aria-pressed="true"
                                >
                                    <?php echo e(t('settings.auto_assign_no_priority')); ?>
                                </button>
                                <?php foreach ($autoAssignDepartmentOptions as $departmentOption): ?>
                                    <button
                                        type="button"
                                        class="settings-auto-assign-priority-chip"
                                        data-auto-assign-priority-department-id="<?php echo (int) ($departmentOption['id'] ?? 0); ?>"
                                        aria-pressed="false"
                                    >
                                        <?php echo e($departmentOption['name'] ?? t('settings.department_default')); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <label class="settings-assignment-open-shift-chip settings-auto-assign-priority-guard-chip">
                                <input type="checkbox" data-auto-assign-priority-strict checked>
                                <?php echo e(t('settings.auto_assign_priority_strict')); ?>
                            </label>
                        </div>
                        <label class="settings-field"><?php echo e(t('settings.from_date')); ?><input data-auto-assign-range-start type="date" value="<?php echo e($autoAssignDefaultStart); ?>" min="<?php echo e($currentMonthStart); ?>"></label>
                        <label class="settings-field"><?php echo e(t('settings.to_date')); ?><input data-auto-assign-range-end type="date" value="<?php echo e($autoAssignDefaultEnd); ?>" min="<?php echo e($currentMonthStart); ?>"></label>
                        <label class="settings-field"><?php echo e(t('settings.minimum_employees')); ?><input data-auto-assign-min-employees type="number" min="0" step="1" value="1"></label>
                        <label class="settings-field"><?php echo e(t('settings.maximum_employees')); ?><input data-auto-assign-max-employees type="number" min="1" step="1" value="3"></label>
                        <label class="settings-field">
                            <?php echo e(t('settings.auto_assign_rest_strategy', ['fallback' => 'Rest day strategy'])); ?>
                            <select data-auto-assign-rest-strategy>
                                <option value="fixed"><?php echo e(t('settings.rest_strategy_fixed', ['fallback' => 'Fixed weekly rest'])); ?></option>
                                <option value="staggered"><?php echo e(t('settings.rest_strategy_staggered', ['fallback' => 'Staggered rotation'])); ?></option>
                                <option value="random"><?php echo e(t('settings.rest_strategy_random', ['fallback' => 'Random rotation'])); ?></option>
                            </select>
                        </label>
                        <label class="settings-field"><?php echo e(t('settings.min_rest_days_week')); ?><input data-auto-assign-min-rest-days type="number" min="0" max="6" step="1" value="1"></label>
                        <label class="settings-field"><?php echo e(t('settings.max_rest_days_week')); ?><input data-auto-assign-max-rest-days type="number" min="0" max="6" step="1" value="2"></label>
                        <label class="settings-field"><?php echo e(t('settings.min_work_days_week')); ?><input data-auto-assign-min-work-days type="number" min="1" max="7" step="1" value="4"></label>
                        <label class="settings-field"><?php echo e(t('settings.max_work_days_week')); ?><input data-auto-assign-max-work-days type="number" min="1" max="7" step="1" value="6"></label>
                        <div class="settings-field settings-field-span-all settings-auto-assign-presets" data-auto-assign-presets>
                            <span><?php echo e(t('settings.policy_presets')); ?></span>
                            <div class="settings-auto-assign-presets-list">
                                <button type="button" class="admin-action-link admin-action-link-secondary settings-auto-assign-preset" data-auto-assign-preset="balanced"><?php echo e(t('settings.preset_balanced')); ?></button>
                                <button type="button" class="admin-action-link admin-action-link-secondary settings-auto-assign-preset" data-auto-assign-preset="coverage"><?php echo e(t('settings.preset_max_coverage')); ?></button>
                                <button type="button" class="admin-action-link admin-action-link-secondary settings-auto-assign-preset" data-auto-assign-preset="wellbeing"><?php echo e(t('settings.preset_staff_wellbeing')); ?></button>
                            </div>
                        </div>
                        <div class="settings-auto-assign-forecast" data-auto-assign-forecast>
                            <strong><?php echo e(t('settings.auto_assign_forecast_title', ['fallback' => 'Auto-assign forecast'])); ?></strong>
                            <p class="crud-modal-subtitle" data-auto-assign-forecast-summary><?php echo e(t('settings.auto_assign_forecast_loading', ['fallback' => 'Calculating forecast...'])); ?></p>
                            <div class="settings-auto-assign-impact" data-auto-assign-impact></div>
                            <ul class="settings-auto-assign-forecast-tips" data-auto-assign-forecast-tips></ul>
                        </div>
                        <div class="settings-auto-assign-action-hints">
                            <div class="settings-auto-assign-action-hint-row">
                                <button
                                    type="button"
                                    class="admin-action-link settings-action-icon"
                                    data-auto-assign-open
                                    title="<?php echo e(t('settings.auto_assign_open')); ?>"
                                    aria-label="<?php echo e(t('settings.auto_assign_open')); ?>"
                                >
                                    <img src="<?php echo $basePath; ?>/assets/icons/calendar-sync.svg" alt="" aria-hidden="true" class="settings-icon-image">
                                </button>
                                <small class="settings-shift-create-hint"><?php echo e(t('settings.auto_assign_open_help')); ?></small>
                            </div>
                            <div class="settings-auto-assign-action-hint-row">
                                <button type="button" class="admin-action-link admin-action-link-secondary settings-action-icon" data-auto-assign-clear title="<?php echo e(t('settings.clear_assigned_shifts')); ?>" aria-label="<?php echo e(t('settings.clear_assigned_shifts')); ?>">
                                    <img src="<?php echo $basePath; ?>/assets/icons/calendar-x.svg" alt="" aria-hidden="true" class="settings-icon-image">
                                </button>
                                <small class="settings-shift-create-hint"><?php echo e(t('settings.clear_assigned_shifts_help')); ?></small>
                            </div>
                        </div>
                        <div class="settings-auto-assign-preview-modal" data-auto-assign-preview-modal hidden>
                            <div class="settings-auto-assign-preview-window">
                                <div class="settings-auto-assign-preview-head">
                                    <strong><?php echo e(t('settings.auto_assign_preview_title')); ?></strong>
                                    <p class="crud-modal-subtitle"><?php echo e(t('settings.auto_assign_preview_subtitle')); ?></p>
                                </div>
                                <div class="settings-auto-assign-preview-grid" data-auto-assign-preview-grid></div>
                                <div class="settings-auto-assign-preview-warning" style="margin-top: 0.6rem; padding: 0.7rem; background: rgba(255,193,7,0.1); border-left: 3px solid #ffc107; border-radius: 4px; font-size: 11px; color: #666;">
                                    <strong style="display: block; color: #ffc107; margin-bottom: 0.3rem;">⚠ <?php echo e(t('settings.auto_assign_preview_warning_title')); ?></strong>
                                    <span><?php echo e(t('settings.auto_assign_preview_warning_body')); ?></span>
                                </div>
                                <div class="settings-auto-assign-preview-actions">
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-auto-assign-preview-cancel><?php echo e(t('employee.cancel')); ?></button>
                                    <button type="button" class="admin-action-link" data-auto-assign-preview-confirm><?php echo e(t('settings.auto_assign_preview_confirm')); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <section class="settings-analytics-card settings-assignment-employee-index" data-assignment-employee-index>
                    <div class="settings-assignment-employee-index-head">
                        <h4><?php echo e(t('settings.employees')); ?></h4>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.select_employee_hint')); ?></p>
                    </div>
                    <?php if (empty($visibleUsers)): ?>
                        <div class="crud-empty-state"><?php echo e(t('settings.no_active_employees')); ?></div>
                    <?php else: ?>
                        <?php
                            $departmentNameById = [];
                            $departmentIconById = [];
                            foreach ($visibleDepartments as $deptOption) {
                                $departmentNameById[(int) ($deptOption['id'] ?? 0)] = (string) ($deptOption['name'] ?? t('settings.department_default'));
                                $departmentIconById[(int) ($deptOption['id'] ?? 0)] = (string) ($deptOption['icon'] ?? $defaultPickerIcon);
                            }

                            $usersByDepartment = [];
                            foreach ($visibleUsers as $employeeItem) {
                                $employeeDepartmentId = (int) ($employeeItem['department_id'] ?? 0);
                                $employeeDepartmentName = (string) ($employeeItem['department_name'] ?? ($departmentNameById[$employeeDepartmentId] ?? t('settings.unassigned')));
                                if ($employeeDepartmentName === '') {
                                    $employeeDepartmentName = t('settings.unassigned');
                                }
                                $groupKey = $employeeDepartmentId > 0 ? 'dept-' . $employeeDepartmentId : 'unassigned';
                                if (!isset($usersByDepartment[$groupKey])) {
                                    $usersByDepartment[$groupKey] = [
                                        'label' => $employeeDepartmentName,
                                        'department_id' => $employeeDepartmentId,
                                        'icon' => $departmentIconById[$employeeDepartmentId] ?? '',
                                        'users' => [],
                                    ];
                                }
                                $usersByDepartment[$groupKey]['users'][] = $employeeItem;
                            }

                            ksort($usersByDepartment);
                        ?>
                        <?php foreach ($usersByDepartment as $departmentGroup): ?>
                            <?php $deptGroupId = (int) ($departmentGroup['department_id'] ?? 0); ?>
                            <section class="settings-assignment-employee-group">
                                <div class="settings-assignment-employee-group-head">
                                    <div class="settings-assignment-employee-group-title"><?php echo $renderGroupIcon($deptGroupId, (string) ($departmentGroup['icon'] ?? ''), (string) ($departmentGroup['label'] ?? t('settings.unassigned'))); ?> <?php echo e($departmentGroup['label'] ?? t('settings.unassigned')); ?></div>
                                    <?php if ($deptGroupId > 0): ?>
                                        <?php
                                            $deptShiftIds = [];
                                            foreach ($shifts as $shiftItem) {
                                                if ((int) ($shiftItem['department_id'] ?? 0) === $deptGroupId && strtolower(trim((string) ($shiftItem['kind'] ?? 'work'))) === 'work') {
                                                    $deptShiftIds[] = (int) ($shiftItem['id'] ?? 0);
                                                }
                                            }
                                        ?>
                                        <div class="settings-assignment-employee-group-actions">
                                            <button
                                                type="button"
                                                class="admin-action-link admin-action-link-secondary"
                                                data-dept-assign-open="1"
                                                data-dept-id="<?php echo $deptGroupId; ?>"
                                                data-dept-name="<?php echo e((string) ($departmentGroup['label'] ?? '')); ?>"
                                                data-dept-shift-ids="<?php echo e(implode(',', $deptShiftIds)); ?>"
                                                title="<?php echo e($isFrLocale ? 'Assigner automatiquement ce departement' : 'Auto-assign this department'); ?>"
                                            >
                                                <img src="<?php echo $basePath; ?>/assets/icons/calendar-sync.svg" alt="" aria-hidden="true" class="settings-icon-image" style="width:13px;height:13px;vertical-align:middle;">
                                                <?php echo e($isFrLocale ? 'Assigner' : 'Assign dept'); ?>
                                            </button>
                                            <button
                                                type="button"
                                                class="admin-action-link admin-action-link-secondary"
                                                data-dept-assign-clear="1"
                                                data-dept-id="<?php echo $deptGroupId; ?>"
                                                data-dept-name="<?php echo e((string) ($departmentGroup['label'] ?? '')); ?>"
                                                data-dept-shift-ids="<?php echo e(implode(',', $deptShiftIds)); ?>"
                                                title="<?php echo e($isFrLocale ? 'Effacer les affectations de ce departement' : 'Clear this department assignments'); ?>"
                                            >
                                                <img src="<?php echo $basePath; ?>/assets/icons/calendar-x.svg" alt="" aria-hidden="true" class="settings-icon-image" style="width:13px;height:13px;vertical-align:middle;">
                                                <?php echo e($isFrLocale ? 'Effacer' : 'Clear dept'); ?>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="settings-assignment-employee-list">
                                    <?php foreach ($departmentGroup['users'] as $employeeItem): ?>
                                        <?php
                                            $employeeId = (int) ($employeeItem['id'] ?? 0);
                                            $employeeName = trim((string) (($employeeItem['first_name'] ?? '') . ' ' . ($employeeItem['last_name'] ?? '')));
                                            if ($employeeName === '') {
                                                $employeeName = (string) ($employeeItem['email'] ?? ('Employee #' . $employeeId));
                                            }
                                            $employeeStat = $employeeAssignmentStats[$employeeId] ?? ['assigned' => 0, 'sick' => 0, 'vacation' => 0, 'rest' => 0];
                                        ?>
                                        <button
                                            type="button"
                                            class="settings-assignment-employee-item"
                                            data-assignment-employee-open
                                            data-user-id="<?php echo $employeeId; ?>"
                                            data-user-name="<?php echo e($employeeName); ?>"
                                            data-user-department-id="<?php echo (int) ($employeeItem['department_id'] ?? 0); ?>"
                                            data-user-department-name="<?php echo e($departmentGroup['label'] ?? t('settings.unassigned')); ?>"
                                        >
                                            <strong><?php echo e($employeeName); ?></strong>
                                            <span><?php echo e($employeeItem['email'] ?? ''); ?></span>
                                            <small>
                                                <?php echo e(t('settings.assigned')); ?>: <?php echo (int) ($employeeStat['assigned'] ?? 0); ?>
                                                • <?php echo e(t('settings.sick')); ?>: <?php echo (int) ($employeeStat['sick'] ?? 0); ?>
                                                • <?php echo e(t('settings.vacation')); ?>: <?php echo (int) ($employeeStat['vacation'] ?? 0); ?>
                                                • <?php echo e(t('settings.rest')); ?>: <?php echo (int) ($employeeStat['rest'] ?? 0); ?>
                                            </small>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <script type="application/json" data-assignment-shift-catalog>
                    <?php
                        $shiftCatalog = array_map(static function (array $shift): array {
                            return [
                                'id' => (int) ($shift['id'] ?? 0),
                                'name' => (string) ($shift['name'] ?? t('settings.shift_default')),
                                'icon' => (string) ($shift['icon'] ?? '🕒'),
                                'kind' => (string) ($shift['kind'] ?? 'work'),
                                'start_time' => (string) ($shift['start_time'] ?? ''),
                                'end_time' => (string) ($shift['end_time'] ?? ''),
                                'department_id' => (int) ($shift['department_id'] ?? 0),
                                'department_name' => (string) ($shift['department_name'] ?? t('settings.department_default')),
                            ];
                        }, $shifts);
                        echo json_encode($shiftCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    ?>
                </script>

                <section class="settings-assignment-employee-modal" data-assignment-employee-modal hidden>
                    <div class="settings-assignment-employee-window">
                        <header class="settings-assignment-employee-window-head">
                            <div>
                                <h4 data-assignment-modal-title><?php echo e(t('settings.employee_details')); ?></h4>
                                <p class="crud-modal-subtitle" data-assignment-modal-subtitle><?php echo e(t('settings.assigned_shifts_hint')); ?></p>
                            </div>
                            <button type="button" class="dashboard-modal-close" data-assignment-employee-close aria-label="<?php echo e(t('settings.close')); ?>">&times;</button>
                        </header>
                        <div class="settings-assignment-employee-window-grid">
                            <section class="settings-analytics-card">
                                <h5><?php echo e(t('settings.availability_rules')); ?></h5>
                                <p class="crud-modal-subtitle"><?php echo e(t('settings.weekly_rest_and_dates')); ?> — <?php echo e(t('settings.default_days_all_months')); ?></p>
                                <div class="settings-auto-rule-weekdays" data-assignment-modal-weekdays></div>
                                <div class="settings-inline-actions">
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-rotate-toggle><?php echo e(t('settings.rotation_toggle')); ?></button>
                                </div>
                                <div class="settings-assignment-rotation-preview" data-assignment-modal-rotation-preview hidden></div>
                                <p class="crud-modal-subtitle settings-month-override-label"><?php echo e(t('settings.month_override_label')); ?></p>
                                <div class="settings-assignment-modal-range-row settings-assignment-modal-month-row">
                                    <label class="settings-field"><?php echo e(t('settings.rules_month')); ?><input type="month" data-assignment-modal-rules-month></label>
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-rules-reset><?php echo e(t('settings.reset_rules')); ?></button>
                                </div>
                                <div class="settings-auto-rule-weekdays" data-assignment-modal-month-weekdays></div>
                                <div class="settings-inline-actions">
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-save-month-override><?php echo e(t('settings.save_month_override')); ?></button>
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-clear-month-override disabled><?php echo e(t('settings.clear_month_override')); ?></button>
                                </div>
                                <h5><?php echo e(t('settings.selected_month_availability')); ?></h5>
                                <div class="settings-assignment-weekly-grid" data-assignment-modal-weekly></div>
                                <h5><?php echo e(t('settings.unavailable_dates_selected_month')); ?></h5>
                                <div class="settings-auto-rule-special-list" data-assignment-modal-month-unavailable></div>
                            </section>
                            <section class="settings-analytics-card">
                                <h5><?php echo e(t('settings.assigned_shifts')); ?></h5>
                                <p class="crud-modal-subtitle"><?php echo e(t('settings.assigned_shifts_hint')); ?></p>
                                <div class="settings-inline-actions">
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-open-shifts><?php echo e(t('settings.open_assigned_shifts', ['fallback' => 'Open assigned shifts'])); ?></button>
                                </div>
                                <p class="crud-modal-subtitle settings-assignment-shifts-summary" data-assignment-modal-shifts-summary></p>
                            </section>
                            <section class="settings-analytics-card settings-assignment-modal-open-slots">
                                <h5><?php echo e(t('settings.open_shifts_to_cover')); ?></h5>
                                <p class="crud-modal-subtitle"><?php echo e(t('settings.open_shifts_hint')); ?></p>
                                <div class="settings-assignment-modal-open-range">
                                    <label class="settings-field"><?php echo e(t('settings.assignment_period')); ?>
                                        <select data-assignment-modal-period-mode>
                                            <option value="current"><?php echo e(t('settings.assignment_period_current')); ?></option>
                                            <option value="future"><?php echo e(t('settings.assignment_period_future')); ?></option>
                                            <option value="month"><?php echo e(t('settings.assignment_period_month')); ?></option>
                                        </select>
                                    </label>
                                    <label class="settings-field"><?php echo e(t('settings.target_month')); ?><input type="month" data-assignment-modal-period-month></label>
                                    <label class="settings-field"><?php echo e(t('settings.from_date')); ?><input type="date" data-assignment-modal-open-from></label>
                                    <label class="settings-field"><?php echo e(t('settings.to_date')); ?><input type="date" data-assignment-modal-open-to></label>
                                </div>
                                <div class="settings-assignment-open-shift-list" data-assignment-modal-open-shift-list></div>
                                <div class="settings-inline-actions">
                                    <button type="button" class="admin-action-link" data-assignment-modal-open-auto-assign><?php echo e(t('settings.auto_assign_for_employee')); ?></button>
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-open-clear-assigned><?php echo e(t('settings.clear_employee_assignments')); ?></button>
                                </div>
                            </section>
                            <section class="settings-analytics-card settings-assignment-modal-open-slots">
                                <h5><?php echo e(t('settings.assign_absence_range')); ?></h5>
                                <p class="crud-modal-subtitle"><?php echo e(t('settings.assign_absence_hint')); ?></p>
                                <div class="settings-assignment-modal-open-range">
                                    <label class="settings-field"><?php echo e(t('settings.from_date')); ?><input type="date" data-assignment-modal-absence-from></label>
                                    <label class="settings-field"><?php echo e(t('settings.to_date')); ?><input type="date" data-assignment-modal-absence-to></label>
                                    <label class="settings-field"><?php echo e(t('crud.type')); ?>
                                        <select data-assignment-modal-absence-type>
                                            <option value="vacation"><?php echo e(t('settings.vacation')); ?></option>
                                            <option value="sick"><?php echo e(t('settings.sick')); ?></option>
                                        </select>
                                    </label>
                                </div>
                                <div class="settings-inline-actions">
                                    <button type="button" class="admin-action-link" data-assignment-modal-absence-assign><?php echo e(t('settings.assign_absence_action')); ?></button>
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-absence-reset><?php echo e(t('settings.reset_dates')); ?></button>
                                </div>
                            </section>
                        </div>
                        <section class="settings-assignment-shifts-modal" data-assignment-modal-shifts-modal hidden>
                            <div class="settings-assignment-shifts-window">
                                <header class="settings-assignment-shifts-window-head">
                                    <div>
                                        <h5><?php echo e(t('settings.assigned_shifts')); ?></h5>
                                        <p class="crud-modal-subtitle" data-assignment-modal-shifts-modal-title><?php echo e(t('settings.assigned_shifts_hint')); ?></p>
                                    </div>
                                    <button type="button" class="dashboard-modal-close" data-assignment-modal-shifts-close aria-label="<?php echo e(t('settings.close')); ?>">&times;</button>
                                </header>
                                <div class="settings-inline-actions settings-assignment-shifts-toolbar">
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-shifts-select-all><?php echo e(t('settings.select_all', ['fallback' => 'Select all'])); ?></button>
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-shifts-clear-selection><?php echo e(t('settings.deselect_all', ['fallback' => 'Deselect all'])); ?></button>
                                    <button type="button" class="admin-action-link" data-assignment-modal-shifts-unassign-selected><?php echo e(t('settings.unassign_selected', ['fallback' => 'Unassign selected'])); ?></button>
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-modal-shifts-unassign-all><?php echo e(t('settings.unassign_all', ['fallback' => 'Unassign all'])); ?></button>
                                </div>
                                <div class="settings-assignment-modal-shift-list" data-assignment-modal-shifts></div>
                            </div>
                        </section>
                    </div>
                </section>

                <div class="settings-analytics-grid">
                    <section class="settings-analytics-card">
                        <h4><?php echo e(t('settings.coverage_by_department')); ?></h4>
                        <p class="crud-modal-subtitle">
                            <?php echo e(t('settings.current_month_range', ['month' => $currentMonthLabel, 'start' => $assignmentRangeStart, 'end' => $assignmentRangeEnd])); ?>
                        </p>
                        <?php if (empty($departmentCoverageRows)): ?>
                            <div class="crud-empty-state"><?php echo e(t('settings.no_department_data')); ?></div>
                        <?php else: ?>
                            <div class="settings-analytics-list">
                                <?php foreach (array_slice($departmentCoverageRows, 0, 12) as $deptMetric): ?>
                                    <article class="settings-analytics-item">
                                        <div class="settings-analytics-item-head">
                                            <strong>
                                                <span class="settings-dept-title-icon" style="background: color-mix(in srgb, <?php echo e($deptMetric['department_color'] ?? '#b98b12'); ?> 18%, #ffffff 82%);">
                                                    <?php $analyticsIconValue = (string) ($deptMetric['department_icon'] ?? ''); ?>
                                                    <?php if ($isIconAsset($analyticsIconValue)): ?>
                                                        <img src="<?php echo e($iconUrl($analyticsIconValue)); ?>" alt="" aria-hidden="true" class="settings-icon-inline-image">
                                                    <?php else: ?>
                                                        <?php echo e($analyticsIconValue ?: '🏷️'); ?>
                                                    <?php endif; ?>
                                                </span>
                                                <?php echo e($deptMetric['department_name'] ?? t('settings.department_default')); ?>
                                            </strong>
                                        </div>
                                        <div class="settings-analytics-metrics">
                                            <span><?php echo e(t('settings.assignments_metric')); ?>: <?php echo (int) ($deptMetric['active_assignments'] ?? 0); ?></span>
                                            <span><?php echo e(t('settings.hours_label')); ?>: <?php echo e(number_format((float) ($deptMetric['hours'] ?? 0), 2)); ?>h</span>
                                            <span class="settings-metric-warning"><?php echo e(t('settings.uncovered_days')); ?>: <?php echo (int) ($deptMetric['uncovered_days'] ?? 0); ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="settings-analytics-card">
                        <h4><?php echo e(t('settings.workload_by_user')); ?></h4>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.workload_month_hint', ['month' => $currentMonthLabel])); ?></p>
                        <?php if (empty($userWorkloadRows)): ?>
                            <div class="crud-empty-state"><?php echo e(t('settings.no_user_workload_data')); ?></div>
                        <?php else: ?>
                            <div class="settings-analytics-list">
                                <?php foreach (array_slice($userWorkloadRows, 0, 12) as $workload): ?>
                                    <article class="settings-analytics-item">
                                        <div class="settings-analytics-item-head">
                                            <strong><?php echo e($workload['user_name'] ?? t('settings.user_default')); ?></strong>
                                            <span><?php echo (int) ($workload['assignments'] ?? 0); ?> <?php echo e(t('settings.assignments_suffix')); ?></span>
                                        </div>
                                        <div class="settings-analytics-metrics">
                                            <span><?php echo e(t('settings.hours_label')); ?>: <?php echo e(number_format((float) ($workload['hours'] ?? 0), 2)); ?>h</span>
                                            <span><?php echo e(t('settings.days_label')); ?>: <?php echo (int) ($workload['days_count'] ?? 0); ?></span>
                                            <span><?php echo e(t('settings.shifts_label')); ?>: <?php echo e($workload['shift_preview'] ?: t('settings.not_available')); ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>

                <div class="settings-inline-actions settings-assignment-list-toggle-row">
                    <button type="button" class="admin-action-link admin-action-link-secondary" data-assignment-list-toggle aria-expanded="false">
                        <?php echo e(t('settings.show_daily_assignments')); ?>
                    </button>
                </div>

                <div class="settings-list-wrap" data-assignment-list-wrap hidden>
                    <p class="crud-modal-subtitle"><?php echo e(t('settings.daily_assignments_month', ['month' => $currentMonthLabel])); ?></p>
                    <div class="settings-list-row settings-list-header settings-list-cols settings-list-cols-assignment">
                        <strong><?php echo e(t('common.date')); ?></strong>
                        <span><?php echo e(t('common.department')); ?></span>
                        <span><?php echo e(t('common.shift')); ?></span>
                        <span><?php echo e(t('settings.user_label')); ?></span>
                        <span><?php echo e(t('settings.workload_label')); ?></span>
                        <span><?php echo e(t('common.status')); ?></span>
                        <span><?php echo e(t('common.action')); ?></span>
                    </div>

                    <?php if (empty($assignmentsCurrentMonth)): ?>
                        <div class="crud-empty-state"><?php echo e(t('settings.no_assignments')); ?></div>
                    <?php else: ?>
                        <?php foreach (array_slice($assignmentsCurrentMonth, 0, 250) as $assignment): ?>
                            <?php
                                $assignmentShiftKindValue = (string) ($assignment['shift_kind'] ?? 'work');
                                $assignmentShiftNameValue = (string) ($assignment['shift_name'] ?? '--');
                                $assignmentShiftDisplayName = $localizedSystemShiftName($assignmentShiftKindValue, $assignmentShiftNameValue);
                                $assignmentShiftDescriptionDisplay = $localizedSystemShiftDescription(
                                    $assignmentShiftKindValue,
                                    (string) ($assignment['shift_description'] ?? '')
                                );
                                if ($assignmentShiftDisplayName === '') {
                                    $assignmentShiftDisplayName = $assignmentShiftNameValue;
                                }
                            ?>
                            <article
                                class="settings-list-item-wrap"
                                data-assignment-id="<?php echo (int) ($assignment['assignment_id'] ?? 0); ?>"
                                data-assignment-user-id="<?php echo (int) ($assignment['user_id'] ?? 0); ?>"
                                data-assignment-user-name="<?php echo e($assignment['user_name'] ?: t('settings.open_slot')); ?>"
                                data-assignment-work-date="<?php echo e($assignment['work_date'] ?? ''); ?>"
                                data-assignment-shift-id="<?php echo (int) ($assignment['shift_id'] ?? 0); ?>"
                                data-assignment-shift-name="<?php echo e($assignmentShiftDisplayName); ?>"
                                data-assignment-shift-icon="<?php echo e($assignment['shift_icon'] ?? '🕒'); ?>"
                                data-assignment-shift-kind="<?php echo e($assignment['shift_kind'] ?? 'work'); ?>"
                                data-assignment-status="<?php echo e($assignment['status'] ?? ((int) ($assignment['user_id'] ?? 0) > 0 ? 'assigned' : 'open')); ?>"
                                data-assignment-start-time="<?php echo e($assignment['start_time'] ?? ''); ?>"
                                data-assignment-end-time="<?php echo e($assignment['end_time'] ?? ''); ?>"
                                data-assignment-department-id="<?php echo (int) ($assignment['department_id'] ?? 0); ?>"
                                data-assignment-department-name="<?php echo e($assignment['department_name'] ?? '--'); ?>"
                            >
                                <div class="settings-list-row settings-list-cols settings-list-cols-assignment">
                                    <strong><?php echo e($assignment['work_date'] ?? ''); ?></strong>
                                    <span><?php echo e($assignment['department_name'] ?? '--'); ?></span>
                                    <span>
                                        <?php $assignmentShiftIcon = (string) ($assignment['shift_icon'] ?? '🕒'); ?>
                                        <?php if ($isIconAsset($assignmentShiftIcon)): ?>
                                            <img src="<?php echo e($iconUrl($assignmentShiftIcon)); ?>" alt="" aria-hidden="true" class="settings-icon-inline-image">
                                        <?php else: ?>
                                            <?php echo e($assignmentShiftIcon); ?>
                                        <?php endif; ?>
                                        <?php echo e($assignmentShiftDisplayName); ?>
                                        <small class="settings-meta-inline"><?php echo e($localizedShiftKindLabel((string) ($assignment['shift_kind'] ?? 'work'))); ?></small>
                                        <?php if ($assignmentShiftDescriptionDisplay !== ''): ?>
                                            <small class="settings-meta-inline"><?php echo e($assignmentShiftDescriptionDisplay); ?></small>
                                        <?php endif; ?>
                                    </span>
                                    <span><?php echo e($assignment['user_name'] ?: t('settings.open_slot')); ?></span>
                                    <?php
                                        $assignmentDuration = $durationHoursForTimes(
                                            (string) ($assignment['start_time'] ?? ''),
                                            (string) ($assignment['end_time'] ?? '')
                                        );
                                    ?>
                                    <span>
                                        <?php echo e(($assignment['start_time'] ?? '--:--') . ' - ' . ($assignment['end_time'] ?? '--:--')); ?>
                                        <small class="settings-meta-inline"><?php echo e(number_format($assignmentDuration, 2)); ?>h</small>
                                    </span>
                                    <span><?php echo e($assignment['status'] ?? ((int) ($assignment['user_id'] ?? 0) > 0 ? 'assigned' : 'open')); ?></span>
                                    <div class="settings-inline-actions">
                                        <button type="button" class="admin-action-link admin-action-link-secondary settings-action-icon settings-assignment-edit" aria-label="<?php echo e(t('settings.edit_assignment')); ?>" title="<?php echo e(t('settings.edit_assignment')); ?>">✎</button>
                                    </div>
                                </div>
                                <div class="settings-edit-drawer" hidden>
                                    <div class="settings-list-cols settings-list-cols-assignment-edit">
                                        <label class="settings-field"><?php echo e(t('settings.work_date')); ?>
                                            <input data-field="work_date" type="date" value="<?php echo e($assignment['work_date'] ?? ''); ?>">
                                        </label>
                                        <label class="settings-field">Shift
                                            <?php
                                                $assignmentShiftIconValue = (string) ($assignment['shift_icon'] ?? $assignment['icon'] ?? '🕒');
                                                $assignmentShiftNameValue = (string) ($assignmentShiftDisplayName ?? ($assignment['shift_name'] ?? t('settings.shift_default')));
                                            ?>
                                            <div class="settings-shift-picker-preview" data-assignment-shift-preview>
                                                <?php if ($isIconAsset($assignmentShiftIconValue)): ?>
                                                    <img src="<?php echo e($iconUrl($assignmentShiftIconValue)); ?>" alt="" aria-hidden="true" class="settings-icon-inline-image">
                                                <?php else: ?>
                                                    <span class="settings-shift-picker-preview-text"><?php echo e($assignmentShiftIconValue); ?></span>
                                                <?php endif; ?>
                                                <span class="settings-shift-picker-preview-label"><?php echo e($assignmentShiftNameValue); ?></span>
                                            </div>
                                            <select data-field="shift_id">
                                                <?php foreach ($shifts as $shift): ?>
                                                    <?php
                                                        $editShiftKind = (string) ($shift['kind'] ?? 'work');
                                                        $editShiftName = (string) ($shift['name'] ?? t('settings.shift_default'));
                                                        $editShiftDisplayName = $localizedSystemShiftName($editShiftKind, $editShiftName);
                                                        if ($editShiftDisplayName === '') {
                                                            $editShiftDisplayName = $editShiftName;
                                                        }
                                                        $editShiftDescription = $localizedSystemShiftDescription($editShiftKind, (string) ($shift['description'] ?? ''));
                                                        $editShiftIconValue = (string) ($shift['icon'] ?? '🕒');
                                                    ?>
                                                    <option value="<?php echo (int) ($shift['id'] ?? 0); ?>" data-shift-option-icon="<?php echo e($editShiftIconValue); ?>" data-shift-option-name="<?php echo e($editShiftDisplayName); ?>" <?php echo ((int) ($assignment['shift_id'] ?? 0) === (int) ($shift['id'] ?? 0)) ? 'selected' : ''; ?>>
                                                        <?php echo e($editShiftDisplayName . ' • ' . ($shift['department_name'] ?? '')); ?><?php echo $editShiftDescription !== '' ? e(' • ' . $editShiftDescription) : ''; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="settings-field"><?php echo e(t('settings.employee_name')); ?>
                                            <select data-field="user_id">
                                                <option value=""><?php echo e(t('settings.open_slot')); ?></option>
                                                <?php foreach ($visibleUsers as $userOption): ?>
                                                    <option value="<?php echo (int) ($userOption['id'] ?? 0); ?>" <?php echo ((int) ($assignment['user_id'] ?? 0) === (int) ($userOption['id'] ?? 0)) ? 'selected' : ''; ?>>
                                                        <?php echo e(trim((string) (($userOption['first_name'] ?? '') . ' ' . ($userOption['last_name'] ?? ''))) ?: ($userOption['email'] ?? t('settings.user_default'))); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="settings-field"><?php echo e(t('common.status')); ?>
                                            <select data-field="status">
                                                <?php $assignmentStatus = (string) ($assignment['status'] ?? ((int) ($assignment['user_id'] ?? 0) > 0 ? 'assigned' : 'open')); ?>
                                                <option value="open" <?php echo $assignmentStatus === 'open' ? 'selected' : ''; ?>><?php echo e(t('settings.open_slot')); ?></option>
                                                <option value="assigned" <?php echo $assignmentStatus === 'assigned' ? 'selected' : ''; ?>><?php echo e(t('settings.assigned')); ?></option>
                                                <option value="in_progress" <?php echo $assignmentStatus === 'in_progress' ? 'selected' : ''; ?>><?php echo e(t('settings.in_progress')); ?></option>
                                                <option value="completed" <?php echo $assignmentStatus === 'completed' ? 'selected' : ''; ?>><?php echo e(t('settings.completed')); ?></option>
                                                <option value="cancelled" <?php echo $assignmentStatus === 'cancelled' ? 'selected' : ''; ?>><?php echo e(t('settings.cancelled')); ?></option>
                                            </select>
                                        </label>
                                        <div class="settings-inline-actions">
                                            <button type="button" class="admin-action-link settings-assignment-save"><?php echo e(t('settings.save')); ?></button>
                                            <button type="button" class="admin-action-link admin-action-link-secondary settings-assignment-unassign"><?php echo e(t('settings.unassign')); ?></button>
                                            <button type="button" class="admin-action-link admin-action-link-secondary settings-assignment-cancel"><?php echo e(t('employee.cancel')); ?></button>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </section>
            <?php endif; ?>

            <section class="crud-panel settings-panel" data-settings-panel="attendances" hidden>
                <div class="settings-panel-head">
                    <div>
                        <h3><?php echo e(t('settings.attendances')); ?></h3>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.attendance_panel_subtitle')); ?></p>
                    </div>
                    <div class="settings-pill-row">
                        <span class="settings-pill"><?php echo count($attendances); ?> <?php echo e(t('settings.records')); ?></span>
                    </div>
                </div>

                <?php if (in_array($currentRole, ['super_admin', 'admin'], true) && $scopeCompanyId > 0): ?>
                    <div class="settings-list-item-wrap settings-company-ip-wrap">
                        <div class="settings-list-row settings-company-ip-row">
                            <div>
                                <strong><?php echo e(t('settings.authorized_wifi_ip_title')); ?></strong>
                                <p class="crud-modal-subtitle"><?php echo e(t('settings.authorized_wifi_ip_subtitle')); ?></p>
                            </div>
                            <div class="settings-company-ip-controls">
                                <input
                                    type="text"
                                    value="<?php echo e($scopeCompanySignatureIp); ?>"
                                    placeholder="<?php echo e(t('settings.wifi_ip_placeholder')); ?>"
                                    data-company-signature-ip
                                    data-company-id="<?php echo (int) $scopeCompanyId; ?>"
                                >
                                <button type="button" class="admin-action-link" data-company-signature-ip-save><?php echo e(t('settings.save_wifi_ip')); ?></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <section class="settings-analytics-card settings-assignment-employee-index" data-attendance-employee-index>
                    <div class="settings-assignment-employee-index-head">
                        <h4><?php echo e(t('settings.employees_by_department')); ?></h4>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.employees_by_department_hint')); ?></p>
                    </div>
                    <?php if (empty($visibleUsers)): ?>
                        <div class="crud-empty-state"><?php echo e(t('settings.no_users_attendance')); ?></div>
                    <?php else: ?>
                        <?php
                            $attendanceDepartmentIconById = [];
                            foreach ($visibleDepartments as $deptOption) {
                                $attendanceDepartmentIconById[(int) ($deptOption['id'] ?? 0)] = (string) ($deptOption['icon'] ?? $defaultPickerIcon);
                            }

                            $attendanceUsersByDepartment = [];
                            foreach ($visibleUsers as $attendanceUserItem) {
                                $attendanceDepartmentId = (int) ($attendanceUserItem['department_id'] ?? 0);
                                $attendanceDepartmentName = (string) ($attendanceUserItem['department_name'] ?? t('settings.unassigned'));
                                if ($attendanceDepartmentName === '') {
                                    $attendanceDepartmentName = t('settings.unassigned');
                                }
                                $attendanceGroupKey = $attendanceDepartmentId > 0 ? 'dept-' . $attendanceDepartmentId : 'unassigned';
                                if (!isset($attendanceUsersByDepartment[$attendanceGroupKey])) {
                                    $attendanceUsersByDepartment[$attendanceGroupKey] = [
                                        'label' => $attendanceDepartmentName,
                                        'department_id' => $attendanceDepartmentId,
                                        'icon' => $attendanceDepartmentIconById[$attendanceDepartmentId] ?? '',
                                        'users' => [],
                                    ];
                                }
                                $attendanceUsersByDepartment[$attendanceGroupKey]['users'][] = $attendanceUserItem;
                            }
                            ksort($attendanceUsersByDepartment);
                        ?>
                        <?php foreach ($attendanceUsersByDepartment as $attendanceDepartmentGroup): ?>
                            <section class="settings-assignment-employee-group">
                                <div class="settings-assignment-employee-group-title"><?php echo $renderGroupIcon((int) ($attendanceDepartmentGroup['department_id'] ?? 0), (string) ($attendanceDepartmentGroup['icon'] ?? ''), (string) ($attendanceDepartmentGroup['label'] ?? t('settings.unassigned'))); ?> <?php echo e($attendanceDepartmentGroup['label'] ?? t('settings.unassigned')); ?></div>
                                <div class="settings-assignment-employee-list">
                                    <?php foreach ($attendanceDepartmentGroup['users'] as $attendanceUserItem): ?>
                                        <?php
                                            $attendanceUserId = (int) ($attendanceUserItem['id'] ?? 0);
                                            $attendanceUserName = trim((string) (($attendanceUserItem['first_name'] ?? '') . ' ' . ($attendanceUserItem['last_name'] ?? '')));
                                            if ($attendanceUserName === '') {
                                                $attendanceUserName = (string) ($attendanceUserItem['email'] ?? ('User #' . $attendanceUserId));
                                            }
                                            $attendanceCount = (int) ($attendanceByUser[$attendanceUserId] ?? 0);
                                        ?>
                                        <button
                                            type="button"
                                            class="settings-assignment-employee-item"
                                            data-attendance-employee-open
                                            data-user-id="<?php echo $attendanceUserId; ?>"
                                            data-user-name="<?php echo e($attendanceUserName); ?>"
                                            data-user-department-name="<?php echo e($attendanceDepartmentGroup['label'] ?? t('settings.unassigned')); ?>"
                                        >
                                            <strong><?php echo e($attendanceUserName); ?></strong>
                                            <span><?php echo e($attendanceUserItem['email'] ?? ''); ?></span>
                                            <small><?php echo e(t('settings.attendance_records')); ?>: <?php echo $attendanceCount; ?></small>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <script type="application/json" data-attendance-assignment-catalog>
                    <?php
                        $attendanceAssignmentCatalog = array_map(static function (array $assignment): array {
                            return [
                                'assignment_id' => (int) ($assignment['assignment_id'] ?? 0),
                                'user_id' => (int) ($assignment['user_id'] ?? 0),
                                'user_name' => (string) ($assignment['user_name'] ?? ''),
                                'work_date' => (string) ($assignment['work_date'] ?? ''),
                                'shift_id' => (int) ($assignment['shift_id'] ?? 0),
                                'shift_name' => (string) ($assignment['shift_name'] ?? t('settings.shift_default')),
                                'department_name' => (string) ($assignment['department_name'] ?? t('settings.department_default')),
                                'start_time' => (string) ($assignment['start_time'] ?? ''),
                                'end_time' => (string) ($assignment['end_time'] ?? ''),
                                'status' => (string) ($assignment['status'] ?? 'assigned'),
                            ];
                        }, $attendanceAssignableShifts);
                        echo json_encode($attendanceAssignmentCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    ?>
                </script>

                <script type="application/json" data-attendance-record-catalog>
                    <?php
                        $attendanceRecordCatalog = array_map(static function (array $attendance): array {
                            return [
                                'id' => (int) ($attendance['id'] ?? 0),
                                'user_id' => (int) ($attendance['user_id'] ?? 0),
                                'user_shift_id' => (int) ($attendance['user_shift_id'] ?? 0),
                                'user_name' => (string) ($attendance['user_name'] ?? ''),
                                'department_name' => (string) ($attendance['department_name'] ?? ''),
                                'shift_name' => (string) ($attendance['shift_name'] ?? ''),
                                'work_date' => (string) ($attendance['work_date'] ?? ''),
                                'status' => (string) ($attendance['status'] ?? 'present'),
                                'check_in_time' => (string) ($attendance['check_in_time'] ?? ''),
                                'check_out_time' => (string) ($attendance['check_out_time'] ?? ''),
                                'digital_signature_id' => (int) ($attendance['digital_signature_id'] ?? 0),
                            ];
                        }, $attendances);
                        echo json_encode($attendanceRecordCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    ?>
                </script>

                <div class="settings-assignment-employee-modal" data-attendance-employee-modal data-attendance-can-edit="<?php echo in_array($currentRole, ['super_admin', 'admin'], true) ? '1' : '0'; ?>" hidden>
                    <div class="settings-assignment-employee-window">
                        <header class="settings-assignment-employee-window-head">
                            <div>
                                <h4 data-attendance-modal-title><?php echo e(t('settings.attendance_signature')); ?></h4>
                                <p class="crud-modal-subtitle" data-attendance-modal-subtitle><?php echo e(t('settings.attendance_signature_hint')); ?></p>
                            </div>
                            <button type="button" class="dashboard-modal-close" data-attendance-employee-close aria-label="<?php echo e(t('settings.close_attendance_modal')); ?>">&times;</button>
                        </header>

                        <div class="settings-assignment-employee-window-grid">
                            <section class="settings-analytics-card">
                                <div class="settings-analytics-item settings-attendance-hours-summary" data-attendance-hours-summary>
                                        <div class="settings-analytics-item-head">
                                            <strong><?php echo e(t('settings.employee_hours_report_title', ['fallback' => 'Monthly hours report'])); ?></strong>
                                        </div>
                                        <div class="settings-attendance-hours-toolbar">
                                            <label class="settings-field settings-attendance-hours-month-field">
                                                <span><?php echo e(t('settings.target_month', ['fallback' => 'Target month'])); ?></span>
                                                <select data-attendance-hours-month-select>
                                                    <option value=""><?php echo e(t('settings.target_month', ['fallback' => 'Target month'])); ?></option>
                                                </select>
                                            </label>
                                            <small data-attendance-hours-month><?php echo e(t('settings.target_month', ['fallback' => 'Target month'])); ?>: --</small>
                                        </div>
                                        <div class="settings-attendance-hours-grid">
                                            <div class="settings-attendance-hours-card">
                                                <span class="settings-attendance-hours-label"><?php echo e(t('settings.hours_planned_label', ['fallback' => 'Planned (forecast)'])); ?></span>
                                                <strong data-attendance-hours-assigned>0h</strong>
                                            </div>
                                            <div class="settings-attendance-hours-card">
                                                <span class="settings-attendance-hours-label"><?php echo e(t('settings.hours_worked_label', ['fallback' => 'Worked (historical)'])); ?></span>
                                                <strong data-attendance-hours-worked>0h</strong>
                                            </div>
                                            <div class="settings-attendance-hours-card">
                                                <span class="settings-attendance-hours-label"><?php echo e(t('settings.assignments_metric', ['fallback' => 'Planned assignments'])); ?></span>
                                                <strong data-attendance-hours-assignment-count>0</strong>
                                            </div>
                                            <div class="settings-attendance-hours-card">
                                                <span class="settings-attendance-hours-label"><?php echo e(t('settings.signature_label', ['fallback' => 'Signed attendances'])); ?></span>
                                                <strong data-attendance-hours-signed-count>0</strong>
                                            </div>
                                            <div class="settings-attendance-hours-card">
                                                <span class="settings-attendance-hours-label"><?php echo e(t('settings.late', ['fallback' => 'Late arrivals'])); ?></span>
                                                <strong data-attendance-hours-late-count>0</strong>
                                            </div>
                                        </div>
                                </div>
                                <label class="settings-field"><?php echo e(t('settings.assigned_shift')); ?>
                                    <select data-attendance-modal-user-shift>
                                        <option value=""><?php echo e(t('settings.select_assigned_shift')); ?></option>
                                    </select>
                                </label>
                                <small class="crud-modal-subtitle" data-attendance-existing-note hidden></small>
                                <label class="settings-field"><?php echo e(t('settings.attendance_status')); ?>
                                    <select data-attendance-modal-status>
                                        <option value="present"><?php echo e(t('settings.present')); ?></option>
                                        <option value="late"><?php echo e(t('settings.late')); ?></option>
                                        <option value="absent"><?php echo e(t('settings.absent')); ?></option>
                                        <option value="early_departure"><?php echo e(t('settings.early_departure')); ?></option>
                                    </select>
                                </label>
                                <div class="settings-assignment-modal-range-row">
                                    <label class="settings-field"><?php echo e(t('settings.checkin_time')); ?>
                                        <input type="time" data-attendance-modal-checkin>
                                    </label>
                                    <label class="settings-field"><?php echo e(t('settings.checkout_time')); ?>
                                        <input type="time" data-attendance-modal-checkout>
                                    </label>
                                </div>
                                <div class="employee-signature-pad-shell">
                                    <span><?php echo e(t('employee.digital_signature')); ?></span>
                                    <canvas width="520" height="180" data-attendance-signature-canvas aria-label="<?php echo e(t('settings.signature_pad_aria')); ?>"></canvas>
                                    <small class="employee-signature-error" data-attendance-signature-error></small>
                                    <div class="employee-signature-pad-actions">
                                        <button type="button" class="admin-action-link admin-action-link-secondary" data-attendance-signature-clear><?php echo e(t('employee.clear_signature')); ?></button>
                                        <small><?php echo e(t('settings.signature_input_hint')); ?></small>
                                    </div>
                                </div>
                                <div class="settings-inline-actions">
                                    <button type="button" class="admin-action-link" data-attendance-signature-save><?php echo e(t('settings.record_attendance')); ?></button>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

                <div class="settings-assignment-employee-modal" data-attendance-record-modal hidden>
                    <div class="settings-assignment-employee-window">
                        <header class="settings-assignment-employee-window-head">
                            <div>
                                <h4 data-attendance-record-title><?php echo e(t('settings.edit_attendance')); ?></h4>
                                <p class="crud-modal-subtitle" data-attendance-record-subtitle><?php echo e(t('settings.edit_attendance_hint')); ?></p>
                            </div>
                            <button type="button" class="dashboard-modal-close" data-attendance-record-close aria-label="<?php echo e(t('settings.close_attendance_edit_modal')); ?>">&times;</button>
                        </header>

                        <div class="settings-assignment-employee-window-grid">
                            <section class="settings-analytics-card">
                                <div class="settings-analytics-item settings-attendance-hours-summary" data-attendance-record-hours-summary>
                                    <div class="settings-analytics-item-head">
                                        <strong><?php echo e(t('settings.employee_hours_report_title', ['fallback' => 'Monthly hours report'])); ?></strong>
                                    </div>
                                    <div class="settings-attendance-hours-toolbar">
                                        <label class="settings-field settings-attendance-hours-month-field">
                                            <span><?php echo e(t('settings.target_month', ['fallback' => 'Target month'])); ?></span>
                                            <select data-attendance-record-hours-month-select>
                                                <option value=""><?php echo e(t('settings.target_month', ['fallback' => 'Target month'])); ?></option>
                                            </select>
                                        </label>
                                        <small data-attendance-record-hours-month><?php echo e(t('settings.target_month', ['fallback' => 'Target month'])); ?>: --</small>
                                    </div>
                                    <div class="settings-attendance-hours-grid">
                                        <div class="settings-attendance-hours-card">
                                            <span class="settings-attendance-hours-label"><?php echo e(t('settings.hours_planned_label', ['fallback' => 'Planned (forecast)'])); ?></span>
                                            <strong data-attendance-record-hours-assigned>0h</strong>
                                        </div>
                                        <div class="settings-attendance-hours-card">
                                            <span class="settings-attendance-hours-label"><?php echo e(t('settings.hours_worked_label', ['fallback' => 'Worked (historical)'])); ?></span>
                                            <strong data-attendance-record-hours-worked>0h</strong>
                                        </div>
                                        <div class="settings-attendance-hours-card">
                                            <span class="settings-attendance-hours-label"><?php echo e(t('settings.assignments_metric', ['fallback' => 'Planned assignments'])); ?></span>
                                            <strong data-attendance-record-hours-assignment-count>0</strong>
                                        </div>
                                        <div class="settings-attendance-hours-card">
                                            <span class="settings-attendance-hours-label"><?php echo e(t('settings.signature_label', ['fallback' => 'Signed attendances'])); ?></span>
                                            <strong data-attendance-record-hours-signed-count>0</strong>
                                        </div>
                                        <div class="settings-attendance-hours-card">
                                            <span class="settings-attendance-hours-label"><?php echo e(t('settings.late', ['fallback' => 'Late arrivals'])); ?></span>
                                            <strong data-attendance-record-hours-late-count>0</strong>
                                        </div>
                                    </div>
                                </div>
                                <label class="settings-field"><?php echo e(t('settings.attendance_status')); ?>
                                    <select data-attendance-record-status>
                                        <option value="present"><?php echo e(t('settings.present')); ?></option>
                                        <option value="late"><?php echo e(t('settings.late')); ?></option>
                                        <option value="absent"><?php echo e(t('settings.absent')); ?></option>
                                        <option value="early_departure"><?php echo e(t('settings.early_departure')); ?></option>
                                    </select>
                                </label>
                                <div class="settings-assignment-modal-range-row">
                                    <label class="settings-field"><?php echo e(t('settings.checkin_time')); ?>
                                        <input type="time" data-attendance-record-checkin>
                                    </label>
                                    <label class="settings-field"><?php echo e(t('settings.checkout_time')); ?>
                                        <input type="time" data-attendance-record-checkout>
                                    </label>
                                </div>
                                <div class="employee-signature-pad-shell">
                                    <span><?php echo e(t('employee.digital_signature')); ?> (<?php echo e(t('settings.optional', ['fallback' => 'optional'])); ?>)</span>
                                    <small class="crud-modal-subtitle" data-attendance-record-signature-state><?php echo e(t('settings.signature_label')); ?>: --</small>
                                    <canvas width="520" height="180" data-attendance-record-signature-canvas aria-label="<?php echo e(t('settings.signature_pad_aria')); ?>"></canvas>
                                    <small class="employee-signature-error" data-attendance-record-signature-error></small>
                                    <div class="employee-signature-pad-actions">
                                        <button type="button" class="admin-action-link admin-action-link-secondary" data-attendance-record-signature-clear><?php echo e(t('employee.clear_signature')); ?></button>
                                        <small><?php echo e(t('settings.signature_input_hint')); ?></small>
                                    </div>
                                </div>
                                <div class="settings-inline-actions">
                                    <button type="button" class="admin-action-link" data-attendance-record-save><?php echo e(t('settings.save_changes')); ?></button>
                                    <button type="button" class="admin-action-link admin-action-link-secondary" data-attendance-record-cancel-registration><?php echo e(t('settings.cancel_attendance')); ?></button>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

                <div class="settings-list-wrap">
                    <div class="settings-list-row settings-list-header settings-list-cols settings-list-cols-assignment">
                        <strong><?php echo e(t('common.date')); ?></strong>
                        <span><?php echo e(t('settings.employee_label')); ?></span>
                        <span><?php echo e(t('common.department')); ?></span>
                        <span><?php echo e(t('common.shift')); ?></span>
                        <span><?php echo e(t('common.status')); ?></span>
                        <span><?php echo e(t('employee.check_in')); ?></span>
                        <span><?php echo e(t('settings.signature_label')); ?></span>
                    </div>
                    <?php if (empty($attendances)): ?>
                        <div class="crud-empty-state"><?php echo e(t('settings.no_attendance_records')); ?></div>
                    <?php else: ?>
                        <?php foreach (array_slice($attendances, 0, 250) as $attendance): ?>
                            <?php
                                $attendanceStatusRaw = strtolower(trim((string) ($attendance['status'] ?? 'present')));
                                $checkInTimeRaw = trim((string) ($attendance['check_in_time'] ?? ''));
                                $shiftStartTimeRaw = trim((string) ($attendance['shift_start_time'] ?? ''));
                                $isLateCheckIn = $attendanceStatusRaw === 'late';
                                if (!$isLateCheckIn && $checkInTimeRaw !== '' && $shiftStartTimeRaw !== '') {
                                    $isLateCheckIn = strtotime('1970-01-01 ' . $checkInTimeRaw) > strtotime('1970-01-01 ' . $shiftStartTimeRaw);
                                }
                                $displayAttendanceStatus = $isLateCheckIn ? t('settings.late') : ucfirst($attendanceStatusRaw !== '' ? $attendanceStatusRaw : 'present');
                            ?>
                            <article class="settings-list-item-wrap">
                                <div class="settings-list-row settings-list-cols settings-list-cols-assignment">
                                    <strong><?php echo e($attendance['work_date'] ?? ''); ?></strong>
                                    <span><?php echo e($attendance['user_name'] ?? '--'); ?></span>
                                    <span><?php echo e($attendance['department_name'] ?? '--'); ?></span>
                                    <span><?php echo e($attendance['shift_name'] ?? '--'); ?></span>
                                    <span><?php echo e($displayAttendanceStatus); ?></span>
                                    <span><?php echo e($attendance['check_in_time'] ?? '--'); ?></span>
                                    <div class="settings-inline-actions settings-inline-actions-compact">
                                        <span>
                                            <?php
                                                if (!empty($attendance['digital_signature_id'])) {
                                                    echo $isLateCheckIn ? t('settings.signed_late') : t('settings.signed');
                                                } else {
                                                    echo t('settings.missing');
                                                }
                                            ?>
                                        </span>
                                        <button
                                            type="button"
                                            class="admin-action-link admin-action-link-secondary"
                                            data-attendance-record-edit
                                            data-attendance-id="<?php echo (int) ($attendance['id'] ?? 0); ?>"
                                        >
                                            <?php echo e(t('settings.edit')); ?>
                                        </button>
                                        <button
                                            type="button"
                                            class="admin-action-link admin-action-link-secondary"
                                            data-attendance-record-delete
                                            data-attendance-id="<?php echo (int) ($attendance['id'] ?? 0); ?>"
                                        >
                                            <?php echo e(t('employee.cancel')); ?>
                                        </button>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </section>

            <?php if ($currentRole !== 'department_manager'): ?>
            <section class="crud-panel settings-panel" data-settings-panel="departments" hidden>
                <div class="settings-panel-head">
                    <div>
                        <h3><?php echo e(t('settings.departments')); ?></h3>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.departments_list_hint')); ?></p>
                    </div>
                    <div class="settings-pill-row">
                        <span class="settings-pill"><?php echo count($visibleDepartments); ?> <?php echo e(t('settings.items')); ?></span>
                    </div>
                </div>

                <?php if ($canCreateDepartments): ?>
                    <div class="settings-list-head settings-create-row" data-dept-create-row>
                        <div class="settings-list-cols settings-list-cols-dept">
                            <label class="settings-field"><?php echo e(t('settings.name_label')); ?><input data-field="name" type="text" value=""></label>
                            <label class="settings-field"><?php echo e(t('schedule.icon')); ?>
                                <div class="settings-picker-stack">
                                    <div class="settings-picker-row">
                                        <input data-field="icon" data-icon-preview data-icon-base="<?php echo e($basePath . '/assets/icons/'); ?>" type="text" value="<?php echo e($defaultPickerIcon); ?>" readonly>
                                        <button type="button" class="settings-picker-toggle" data-picker-toggle="icon"><?php echo e(t('common.select')); ?></button>
                                    </div>
                                    <div class="settings-picker-popover" data-picker-popover="icon" hidden>
                                        <div class="settings-choice-grid settings-choice-grid-icons" data-choice-field="icon">
                                            <?php foreach ($departmentIconCatalog as $icon): ?>
                                                <button type="button" class="settings-choice-btn settings-choice-btn-icon" data-choice-value="<?php echo e($icon); ?>" aria-label="<?php echo e(t('settings.choose_icon')); ?> <?php echo e($iconLabel((string) $icon)); ?>">
                                                    <img src="<?php echo e($iconUrl((string) $icon)); ?>" alt="" aria-hidden="true" class="settings-choice-icon-image">
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </label>
                            <label class="settings-field"><?php echo e(t('schedule.color')); ?>
                                <div class="settings-picker-stack">
                                    <div class="settings-picker-row">
                                        <input data-field="color" type="hidden" value="#b98b12">
                                        <input data-color-preview type="text" value="" readonly aria-label="<?php echo e(t('settings.selected_color_preview')); ?>" style="--selected-color: #b98b12;">
                                        <button type="button" class="settings-picker-toggle" data-picker-toggle="color"><?php echo e(t('common.select')); ?></button>
                                    </div>
                                    <div class="settings-picker-popover" data-picker-popover="color" hidden>
                                        <div class="settings-choice-grid" data-choice-field="color">
                                            <?php foreach ($pickerColorCatalog as $color): ?>
                                                <button type="button" class="settings-choice-btn settings-choice-btn-color" data-choice-value="<?php echo e($color); ?>" data-choice-label="<?php echo e($pickerColorLabel($color)); ?>" style="--choice-color: <?php echo e($color); ?>;" aria-label="<?php echo e(t('settings.choose_color')); ?> <?php echo e($pickerColorLabel($color)); ?>">
                                                    <span class="settings-color-swatch" aria-hidden="true"></span>
                                                    <span class="settings-choice-label"><?php echo e($pickerColorLabel($color)); ?></span>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </label>
                            <label class="settings-field"><?php echo e(t('settings.head_of_department')); ?>
                                <select data-field="head_user_id">
                                    <option value="">-- <?php echo e(t('settings.unassigned')); ?> --</option>
                                    <?php foreach ($departmentCreateHeadUsers as $userOption): ?>
                                        <option value="<?php echo (int) ($userOption['id'] ?? 0); ?>">
                                            <?php echo e(trim((string) (($userOption['first_name'] ?? '') . ' ' . ($userOption['last_name'] ?? ''))) ?: ($userOption['email'] ?? t('settings.user_default'))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <input data-field="company_id" type="hidden" value="<?php echo (int) $scopeCompanyId; ?>">
                            <div class="settings-inline-actions">
                                <button type="button" class="admin-action-link settings-dept-create"><?php echo e(t('crud.create')); ?></button>
                                <button type="button" class="admin-action-link admin-action-link-secondary settings-dept-reset"><?php echo e(t('crud.reset')); ?></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="settings-list-wrap">
                    <div class="settings-list-row settings-list-header settings-list-cols settings-list-cols-dept">
                        <strong><?php echo e(t('settings.name_label')); ?></strong>
                        <span><?php echo e(t('settings.company_label')); ?></span>
                        <span><?php echo e(t('settings.lead_label')); ?></span>
                        <span><?php echo e(t('settings.staff_label')); ?></span>
                        <span><?php echo e(t('settings.shifts')); ?></span>
                        <span><?php echo e(t('common.action')); ?></span>
                    </div>

                    <?php if (empty($visibleDepartments)): ?>
                        <div class="crud-empty-state"><?php echo e(t('settings.no_departments_available')); ?></div>
                    <?php else: ?>
                        <?php foreach ($visibleDepartments as $department): ?>
                            <article class="settings-list-item-wrap" data-department-id="<?php echo (int) ($department['id'] ?? 0); ?>" data-company-id="<?php echo (int) ($department['company_id'] ?? $scopeCompanyId); ?>">
                                <div class="settings-list-row settings-list-cols settings-list-cols-dept">
                                    <strong class="settings-dept-title">
                                        <span class="settings-dept-title-icon" style="color: <?php echo e($department['color'] ?? '#b98b12'); ?>;">
                                            <?php $deptIconValue = (string) ($department['icon'] ?? $defaultPickerIcon); ?>
                                            <?php if ($isIconAsset($deptIconValue)): ?>
                                                <img src="<?php echo e($iconUrl($deptIconValue)); ?>" alt="" aria-hidden="true" class="settings-icon-inline-image">
                                            <?php else: ?>
                                                <?php echo e($deptIconValue); ?>
                                            <?php endif; ?>
                                        </span>
                                        <span><?php echo e($department['name'] ?? t('settings.department_default')); ?></span>
                                    </strong>
                                    <span><?php echo e($department['company_name'] ?? $scopeCompanyName); ?></span>
                                    <span><?php echo e($department['head_user_name'] ?: t('settings.unassigned')); ?></span>
                                    <span><?php echo count($department['users'] ?? []); ?></span>
                                    <span><?php echo count($department['shifts'] ?? []); ?></span>
                                    <?php if ($canManageDepartments): ?>
                                        <div class="settings-inline-actions">
                                            <button type="button" class="admin-action-link admin-action-link-secondary settings-action-icon settings-dept-edit" aria-label="<?php echo e(t('settings.edit_department')); ?>" title="<?php echo e(t('settings.edit_department')); ?>">✎</button>
                                            <button type="button" class="admin-action-link admin-action-link-secondary settings-action-icon settings-action-icon-danger settings-dept-delete" data-department-id="<?php echo (int) ($department['id'] ?? 0); ?>" aria-label="<?php echo e(t('settings.delete_department')); ?>" title="<?php echo e(t('settings.delete_department')); ?>">🗑</button>
                                        </div>
                                    <?php else: ?>
                                        <span class="admin-action-link admin-action-link-secondary"><?php echo e(t('settings.locked')); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($canManageDepartments): ?>
                                <div class="settings-edit-drawer" hidden>
                                    <div class="settings-list-cols settings-list-cols-dept-edit">
                                        <label class="settings-field"><?php echo e(t('settings.name_label')); ?><input data-field="name" type="text" value="<?php echo e($department['name'] ?? t('settings.department_default')); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('schedule.icon')); ?>
                                            <div class="settings-picker-stack">
                                                <div class="settings-picker-row">
                                                    <input data-field="icon" data-icon-preview data-icon-base="<?php echo e($basePath . '/assets/icons/'); ?>" type="text" value="<?php echo e($department['icon'] ?? $defaultPickerIcon); ?>" readonly>
                                                    <button type="button" class="settings-picker-toggle" data-picker-toggle="icon"><?php echo e(t('common.select')); ?></button>
                                                </div>
                                                <div class="settings-picker-popover" data-picker-popover="icon" hidden>
                                                    <div class="settings-choice-grid settings-choice-grid-icons" data-choice-field="icon">
                                                        <?php foreach ($departmentIconCatalog as $icon): ?>
                                                            <button type="button" class="settings-choice-btn settings-choice-btn-icon" data-choice-value="<?php echo e($icon); ?>" aria-label="<?php echo e(t('settings.choose_icon')); ?> <?php echo e($iconLabel((string) $icon)); ?>">
                                                                <img src="<?php echo e($iconUrl((string) $icon)); ?>" alt="" aria-hidden="true" class="settings-choice-icon-image">
                                                            </button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </label>
                                        <label class="settings-field"><?php echo e(t('schedule.color')); ?>
                                            <div class="settings-picker-stack">
                                                <div class="settings-picker-row">
                                                    <input data-field="color" type="hidden" value="<?php echo e($department['color'] ?? '#b98b12'); ?>">
                                                    <input data-color-preview type="text" value="" readonly aria-label="<?php echo e(t('settings.selected_color_preview')); ?>" style="--selected-color: <?php echo e($department['color'] ?? '#b98b12'); ?>;">
                                                    <button type="button" class="settings-picker-toggle" data-picker-toggle="color"><?php echo e(t('common.select')); ?></button>
                                                </div>
                                                <div class="settings-picker-popover" data-picker-popover="color" hidden>
                                                    <div class="settings-choice-grid" data-choice-field="color">
                                                        <?php foreach ($pickerColorCatalog as $color): ?>
                                                            <button type="button" class="settings-choice-btn settings-choice-btn-color" data-choice-value="<?php echo e($color); ?>" data-choice-label="<?php echo e($pickerColorLabel($color)); ?>" style="--choice-color: <?php echo e($color); ?>;" aria-label="<?php echo e(t('settings.choose_color')); ?> <?php echo e($pickerColorLabel($color)); ?>">
                                                                <span class="settings-color-swatch" aria-hidden="true"></span>
                                                                <span class="settings-choice-label"><?php echo e($pickerColorLabel($color)); ?></span>
                                                            </button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </label>
                                        <label class="settings-field"><?php echo e(t('settings.head_of_department')); ?>
                                            <select data-field="head_user_id">
                                                <option value="">-- <?php echo e(t('settings.unassigned')); ?> --</option>
                                                <?php
                                                $deptIdForHead = (int) ($department['id'] ?? 0);
                                                $eligibleHeadUsers = array_values(array_filter(
                                                    $visibleUsers,
                                                    static fn(array $u): bool => ((int) ($u['company_id'] ?? 0) === $scopeCompanyId)
                                                        && (((int) ($u['department_id'] ?? 0) === 0) || ((int) ($u['department_id'] ?? 0) === $deptIdForHead))
                                                ));
                                                foreach ($eligibleHeadUsers as $userOption): ?>
                                                    <option value="<?php echo (int) ($userOption['id'] ?? 0); ?>" <?php echo ((int) ($department['head_user_id'] ?? 0) === (int) ($userOption['id'] ?? 0)) ? 'selected' : ''; ?>>
                                                        <?php echo e(trim((string) (($userOption['first_name'] ?? '') . ' ' . ($userOption['last_name'] ?? ''))) ?: ($userOption['email'] ?? t('settings.user_default'))); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <div class="settings-inline-actions">
                                            <button type="button" class="admin-action-link settings-dept-save"><?php echo e(t('settings.save')); ?></button>
                                            <button type="button" class="admin-action-link admin-action-link-secondary settings-dept-cancel"><?php echo e(t('employee.cancel')); ?></button>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="crud-panel settings-panel" data-settings-panel="users" hidden>
                <div class="settings-panel-head">
                    <div>
                        <h3><?php echo e(t('settings.users')); ?></h3>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.users_list_hint')); ?></p>
                    </div>
                    <div class="settings-pill-row">
                        <span class="settings-pill"><?php echo count($visibleUsers); ?> <?php echo e(t('settings.users_suffix')); ?></span>
                    </div>
                </div>

                <div class="settings-list-head settings-create-row" data-user-create-row>
                    <div class="settings-list-cols settings-list-cols-user-create">
                        <input data-field="company_id" type="hidden" value="<?php echo (int) $scopeCompanyId; ?>">
                        <label class="settings-field"><?php echo e(t('crud.first_name')); ?><input data-field="first_name" type="text" value=""></label>
                        <label class="settings-field"><?php echo e(t('crud.last_name')); ?><input data-field="last_name" type="text" value=""></label>
                        <label class="settings-field"><?php echo e(t('crud.email')); ?><input data-field="email" type="email" value=""></label>
                        <label class="settings-field"><?php echo e(t('crud.role')); ?>
                            <select data-field="role">
                                <?php foreach ($roleCatalog as $r): ?>
                                    <?php if ($currentRole === 'admin' && $r['key'] === 'super_admin') continue; ?>
                                    <option value="<?php echo e($r['key']); ?>" <?php echo $r['key'] === 'employee' ? 'selected' : ''; ?>><?php echo e($r['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="settings-field"><?php echo e(t('common.department')); ?>
                            <select data-field="department_id">
                                <option value=""><?php echo e(t('crud.select_department')); ?></option>
                                <?php foreach ($visibleDepartments as $department): ?>
                                    <option value="<?php echo (int) ($department['id'] ?? 0); ?>"><?php echo e($department['name'] ?? t('settings.department_default')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="settings-field"><?php echo e(t('crud.password')); ?><input data-field="password" type="text" value=""></label>
                        <div class="settings-inline-actions">
                            <button type="button" class="admin-action-link settings-user-create"><?php echo e(t('crud.create_user')); ?></button>
                            <button type="button" class="admin-action-link admin-action-link-secondary settings-user-reset"><?php echo e(t('crud.reset')); ?></button>
                        </div>
                    </div>
                </div>

                <div class="settings-list-wrap">
                    <div class="settings-list-row settings-list-header settings-list-cols settings-list-cols-user">
                        <strong><?php echo e(t('crud.first_name')); ?></strong>
                        <span><?php echo e(t('crud.email')); ?></span>
                        <span><?php echo e(t('crud.role')); ?></span>
                        <span><?php echo e(t('common.department')); ?></span>
                        <span><?php echo e(t('common.status')); ?></span>
                        <span><?php echo e(t('common.action')); ?></span>
                    </div>

                    <?php if (empty($visibleUsers)): ?>
                        <div class="crud-empty-state"><?php echo e(t('settings.no_users_assigned')); ?></div>
                    <?php else: ?>
                        <?php foreach (array_slice($visibleUsers, 0, 200) as $user): ?>
                            <?php
                                $userDepartmentIds = is_array($user['department_ids'] ?? null)
                                    ? array_values(array_filter(array_map('intval', $user['department_ids']), static fn (int $id): bool => $id > 0))
                                    : [];
                                if (empty($userDepartmentIds) && (int) ($user['department_id'] ?? 0) > 0) {
                                    $userDepartmentIds[] = (int) ($user['department_id'] ?? 0);
                                }
                                $userDepartmentNames = is_array($user['department_names'] ?? null)
                                    ? array_values(array_filter(array_map(static fn ($name): string => trim((string) $name), $user['department_names']), static fn (string $name): bool => $name !== ''))
                                    : [];
                                if (empty($userDepartmentNames) && trim((string) ($user['department_name'] ?? '')) !== '') {
                                    $userDepartmentNames[] = trim((string) ($user['department_name'] ?? ''));
                                }
                            ?>
                            <article class="settings-list-item-wrap" data-user-id="<?php echo (int) ($user['id'] ?? 0); ?>">
                                <div class="settings-list-row settings-list-cols settings-list-cols-user">
                                    <strong><?php echo e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: t('settings.user_default')); ?></strong>
                                    <span><?php echo e($user['email'] ?? ''); ?></span>
                                    <span><?php echo e($roleLabels[$user['role'] ?? 'employee'] ?? ucfirst((string) ($user['role'] ?? 'employee'))); ?></span>
                                    <span><?php echo e(!empty($userDepartmentNames) ? implode(', ', $userDepartmentNames) : '--'); ?></span>
                                    <span><?php echo e($user['status'] ?? 'active'); ?></span>
                                    <div class="settings-inline-actions">
                                        <button type="button" class="admin-action-link admin-action-link-secondary settings-user-edit" aria-label="<?php echo e(t('crud.edit_user')); ?>" title="<?php echo e(t('crud.edit_user')); ?>">✎ <?php echo e(t('settings.edit')); ?></button>
                                        <button type="button" class="admin-action-link admin-action-link-secondary settings-action-icon-danger settings-user-delete" data-user-id="<?php echo (int) ($user['id'] ?? 0); ?>" aria-label="<?php echo e(t('crud.delete_user')); ?>" title="<?php echo e(t('crud.delete_user')); ?>">🗑 <?php echo e(t('schedule.delete')); ?></button>
                                    </div>
                                </div>

                                <div class="settings-edit-drawer" hidden>
                                    <div class="settings-list-cols settings-list-cols-user-edit">
                                        <label class="settings-field"><?php echo e(t('crud.first_name')); ?><input data-field="first_name" type="text" value="<?php echo e($user['first_name'] ?? ''); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('crud.last_name')); ?><input data-field="last_name" type="text" value="<?php echo e($user['last_name'] ?? ''); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('crud.email')); ?><input data-field="email" type="email" value="<?php echo e($user['email'] ?? ''); ?>"></label>
                                        <label class="settings-field"><?php echo e(t('crud.role')); ?>
                                            <select data-field="role">
                                                <?php foreach ($roleCatalog as $r): ?>
                                                    <?php if ($currentRole === 'admin' && $r['key'] === 'super_admin') continue; ?>
                                                    <option value="<?php echo e($r['key']); ?>" <?php echo (($user['role'] ?? '') === $r['key']) ? 'selected' : ''; ?>><?php echo e($r['label']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="settings-field"><?php echo e(t('common.department')); ?>
                                            <select data-field="department_id">
                                                <option value=""><?php echo e(t('crud.select_department')); ?></option>
                                                <?php foreach ($visibleDepartments as $department): ?>
                                                    <?php $departmentOptionId = (int) ($department['id'] ?? 0); ?>
                                                    <option value="<?php echo $departmentOptionId; ?>" <?php echo in_array($departmentOptionId, $userDepartmentIds, true) ? 'selected' : ''; ?>><?php echo e($department['name'] ?? t('settings.department_default')); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="settings-field"><?php echo e(t('common.status')); ?>
                                            <select data-field="status">
                                                <option value="active" <?php echo (($user['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>><?php echo e(t('crud.active')); ?></option>
                                                <option value="inactive" <?php echo (($user['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>><?php echo e(t('crud.inactive')); ?></option>
                                            </select>
                                        </label>
                                        <label class="settings-field"><?php echo e(t('crud.password')); ?><input data-field="password" type="text" value="" placeholder="<?php echo e(t('crud.leave_blank_password')); ?>"></label>
                                        <div class="settings-inline-actions">
                                            <button type="button" class="admin-action-link settings-user-save" data-user-id="<?php echo (int) ($user['id'] ?? 0); ?>"><?php echo e(t('settings.save')); ?></button>
                                            <button type="button" class="admin-action-link admin-action-link-secondary settings-user-cancel"><?php echo e(t('employee.cancel')); ?></button>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <section class="settings-hours-report" data-user-hours-report>
                    <div class="settings-panel-head">
                        <div>
                            <h4><?php echo e(t('settings.employee_hours_report_title', ['fallback' => 'Monthly hours report'])); ?></h4>
                            <p class="crud-modal-subtitle"><?php echo e(t('settings.employee_hours_report_hint', ['fallback' => 'Historical worked hours and monthly planned hours by employee.'])); ?></p>
                        </div>
                        <div class="settings-hours-report-controls">
                            <label class="settings-field">
                                <?php echo e(t('settings.target_month', ['fallback' => 'Target month'])); ?>
                                <input type="month" data-hours-report-month value="<?php echo e(date('Y-m')); ?>">
                            </label>
                            <button type="button" class="admin-action-link admin-action-link-secondary" data-hours-report-refresh>
                                <?php echo e(t('settings.refresh_hours_report_label', ['fallback' => 'Refresh report'])); ?>
                            </button>
                            <button type="button" class="admin-action-link" data-hours-report-print-all>
                                <?php echo e(t('settings.print_hours_report_label', ['fallback' => 'Print monthly report'])); ?>
                            </button>
                        </div>
                    </div>
                    <div class="settings-list-wrap settings-hours-report-wrap">
                        <div class="settings-list-row settings-list-header settings-list-cols settings-list-cols-hours-report">
                            <strong><?php echo e(t('settings.employee_label', ['fallback' => 'Employee'])); ?></strong>
                            <span><?php echo e(t('settings.hours_worked_label', ['fallback' => 'Worked (historical)'])); ?></span>
                            <span><?php echo e(t('settings.hours_planned_label', ['fallback' => 'Planned (forecast)'])); ?></span>
                            <span><?php echo e(t('settings.hours_delta_label', ['fallback' => 'Delta'])); ?></span>
                            <span><?php echo e(t('settings.hours_override_label', ['fallback' => 'Worked override'])); ?></span>
                            <span><?php echo e(t('settings.note_label', ['fallback' => 'Note'])); ?></span>
                            <span><?php echo e(t('common.action')); ?></span>
                        </div>
                        <div data-hours-report-body></div>
                    </div>
                </section>
            </section>

            <section class="crud-panel settings-panel" data-settings-panel="shifts" hidden>
                <div class="settings-panel-head">
                    <div>
                        <h3><?php echo e(t('settings.shifts')); ?></h3>
                        <p class="crud-modal-subtitle"><?php echo e(t('settings.shifts_list_hint')); ?></p>
                    </div>
                    <div class="settings-pill-row">
                        <span class="settings-pill"><?php echo count($visibleShiftGroups); ?> <?php echo e(t('settings.shifts_suffix')); ?></span>
                    </div>
                </div>

                <?php if ($canCreateShifts): ?>
                <div class="settings-list-head settings-create-row settings-create-row-shift" data-shift-create-row>
                    <div class="settings-list-cols settings-list-cols-shift-create">
                        <div class="settings-shift-create-column settings-shift-create-column-left">
                            <h4 class="settings-shift-block-title"><?php echo e(t('settings.shift_core_block')); ?></h4>
                            <label class="settings-field settings-field-departments"><?php echo e(t('common.department')); ?>
                                <select data-field="department_ids" multiple size="4">
                                    <?php foreach ($visibleDepartments as $department): ?>
                                        <option value="<?php echo (int) ($department['id'] ?? 0); ?>" <?php echo (int) ($department['id'] ?? 0) === (int) ($planner['active_department_id'] ?? 0) ? 'selected' : ''; ?>>
                                            <?php echo e($department['name'] ?? t('settings.department_default')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="settings-shift-create-hint"><?php echo e($isFrLocale ? 'Cliquez pour sélectionner un ou plusieurs départements.' : 'Click to select one or more departments.'); ?></small>
                            </label>
                            <label class="settings-field settings-field-shift-name"><?php echo e(t('settings.name_label')); ?><input data-field="name" type="text" value="" placeholder="<?php echo e(t('settings.shift_name_placeholder')); ?>"></label>
                            <?php if ($currentRole === 'super_admin'): ?>
                                <label class="settings-field settings-field-shift-kind"><?php echo e(t('crud.role')); ?>
                                    <select data-field="kind">
                                        <option value="work" selected><?php echo e(t('settings.kind_work')); ?></option>
                                        <option value="rest"><?php echo e(t('settings.rest')); ?></option>
                                        <option value="vacation"><?php echo e(t('settings.vacation')); ?></option>
                                        <option value="sick"><?php echo e(t('settings.sick')); ?></option>
                                    </select>
                                </label>
                            <?php endif; ?>
                            <small class="settings-shift-create-hint"><?php echo e(t('settings.shift_create_quick_hint')); ?></small>
                            <label class="settings-field"><?php echo e(t('settings.from_date')); ?><input data-field="range_start" type="date" value=""></label>
                            <label class="settings-field"><?php echo e(t('settings.to_date')); ?><input data-field="range_end" type="date" value=""></label>
                            <details class="settings-shift-advanced" open>
                                <summary><?php echo e(t('settings.shift_advanced_options')); ?></summary>
                                <div class="settings-shift-advanced-grid">
                                    <div class="settings-shift-advanced-block">
                                        <h5><?php echo e(t('settings.shift_work_pattern')); ?></h5>
                                    <label class="settings-field settings-field-departments"><?php echo e(t('settings.work_weekdays_label')); ?>
                                        <select data-field="work_weekdays" multiple size="4">
                                            <?php foreach ($weekdayLabels as $weekdayValue => $weekdayLabel): ?>
                                                <option value="<?php echo (int) $weekdayValue; ?>" selected><?php echo e($weekdayLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="settings-shift-create-hint"><?php echo e(t('settings.shift_work_weekdays_hint', ['fallback' => 'Questi giorni generano slot di lavoro.'])); ?></small>
                                    </label>
                                    <label class="settings-field settings-field-departments"><?php echo e(t('settings.months_to_cover_label')); ?>
                                        <select data-field="month_numbers" multiple size="4">
                                            <?php foreach ($monthLabels as $monthValue => $monthLabel): ?>
                                                <option value="<?php echo (int) $monthValue; ?>" selected><?php echo e($monthLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    </div>

                                    <div class="settings-shift-advanced-block">
                                        <h5><?php echo e(t('settings.shift_rest_pattern')); ?></h5>
                                    <label class="settings-field settings-field-departments"><?php echo e(t('settings.weekly_rest_days_label')); ?>
                                        <select data-field="weekly_rest_weekdays" multiple size="4">
                                            <?php foreach ($weekdayLabels as $weekdayValue => $weekdayLabel): ?>
                                                <option value="<?php echo (int) $weekdayValue; ?>"><?php echo e($weekdayLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="settings-shift-create-hint"><?php echo e(t('settings.shift_rest_weekdays_hint')); ?></small>
                                    </label>
                                    <label class="settings-field"><?php echo e(t('settings.include_rest_day_label')); ?>
                                        <select data-field="include_restday">
                                            <option value="0" selected><?php echo e(t('settings.no_label')); ?></option>
                                            <option value="1"><?php echo e(t('settings.yes_label')); ?></option>
                                        </select>
                                    </label>
                                    <label class="settings-field settings-field-departments"><?php echo e(t('settings.rest_day_weekdays_label')); ?>
                                        <select data-field="restday_weekdays" multiple size="4">
                                            <?php foreach ($weekdayLabels as $weekdayValue => $weekdayLabel): ?>
                                                <option value="<?php echo (int) $weekdayValue; ?>"><?php echo e($weekdayLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="settings-shift-create-hint"><?php echo e(t('settings.rest_day_weekdays_hint')); ?></small>
                                    </label>
                                    <label class="settings-field"><?php echo e(t('settings.rest_day_repetition_label')); ?>
                                        <select data-field="restday_repeat_mode">
                                            <option value="weekly" selected><?php echo e(t('settings.repeat_weekly')); ?></option>
                                            <option value="monthly"><?php echo e(t('settings.repeat_monthly')); ?></option>
                                        </select>
                                    </label>
                                    <label class="settings-field"><?php echo e(t('settings.rest_day_scale_label')); ?>
                                        <select data-field="restday_scale_mode">
                                            <option value="weekly" selected><?php echo e(t('settings.scale_week')); ?></option>
                                            <option value="monthly"><?php echo e(t('settings.scale_month')); ?></option>
                                        </select>
                                    </label>
                                    </div>
                                </div>
                            </details>
                        </div>

                        <div class="settings-shift-create-column settings-shift-create-column-right">
                            <h4 class="settings-shift-block-title"><?php echo e(t('settings.shift_visual_block')); ?></h4>
                            <label class="settings-field"><?php echo e(t('schedule.icon')); ?>
                                <div class="settings-picker-stack">
                                    <div class="settings-picker-row">
                                        <input data-field="icon" data-icon-preview data-icon-base="<?php echo e($basePath . '/assets/icons/'); ?>" type="text" value="<?php echo e($defaultPickerIcon); ?>" readonly>
                                        <button type="button" class="settings-picker-toggle" data-picker-toggle="icon"><?php echo e(t('common.select')); ?></button>
                                    </div>
                                    <div class="settings-picker-popover" data-picker-popover="icon" hidden>
                                        <div class="settings-choice-grid settings-choice-grid-icons" data-choice-field="icon">
                                            <?php foreach ($shiftIconCatalog as $icon): ?>
                                                <button type="button" class="settings-choice-btn settings-choice-btn-icon" data-choice-value="<?php echo e($icon); ?>" aria-label="<?php echo e(t('settings.choose_icon')); ?> <?php echo e($iconLabel((string) $icon)); ?>">
                                                    <img src="<?php echo e($iconUrl((string) $icon)); ?>" alt="" aria-hidden="true" class="settings-choice-icon-image">
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </label>
                            <label class="settings-field"><?php echo e(t('schedule.color')); ?>
                                <div class="settings-picker-stack">
                                    <div class="settings-picker-row">
                                        <input data-field="color" type="hidden" value="#2f6fed">
                                        <input data-color-preview type="text" value="" readonly aria-label="<?php echo e(t('settings.selected_color_preview')); ?>" style="--selected-color: #2f6fed;">
                                        <button type="button" class="settings-picker-toggle" data-picker-toggle="color"><?php echo e(t('common.select')); ?></button>
                                    </div>
                                    <div class="settings-picker-popover" data-picker-popover="color" hidden>
                                        <div class="settings-choice-grid" data-choice-field="color">
                                            <?php foreach ($pickerColorCatalog as $color): ?>
                                                <button type="button" class="settings-choice-btn settings-choice-btn-color" data-choice-value="<?php echo e($color); ?>" data-choice-label="<?php echo e($pickerColorLabel($color)); ?>" style="--choice-color: <?php echo e($color); ?>;" aria-label="<?php echo e(t('settings.choose_color')); ?> <?php echo e($pickerColorLabel($color)); ?>">
                                                    <span class="settings-color-swatch" aria-hidden="true"></span>
                                                    <span class="settings-choice-label"><?php echo e($pickerColorLabel($color)); ?></span>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </label>
                            <label class="settings-field"><?php echo e(t('crud.description')); ?>
                                <input data-field="description" type="text" value="" placeholder="<?php echo e(t('settings.shift_notes_placeholder')); ?>">
                            </label>
                            <label class="settings-field"><?php echo e(t('schedule.start')); ?><input data-field="start_time" type="time" value="09:00"></label>
                            <label class="settings-field"><?php echo e(t('schedule.end')); ?><input data-field="end_time" type="time" value="17:00"></label>
                        </div>

                        <div class="settings-shift-create-actions">
                            <h4 class="settings-shift-block-title"><?php echo e(t('settings.shift_actions_block')); ?></h4>
                            <button type="button" class="admin-action-link settings-shift-create"><?php echo e(t('settings.create_shift')); ?></button>
                            <button type="button" class="admin-action-link admin-action-link-secondary settings-shift-reset"><?php echo e(t('crud.reset')); ?></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="settings-list-wrap">
                    <div class="settings-list-row settings-list-header settings-list-cols settings-list-cols-shift">
                        <strong><?php echo e(t('crud.department_name')); ?></strong>
                        <span><?php echo e(t('common.department')); ?></span>
                        <span><?php echo e(t('crud.description')); ?></span>
                        <span><?php echo e(t('settings.time_label')); ?></span>
                        <span><?php echo e(t('schedule.icon')); ?></span>
                        <span><?php echo e(t('schedule.color')); ?></span>
                        <span><?php echo e(t('common.action')); ?></span>
                    </div>

                    <?php if (empty($visibleShiftGroups)): ?>
                        <div class="crud-empty-state"><?php echo e(t('settings.no_shifts_available')); ?></div>
                    <?php else: ?>
                        <?php foreach ($visibleShiftGroups as $shiftGroup): ?>
                            <?php
                                $shift = is_array($shiftGroup['shift'] ?? null) ? $shiftGroup['shift'] : [];
                                $sharedShiftIds = is_array($shiftGroup['shift_ids'] ?? null) ? $shiftGroup['shift_ids'] : [];
                                $sharedDepartments = is_array($shiftGroup['departments'] ?? null) ? $shiftGroup['departments'] : [];
                                $sharedDepartmentDisplay = !empty($sharedDepartments)
                                    ? implode(', ', $sharedDepartments)
                                    : ((string) ($shift['department_name'] ?? ''));
                            ?>
                            <?php
                                $shiftKindRaw = strtolower(trim((string) ($shift['kind'] ?? 'work')));
                                $shiftNameRaw = strtolower(trim((string) ($shift['name'] ?? '')));
                                $isSystemShiftTemplate = in_array($shiftKindRaw, ['rest', 'vacation', 'sick'], true)
                                    || in_array($shiftNameRaw, ['rest day', 'vacation', 'sick leave', 'repos', 'vacances', 'maladie', 'riposo', 'ferie', 'malattia'], true);
                            ?>
                            <?php
                                $shiftKindValue = (string) ($shift['kind'] ?? 'work');
                                $shiftNameValue = (string) ($shift['name'] ?? t('settings.shift_default'));
                                $shiftDisplayName = $localizedSystemShiftName($shiftKindValue, $shiftNameValue);
                                $shiftDescriptionDisplay = $localizedSystemShiftDescription($shiftKindValue, (string) ($shift['description'] ?? '--'));
                                if ($shiftDisplayName === '') {
                                    $shiftDisplayName = $shiftNameValue;
                                }
                            ?>
                            <article class="settings-list-item-wrap" data-shift-id="<?php echo (int) ($shift['id'] ?? 0); ?>" data-shift-kind="<?php echo e($shiftKindValue); ?>" data-shift-department-id="<?php echo (int) ($shift['department_id'] ?? 0); ?>" data-shift-name="<?php echo e($shiftNameValue); ?>" data-shift-shared-ids="<?php echo e(implode(',', $sharedShiftIds)); ?>">
                                <div class="settings-list-row settings-list-cols settings-list-cols-shift">
                                    <strong><?php echo e($shiftDisplayName); ?></strong>
                                    <span><?php echo e($sharedDepartmentDisplay); ?></span>
                                    <span>
                                        <?php echo e($shiftDescriptionDisplay); ?>
                                        <?php if ($isSystemShiftTemplate && $currentRole !== 'super_admin'): ?>
                                            <br><small><?php echo e(t('settings.system_template_read_only')); ?></small>
                                        <?php endif; ?>
                                    </span>
                                    <span><?php echo e(($shift['start_time'] ?? '--:--') . ' - ' . ($shift['end_time'] ?? '--:--')); ?></span>
                                    <span>
                                        <?php $shiftIconValue = (string) ($shift['icon'] ?? $defaultPickerIcon); ?>
                                        <?php if ($isIconAsset($shiftIconValue)): ?>
                                            <img src="<?php echo e($iconUrl($shiftIconValue)); ?>" alt="" aria-hidden="true" class="settings-icon-inline-image">
                                        <?php else: ?>
                                            <?php echo e($shiftIconValue); ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="settings-color-display" style="--choice-color: <?php echo e($shift['color'] ?? '#2f6fed'); ?>;">
                                        <span class="settings-color-swatch" aria-hidden="true"></span>
                                        <span><?php echo e($pickerColorLabel((string) ($shift['color'] ?? '#2f6fed'))); ?></span>
                                    </span>
                                    <div class="settings-inline-actions">
                                        <?php if ($isSystemShiftTemplate && $currentRole !== 'super_admin'): ?>
                                            <span class="admin-action-link admin-action-link-secondary" aria-label="<?php echo e(t('settings.system_template')); ?>" title="<?php echo e(t('settings.system_template')); ?>"><?php echo e(t('settings.locked')); ?></span>
                                        <?php elseif (!$canManageShifts): ?>
                                            <span class="admin-action-link admin-action-link-secondary"><?php echo e(t('settings.locked')); ?></span>
                                        <?php else: ?>
                                            <button type="button" class="admin-action-link admin-action-link-secondary settings-action-icon settings-shift-edit" aria-label="<?php echo e(t('settings.edit_shift')); ?>" title="<?php echo e(t('settings.edit_shift')); ?>">✎</button>
                                            <button type="button" class="admin-action-link admin-action-link-secondary settings-action-icon settings-action-icon-danger settings-shift-delete" data-shift-id="<?php echo (int) ($shift['id'] ?? 0); ?>" aria-label="<?php echo e(t('settings.delete_shift')); ?>" title="<?php echo e(t('settings.delete_shift')); ?>">🗑</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($canManageShifts && (!$isSystemShiftTemplate || $currentRole === 'super_admin')): ?>
                                    <div class="settings-edit-drawer" hidden>
                                        <div class="settings-list-cols settings-list-cols-shift-edit">
                                            <label class="settings-field"><?php echo e(t('settings.name_label')); ?><input data-field="name" type="text" value="<?php echo e($shift['name'] ?? t('settings.shift_default')); ?>"></label>
                                            <label class="settings-field">Icon
                                                <div class="settings-picker-stack">
                                                    <div class="settings-picker-row">
                                                        <input data-field="icon" data-icon-preview data-icon-base="<?php echo e($basePath . '/assets/icons/'); ?>" type="text" value="<?php echo e($shift['icon'] ?? $defaultPickerIcon); ?>" readonly>
                                                        <button type="button" class="settings-picker-toggle" data-picker-toggle="icon"><?php echo e(t('common.select')); ?></button>
                                                    </div>
                                                    <div class="settings-picker-popover" data-picker-popover="icon" hidden>
                                                        <div class="settings-choice-grid settings-choice-grid-icons" data-choice-field="icon">
                                                            <?php foreach ($shiftIconCatalog as $icon): ?>
                                                                <button type="button" class="settings-choice-btn settings-choice-btn-icon" data-choice-value="<?php echo e($icon); ?>" aria-label="<?php echo e(t('settings.choose_icon')); ?> <?php echo e($iconLabel((string) $icon)); ?>">
                                                                    <img src="<?php echo e($iconUrl((string) $icon)); ?>" alt="" aria-hidden="true" class="settings-choice-icon-image">
                                                                </button>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                            <label class="settings-field"><?php echo e(t('schedule.color')); ?>
                                                <div class="settings-picker-stack">
                                                    <div class="settings-picker-row">
                                                        <input data-field="color" type="hidden" value="<?php echo e($shift['color'] ?? '#2f6fed'); ?>">
                                                        <input data-color-preview type="text" value="" readonly aria-label="<?php echo e(t('settings.selected_color_preview')); ?>" style="--selected-color: <?php echo e($shift['color'] ?? '#2f6fed'); ?>;">
                                                        <button type="button" class="settings-picker-toggle" data-picker-toggle="color"><?php echo e(t('common.select')); ?></button>
                                                    </div>
                                                    <div class="settings-picker-popover" data-picker-popover="color" hidden>
                                                        <div class="settings-choice-grid" data-choice-field="color">
                                                            <?php foreach ($pickerColorCatalog as $color): ?>
                                                                <button type="button" class="settings-choice-btn settings-choice-btn-color" data-choice-value="<?php echo e($color); ?>" data-choice-label="<?php echo e($pickerColorLabel($color)); ?>" style="--choice-color: <?php echo e($color); ?>;" aria-label="<?php echo e(t('settings.choose_color')); ?> <?php echo e($pickerColorLabel($color)); ?>">
                                                                    <span class="settings-color-swatch" aria-hidden="true"></span>
                                                                    <span class="settings-choice-label"><?php echo e($pickerColorLabel($color)); ?></span>
                                                                </button>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                            <label class="settings-field settings-field-wide"><?php echo e(t('crud.description')); ?>
                                                <input data-field="description" type="text" value="<?php echo e($shift['description'] ?? ''); ?>" placeholder="<?php echo e(t('settings.shift_notes_placeholder')); ?>">
                                            </label>
                                            <label class="settings-field"><?php echo e(t('schedule.start')); ?><input data-field="start_time" type="time" value="<?php echo e(substr((string) ($shift['start_time'] ?? ''), 0, 5)); ?>"></label>
                                            <label class="settings-field"><?php echo e(t('schedule.end')); ?><input data-field="end_time" type="time" value="<?php echo e(substr((string) ($shift['end_time'] ?? ''), 0, 5)); ?>"></label>
                                            <label class="settings-field"><?php echo e(t('settings.from_date')); ?><input data-field="range_start" type="date" value="<?php echo e(date('Y-m-01')); ?>"></label>
                                            <label class="settings-field"><?php echo e(t('settings.to_date')); ?><input data-field="range_end" type="date" value="<?php echo e(date('Y-m-t')); ?>"></label>
                                            <label class="settings-field settings-field-departments"><?php echo e(t('settings.work_weekdays_label')); ?>
                                                <select data-field="work_weekdays" multiple size="4">
                                                    <?php foreach ($weekdayLabels as $weekdayValue => $weekdayLabel): ?>
                                                        <option value="<?php echo (int) $weekdayValue; ?>" selected><?php echo e($weekdayLabel); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="settings-field settings-field-departments"><?php echo e(t('settings.months_to_cover_label')); ?>
                                                <select data-field="month_numbers" multiple size="4">
                                                    <?php foreach ($monthLabels as $monthValue => $monthLabel): ?>
                                                        <option value="<?php echo (int) $monthValue; ?>" selected><?php echo e($monthLabel); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="settings-field settings-field-departments"><?php echo e(t('settings.weekly_rest_days_label')); ?>
                                                <select data-field="weekly_rest_weekdays" multiple size="4">
                                                    <?php foreach ($weekdayLabels as $weekdayValue => $weekdayLabel): ?>
                                                        <option value="<?php echo (int) $weekdayValue; ?>"><?php echo e($weekdayLabel); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="settings-field"><?php echo e(t('settings.include_rest_day_label')); ?>
                                                <select data-field="include_restday">
                                                    <option value="0" selected><?php echo e(t('settings.no_label')); ?></option>
                                                    <option value="1"><?php echo e(t('settings.yes_label')); ?></option>
                                                </select>
                                            </label>
                                            <label class="settings-field settings-field-departments"><?php echo e(t('settings.rest_day_weekdays_label')); ?>
                                                <select data-field="restday_weekdays" multiple size="4">
                                                    <?php foreach ($weekdayLabels as $weekdayValue => $weekdayLabel): ?>
                                                        <option value="<?php echo (int) $weekdayValue; ?>"><?php echo e($weekdayLabel); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="settings-shift-create-hint"><?php echo e(t('settings.rest_day_weekdays_hint')); ?></small>
                                            </label>
                                            <label class="settings-field"><?php echo e(t('settings.rest_day_repetition_label')); ?>
                                                <select data-field="restday_repeat_mode">
                                                    <option value="weekly" selected><?php echo e(t('settings.repeat_weekly')); ?></option>
                                                    <option value="monthly"><?php echo e(t('settings.repeat_monthly')); ?></option>
                                                </select>
                                            </label>
                                            <label class="settings-field"><?php echo e(t('settings.rest_day_scale_label')); ?>
                                                <select data-field="restday_scale_mode">
                                                    <option value="weekly" selected><?php echo e(t('settings.scale_week')); ?></option>
                                                    <option value="monthly"><?php echo e(t('settings.scale_month')); ?></option>
                                                </select>
                                            </label>
                                            <label class="settings-field">
                                                <?php echo e(t('settings.regenerate_slots_label')); ?>
                                                <select data-field="regenerate_slots">
                                                    <option value="0" selected><?php echo e(t('settings.no_label')); ?></option>
                                                    <option value="1"><?php echo e(t('settings.yes_label')); ?></option>
                                                </select>
                                            </label>
                                            <div class="settings-inline-actions">
                                                <button type="button" class="admin-action-link settings-shift-save" data-shift-id="<?php echo (int) ($shift['id'] ?? 0); ?>"><?php echo e(t('settings.save')); ?></button>
                                                <button type="button" class="admin-action-link admin-action-link-secondary settings-shift-cancel"><?php echo e(t('employee.cancel')); ?></button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

    </div>
</section>
