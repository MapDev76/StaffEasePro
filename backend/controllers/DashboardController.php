<?php
/**
 * Dashboard controller
 *
 * Prepares data for the shared dashboard UI and for the modal CRUD templates.
 * The controller enforces authentication and role-based scoping and populates
 * `$dashboardSidebarSections` and the `$dashboardModal*` arrays consumed by
 * `app/layout/*` templates.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/CompanyModel.php';
require_once __DIR__ . '/../models/DepartmentModel.php';

if (!isLoggedIn()) {
    setFlash('error', t('common.login_required'));
    redirectTo('login');
}

$pdo = getPDO();
ensureSchedulerSchema($pdo);
$userModel = new UserModel($pdo);
$companyModel = new CompanyModel($pdo);
$departmentModel = new DepartmentModel($pdo);

$currentUser = currentUser();
$role = $currentUser['role'] ?? 'employee';
$profile = $userModel->profileWithRelations((int) $currentUser['id']) ?? [];

$dashboardShowOnboardingTour = false;
if (!empty($_SESSION['show_onboarding_tour'])) {
    $dashboardShowOnboardingTour = true;
    unset($_SESSION['show_onboarding_tour']);
}

$roleLabels = [
    'super_admin' => t('roles.super_admin'),
    'admin' => t('roles.admin'),
    'department_manager' => t('roles.department_manager'),
    'employee' => t('roles.employee'),
];

$dashboardSidebarRoleLabel = $roleLabels[$role] ?? t('roles.employee');

$dashboardSidebarSections = [];

$companyId = isset($profile['company_id']) ? (int) $profile['company_id'] : null;
$departmentId = isset($profile['department_id']) ? (int) $profile['department_id'] : null;

if ($role === 'admin' && (int) ($companyId ?? 0) <= 0) {
    $fallbackCompanyId = (int) ($currentUser['company_id'] ?? 0);

    if ($fallbackCompanyId <= 0 && (int) ($departmentId ?? 0) > 0) {
        $fallbackDepartment = $departmentModel->findById((int) $departmentId);
        $fallbackCompanyId = (int) ($fallbackDepartment['company_id'] ?? 0);
    }

    if ($fallbackCompanyId <= 0) {
        $companiesFallback = $companyModel->all();
        $fallbackCompanyId = (int) ($companiesFallback[0]['id'] ?? 0);
    }

    if ($fallbackCompanyId > 0) {
        $companyId = $fallbackCompanyId;
        $fallbackCompany = $companyModel->findById($fallbackCompanyId);
        if (!empty($fallbackCompany['name'])) {
            $profile['company_name'] = $fallbackCompany['name'];
        }
        if (!empty($fallbackCompany['type'])) {
            $profile['company_type'] = $fallbackCompany['type'];
        }
    }
}

$pageTitle = match ($role) {
    'super_admin' => t('common.dashboard') . ' - ' . t('roles.super_admin'),
    'admin' => t('common.dashboard') . ' - ' . t('roles.admin'),
    'department_manager' => t('common.dashboard') . ' - ' . t('roles.department_manager'),
    'employee' => t('common.dashboard') . ' - ' . t('roles.employee'),
    default => t('common.dashboard'),
};

$viewFile = __DIR__ . '/../../public/views/admin/dashboard.php';

$requestedCompanyId = (int) ($_GET['settings_company_id'] ?? 0);
$requestedView = strtolower(trim((string) ($_GET['view'] ?? '')));
$isSuperAdminDirectoryView = $role === 'super_admin' && $requestedCompanyId <= 0 && ($requestedView === '' || $requestedView === 'directory');
$isSuperAdminScopedToCompany = $role === 'super_admin' && !$isSuperAdminDirectoryView && $requestedCompanyId > 0;

$dashboardCalendarToday = date('Y-m-d');
$dashboardCalendarMode = (in_array($role, ['admin', 'department_manager'], true) || $isSuperAdminScopedToCompany) ? 'week' : 'month';
$dashboardCalendarScopeLabel = '';
$dashboardCalendarEvents = [];
$dashboardPlannerData = [
    'departments' => [],
    'users' => [],
    'shifts' => [],
    'attendances' => [],
    'company' => [
        'id' => $companyId,
        'name' => $profile['company_name'] ?? ($currentUser['company_name'] ?? ''),
        'type' => $profile['company_type'] ?? ($currentUser['company_type'] ?? ''),
        'logo_path' => null,
        'signature_ip' => null,
    ],
    'companies' => [],
    'active_department_id' => null,
    'active_shift_id' => null,
    'today' => $dashboardCalendarToday,
    'mode' => $dashboardCalendarMode,
    'assignments' => [],
    'monthly_hours_plans' => [],
];

$resolvePreferredDepartmentId = static function (array $departmentRows, string $preferredName = 'reception'): ?int {
    foreach ($departmentRows as $departmentRow) {
        $name = strtolower(trim((string) ($departmentRow['name'] ?? '')));
        if ($name === $preferredName) {
            return (int) ($departmentRow['id'] ?? 0);
        }
    }

    return isset($departmentRows[0]['id']) ? (int) $departmentRows[0]['id'] : null;
};

$resolvePreferredShiftId = static function (array $shiftRows, ?int $preferredDepartmentId = null): ?int {
    foreach ($shiftRows as $shiftRow) {
        if ($preferredDepartmentId !== null && (int) ($shiftRow['department_id'] ?? 0) !== (int) $preferredDepartmentId) {
            continue;
        }
        if (strtolower((string) ($shiftRow['kind'] ?? 'work')) === 'work') {
            return (int) ($shiftRow['id'] ?? 0);
        }
    }

    foreach ($shiftRows as $shiftRow) {
        if ($preferredDepartmentId !== null && (int) ($shiftRow['department_id'] ?? 0) !== (int) $preferredDepartmentId) {
            continue;
        }
        return (int) ($shiftRow['id'] ?? 0);
    }

    return isset($shiftRows[0]['id']) ? (int) $shiftRows[0]['id'] : null;
};

$plannerCompanyId = null;
if ($role === 'super_admin') {
    $allCompanies = $companyModel->all();
    $dashboardPlannerData['companies'] = $allCompanies;
    $modalCompanies = $allCompanies;
} else {
    $allCompanies = [];
}

if ($role === 'super_admin') {
    if (!empty($allCompanies) && !$isSuperAdminDirectoryView) {
        if ($requestedCompanyId > 0) {
            foreach ($allCompanies as $companyRow) {
                if ((int) ($companyRow['id'] ?? 0) === $requestedCompanyId) {
                    $plannerCompanyId = $requestedCompanyId;
                    break;
                }
            }
        }
        if ($plannerCompanyId === null) {
            $plannerCompanyId = (int) ($allCompanies[0]['id'] ?? 0);
        }
    }

    if (!$isSuperAdminDirectoryView && $requestedCompanyId <= 0 && $plannerCompanyId !== null && $plannerCompanyId > 0) {
        redirectTo('dashboard', ['settings_company_id' => $plannerCompanyId]);
    }

    if ($plannerCompanyId !== null && $plannerCompanyId > 0) {
        $companyName = '';
        $companyType = '';
        $companyLogoPath = null;
        $companySignatureIp = null;
        foreach ($allCompanies as $companyRow) {
            if ((int) ($companyRow['id'] ?? 0) === $plannerCompanyId) {
                $companyName = (string) ($companyRow['name'] ?? '');
                $companyType = (string) ($companyRow['type'] ?? '');
                $companyLogoPath = $companyRow['logo_path'] ?? null;
                $companySignatureIp = $companyRow['signature_ip'] ?? null;
                break;
            }
        }

        $departmentRows = $departmentModel->byCompanyId($plannerCompanyId);
        $userRows = $userModel->companyUsersByCompanyId($plannerCompanyId);
        $departmentIds = array_values(array_map(static fn (array $department): int => (int) $department['id'], $departmentRows));
        $shiftRows = [];
        if (!empty($departmentIds)) {
            $shiftSelect = 's.id, s.department_id, s.name, s.icon, s.color, s.description, s.kind, s.start_time, s.end_time, d.name AS department_name';
            $placeholders = implode(', ', array_fill(0, count($departmentIds), '?'));
            $shiftStatement = $pdo->prepare(
                'SELECT ' . $shiftSelect . ' FROM shifts s INNER JOIN departments d ON d.id = s.department_id WHERE s.department_id IN (' . $placeholders . ') ORDER BY s.department_id ASC, s.start_time ASC, s.id ASC'
            );
            $shiftStatement->execute($departmentIds);
            $shiftRows = $shiftStatement->fetchAll();
        }

        $groupedUsers = [];
        foreach ($userRows as $userRow) {
            $departmentIds = $userRow['department_ids'] ?? [];
            if (!is_array($departmentIds) || empty($departmentIds)) {
                $fallbackDepartmentId = (int) ($userRow['department_id'] ?? 0);
                $departmentIds = $fallbackDepartmentId > 0 ? [$fallbackDepartmentId] : [];
            }

            foreach ($departmentIds as $departmentIdValue) {
                $deptKey = (int) $departmentIdValue;
                if ($deptKey <= 0) {
                    continue;
                }
                if (!isset($groupedUsers[$deptKey])) {
                    $groupedUsers[$deptKey] = [];
                }
                $groupedUsers[$deptKey][] = $userRow;
            }
        }

        $groupedShifts = [];
        foreach ($shiftRows as $shiftRow) {
            $deptKey = (int) ($shiftRow['department_id'] ?? 0);
            if (!isset($groupedShifts[$deptKey])) {
                $groupedShifts[$deptKey] = [];
            }
            $groupedShifts[$deptKey][] = $shiftRow;
        }

        foreach ($departmentRows as $departmentRow) {
            $deptId = (int) $departmentRow['id'];
            $dashboardPlannerData['departments'][] = [
                'id' => $deptId,
                'company_id' => (int) ($departmentRow['company_id'] ?? $plannerCompanyId),
                'name' => $departmentRow['name'] ?? '',
                'icon' => $departmentRow['icon'] ?? null,
                'color' => $departmentRow['color'] ?? null,
                'description' => $departmentRow['description'] ?? '',
                'head_user_id' => (int) ($departmentRow['head_user_id'] ?? 0),
                'head_user_name' => $departmentRow['head_user_name'] ?? '',
                'users' => $groupedUsers[$deptId] ?? [],
                'shifts' => $groupedShifts[$deptId] ?? [],
            ];
        }

        $dashboardPlannerData['users'] = $userRows;
        $dashboardPlannerData['shifts'] = $shiftRows;
        $dashboardPlannerData['company'] = [
            'id' => $plannerCompanyId,
            'name' => $companyName,
            'type' => $companyType,
            'logo_path' => $companyLogoPath,
            'signature_ip' => $companySignatureIp,
        ];
        $dashboardCalendarScopeLabel = trim(($companyName !== '' ? $companyName : t('common.company', ['fallback' => 'Company'])) . ' ' . t('common.calendar', ['fallback' => 'Calendar']));
        $dashboardPlannerData['active_department_id'] = $departmentRows[0]['id'] ?? null;
        $dashboardPlannerData['active_shift_id'] = $resolvePreferredShiftId($shiftRows, isset($departmentRows[0]['id']) ? (int) $departmentRows[0]['id'] : null);

        $calendarStatement = $pdo->prepare(
            'SELECT us.id AS assignment_id,
                    us.work_date,
                    us.status,
                    us.notes,
                    s.id AS shift_id,
                    s.name AS shift_name,
                    s.icon AS shift_icon,
                    s.color AS shift_color,
                          s.description AS shift_description,
                          s.kind AS shift_kind,
                    s.start_time,
                    s.end_time,
                    d.id AS department_id,
                    d.name AS department_name,
                          d.color AS department_color,
                    u.id AS user_id,
                          CONCAT(u.first_name, " ", u.last_name) AS user_name,
                          CASE WHEN us.user_id IS NULL THEN "open" ELSE "assigned" END AS assignment_source
             FROM user_shifts us
             INNER JOIN shifts s ON s.id = us.shift_id
             INNER JOIN departments d ON d.id = s.department_id
             LEFT JOIN users u ON u.id = us.user_id
             WHERE d.company_id = :company_id
             ORDER BY us.work_date ASC, s.start_time ASC, us.id ASC'
        );
        $calendarStatement->execute(['company_id' => $plannerCompanyId]);
        $dashboardPlannerData['assignments'] = $calendarStatement->fetchAll();

        $attendanceStatement = $pdo->prepare(
            'SELECT a.id,
                    a.user_id,
                    a.user_shift_id,
                    a.digital_signature_id,
                    a.work_date,
                    a.check_in_time,
                    a.check_out_time,
                    a.status,
                    CONCAT(u.first_name, " ", u.last_name) AS user_name,
                    us.shift_id,
                    s.name AS shift_name,
                    s.start_time AS shift_start_time,
                    s.end_time AS shift_end_time,
                    d.id AS department_id,
                    d.name AS department_name,
                    ds.signature_date,
                    ds.signature_data
             FROM attendances a
             INNER JOIN users u ON u.id = a.user_id
             LEFT JOIN user_shifts us ON us.id = a.user_shift_id
             LEFT JOIN shifts s ON s.id = us.shift_id
             LEFT JOIN departments d ON d.id = s.department_id
             LEFT JOIN digital_signatures ds ON ds.id = a.digital_signature_id
             WHERE d.company_id = :company_id
             ORDER BY a.work_date DESC, a.id DESC'
        );
        $attendanceStatement->execute(['company_id' => $plannerCompanyId]);
        $dashboardPlannerData['attendances'] = $attendanceStatement->fetchAll();
    }
}

if ($role === 'admin' && $companyId !== null) {
    $dashboardCalendarScopeLabel = trim((string) (($profile['company_name'] ?? 'Company') . ' calendar'));
    $departmentRows = $departmentModel->byCompanyId($companyId);
    $userRows = $userModel->companyUsersByCompanyId($companyId);
    $departmentIds = array_values(array_map(static fn (array $department): int => (int) $department['id'], $departmentRows));
    $shiftRows = [];
    if (!empty($departmentIds)) {
        $shiftSelect = 's.id, s.department_id, s.name, s.icon, s.color, s.description, s.kind, s.start_time, s.end_time, d.name AS department_name';
        $placeholders = implode(', ', array_fill(0, count($departmentIds), '?'));
        $shiftStatement = $pdo->prepare(
            'SELECT ' . $shiftSelect . ' FROM shifts s INNER JOIN departments d ON d.id = s.department_id WHERE s.department_id IN (' . $placeholders . ') ORDER BY s.department_id ASC, s.start_time ASC, s.id ASC'
        );
        $shiftStatement->execute($departmentIds);
        $shiftRows = $shiftStatement->fetchAll();
    }

    $groupedUsers = [];
    foreach ($userRows as $userRow) {
        $departmentIds = $userRow['department_ids'] ?? [];
        if (!is_array($departmentIds) || empty($departmentIds)) {
            $fallbackDepartmentId = (int) ($userRow['department_id'] ?? 0);
            $departmentIds = $fallbackDepartmentId > 0 ? [$fallbackDepartmentId] : [];
        }

        foreach ($departmentIds as $departmentIdValue) {
            $deptKey = (int) $departmentIdValue;
            if ($deptKey <= 0) {
                continue;
            }
            if (!isset($groupedUsers[$deptKey])) {
                $groupedUsers[$deptKey] = [];
            }
            $groupedUsers[$deptKey][] = $userRow;
        }
    }

    $groupedShifts = [];
    foreach ($shiftRows as $shiftRow) {
        $deptKey = (int) ($shiftRow['department_id'] ?? 0);
        if (!isset($groupedShifts[$deptKey])) {
            $groupedShifts[$deptKey] = [];
        }
        $groupedShifts[$deptKey][] = $shiftRow;
    }

    foreach ($departmentRows as $departmentRow) {
        $deptId = (int) $departmentRow['id'];
        $dashboardPlannerData['departments'][] = [
            'id' => $deptId,
            'company_id' => (int) ($departmentRow['company_id'] ?? $companyId),
            'name' => $departmentRow['name'] ?? '',
            'icon' => $departmentRow['icon'] ?? null,
            'color' => $departmentRow['color'] ?? null,
            'description' => $departmentRow['description'] ?? '',
            'head_user_id' => (int) ($departmentRow['head_user_id'] ?? 0),
            'head_user_name' => $departmentRow['head_user_name'] ?? '',
            'users' => $groupedUsers[$deptId] ?? [],
            'shifts' => $groupedShifts[$deptId] ?? [],
        ];
    }

    $dashboardPlannerData['users'] = $userRows;
    $dashboardPlannerData['shifts'] = $shiftRows;
    $adminCompany = $companyModel->findById((int) $companyId);
    $dashboardPlannerData['company'] = [
        'id' => (int) $companyId,
        'name' => (string) ($adminCompany['name'] ?? ($profile['company_name'] ?? '')),
        'type' => (string) ($adminCompany['type'] ?? ($profile['company_type'] ?? '')),
        'logo_path' => $adminCompany['logo_path'] ?? null,
        'signature_ip' => $adminCompany['signature_ip'] ?? null,
    ];
    $dashboardPlannerData['companies'] = array_values(array_filter(
        $companyModel->all(),
        static fn (array $company): bool => (int) ($company['id'] ?? 0) === $companyId
    ));
    $adminActiveDepartmentId = $resolvePreferredDepartmentId($departmentRows, 'reception');
    $dashboardPlannerData['active_department_id'] = $adminActiveDepartmentId;
    $dashboardPlannerData['active_shift_id'] = $resolvePreferredShiftId($shiftRows, $adminActiveDepartmentId !== null ? (int) $adminActiveDepartmentId : null);
    $calendarStatement = $pdo->prepare(
        'SELECT us.id AS assignment_id,
                us.work_date,
                us.status,
                us.notes,
                s.id AS shift_id,
                s.name AS shift_name,
                s.icon AS shift_icon,
                s.color AS shift_color,
                  s.description AS shift_description,
                  s.kind AS shift_kind,
                s.start_time,
                s.end_time,
                d.id AS department_id,
                d.name AS department_name,
                  d.color AS department_color,
                u.id AS user_id,
                  CONCAT(u.first_name, " ", u.last_name) AS user_name,
                  CASE WHEN us.user_id IS NULL THEN "open" ELSE "assigned" END AS assignment_source
         FROM user_shifts us
         INNER JOIN shifts s ON s.id = us.shift_id
         INNER JOIN departments d ON d.id = s.department_id
         LEFT JOIN users u ON u.id = us.user_id
         WHERE d.company_id = :company_id
         ORDER BY us.work_date ASC, s.start_time ASC, us.id ASC'
    );
    $calendarStatement->execute(['company_id' => $companyId]);
    $dashboardCalendarEvents = $calendarStatement->fetchAll();
    $dashboardPlannerData['assignments'] = $dashboardCalendarEvents;

    $attendanceStatement = $pdo->prepare(
        'SELECT a.id,
                a.user_id,
                a.user_shift_id,
                a.digital_signature_id,
                a.work_date,
                a.check_in_time,
                a.check_out_time,
                a.status,
                CONCAT(u.first_name, " ", u.last_name) AS user_name,
                us.shift_id,
                s.name AS shift_name,
                s.start_time AS shift_start_time,
                s.end_time AS shift_end_time,
                d.id AS department_id,
                d.name AS department_name,
                ds.signature_date,
                ds.signature_data
         FROM attendances a
         INNER JOIN users u ON u.id = a.user_id
         LEFT JOIN user_shifts us ON us.id = a.user_shift_id
         LEFT JOIN shifts s ON s.id = us.shift_id
         LEFT JOIN departments d ON d.id = s.department_id
         LEFT JOIN digital_signatures ds ON ds.id = a.digital_signature_id
         WHERE d.company_id = :company_id
         ORDER BY a.work_date DESC, a.id DESC'
    );
    $attendanceStatement->execute(['company_id' => $companyId]);
    $dashboardPlannerData['attendances'] = $attendanceStatement->fetchAll();
}

if ($role === 'department_manager' && $departmentId !== null) {
    $dashboardCalendarScopeLabel = trim((string) (($profile['department_name'] ?? 'Department') . ' calendar'));
    $departmentRows = [];
    if ((int) $departmentId > 0) {
        $companyDepartmentRows = $departmentModel->byCompanyId((int) ($companyId ?? 0));
        foreach ($companyDepartmentRows as $departmentRow) {
            if ((int) ($departmentRow['id'] ?? 0) === (int) $departmentId) {
                $departmentRows[] = $departmentRow;
                break;
            }
        }

        if (empty($departmentRows)) {
            $fallbackDepartment = $departmentModel->findById((int) $departmentId);
            if ($fallbackDepartment) {
                $departmentRows[] = [
                    'id' => (int) ($fallbackDepartment['id'] ?? $departmentId),
                    'company_id' => (int) ($fallbackDepartment['company_id'] ?? ($companyId ?? 0)),
                    'name' => (string) ($fallbackDepartment['name'] ?? ''),
                    'icon' => $fallbackDepartment['icon'] ?? null,
                    'color' => $fallbackDepartment['color'] ?? null,
                    'description' => (string) ($fallbackDepartment['description'] ?? ''),
                    'head_user_id' => (int) ($fallbackDepartment['head_user_id'] ?? 0),
                    'head_user_name' => '',
                ];
            }
        }
    }

    $teamRows = $userModel->teamByDepartmentId($departmentId);
    $shiftSelect = 's.id, s.department_id, s.name, s.icon, s.color, s.description, s.kind, s.start_time, s.end_time, d.name AS department_name';

    $shiftStatement = $pdo->prepare(
        'SELECT ' . $shiftSelect . ' FROM shifts s INNER JOIN departments d ON d.id = s.department_id WHERE s.department_id = :department_id ORDER BY s.start_time ASC, s.id ASC'
    );
    $shiftStatement->execute(['department_id' => $departmentId]);
    $shiftRows = $shiftStatement->fetchAll();
    $teamGrouped = [$departmentId => $teamRows];
    $shiftGrouped = [$departmentId => $shiftRows];
    $dashboardPlannerData['departments'] = array_map(
        static function (array $departmentRow) use ($teamGrouped, $shiftGrouped): array {
            $deptId = (int) $departmentRow['id'];

            return [
                'id' => $deptId,
                'company_id' => (int) ($departmentRow['company_id'] ?? 0),
                'name' => $departmentRow['name'] ?? '',
                'icon' => $departmentRow['icon'] ?? null,
                'color' => $departmentRow['color'] ?? null,
                'description' => $departmentRow['description'] ?? '',
                'head_user_id' => (int) ($departmentRow['head_user_id'] ?? 0),
                'head_user_name' => $departmentRow['head_user_name'] ?? '',
                'users' => $teamGrouped[$deptId] ?? [],
                'shifts' => $shiftGrouped[$deptId] ?? [],
            ];
        },
        $departmentRows
    );
    $dashboardPlannerData['users'] = $teamRows;
    $dashboardPlannerData['shifts'] = $shiftRows;
    $dashboardPlannerData['companies'] = array_values(array_filter(
        $companyModel->all(),
        static fn (array $company): bool => (int) ($company['id'] ?? 0) === (int) ($companyId ?? 0)
    ));
    $dashboardPlannerData['active_department_id'] = $departmentId;
    $dashboardPlannerData['active_shift_id'] = $resolvePreferredShiftId($shiftRows, (int) $departmentId);
    $calendarStatement = $pdo->prepare(
        'SELECT us.id AS assignment_id,
                us.work_date,
                us.status,
                us.notes,
                s.id AS shift_id,
                s.name AS shift_name,
                s.icon AS shift_icon,
                s.color AS shift_color,
                  s.description AS shift_description,
                  s.kind AS shift_kind,
                s.start_time,
                s.end_time,
                d.id AS department_id,
                d.name AS department_name,
                  d.color AS department_color,
                u.id AS user_id,
                  CONCAT(u.first_name, " ", u.last_name) AS user_name,
                  CASE WHEN us.user_id IS NULL THEN "open" ELSE "assigned" END AS assignment_source
         FROM user_shifts us
         INNER JOIN shifts s ON s.id = us.shift_id
         INNER JOIN departments d ON d.id = s.department_id
         LEFT JOIN users u ON u.id = us.user_id
         WHERE d.id = :department_id
         ORDER BY us.work_date ASC, s.start_time ASC, us.id ASC'
    );
    $calendarStatement->execute(['department_id' => $departmentId]);
    $dashboardCalendarEvents = $calendarStatement->fetchAll();
    $dashboardPlannerData['assignments'] = $dashboardCalendarEvents;

    $attendanceStatement = $pdo->prepare(
        'SELECT a.id,
                a.user_id,
                a.user_shift_id,
                a.digital_signature_id,
                a.work_date,
                a.check_in_time,
                a.check_out_time,
                a.status,
                CONCAT(u.first_name, " ", u.last_name) AS user_name,
                us.shift_id,
                s.name AS shift_name,
                s.start_time AS shift_start_time,
                s.end_time AS shift_end_time,
                d.id AS department_id,
                d.name AS department_name,
                ds.signature_date,
                ds.signature_data
         FROM attendances a
         INNER JOIN users u ON u.id = a.user_id
         LEFT JOIN user_shifts us ON us.id = a.user_shift_id
         LEFT JOIN shifts s ON s.id = us.shift_id
         LEFT JOIN departments d ON d.id = s.department_id
         LEFT JOIN digital_signatures ds ON ds.id = a.digital_signature_id
         WHERE d.id = :department_id
         ORDER BY a.work_date DESC, a.id DESC'
    );
    $attendanceStatement->execute(['department_id' => $departmentId]);
    $dashboardPlannerData['attendances'] = $attendanceStatement->fetchAll();
}

if (($dashboardPlannerData['company']['logo_path'] ?? null) === null) {
    $fallbackCompanyId = (int) ($dashboardPlannerData['company']['id'] ?? 0);
    if ($fallbackCompanyId > 0) {
        $fallbackCompany = $companyModel->findById($fallbackCompanyId);
        if ($fallbackCompany) {
            $dashboardPlannerData['company']['logo_path'] = $fallbackCompany['logo_path'] ?? null;
        }
    }
}

$plannerUserIds = array_values(array_unique(array_filter(array_map(
    static fn (array $userRow): int => (int) ($userRow['id'] ?? 0),
    is_array($dashboardPlannerData['users'] ?? null) ? $dashboardPlannerData['users'] : []
), static fn (int $id): bool => $id > 0)));
if (!empty($plannerUserIds)) {
    $userPlaceholders = implode(', ', array_fill(0, count($plannerUserIds), '?'));
    $planStatement = $pdo->prepare(
        'SELECT id,
                user_id,
                month_key,
                planned_hours,
                worked_hours_override,
                note,
                updated_by_user_id,
                updated_at
         FROM user_month_hours_plans
         WHERE user_id IN (' . $userPlaceholders . ')
         ORDER BY month_key DESC, user_id ASC'
    );
    $planStatement->execute($plannerUserIds);
    $dashboardPlannerData['monthly_hours_plans'] = $planStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$stats = [
    'users' => $userModel->count(),
    'companies' => $companyModel->count(),
    'departments' => $departmentModel->count(),
];

$moduleRows = [
    'company_directory' => [],
    'company_departments' => [],
    'company_requests' => [],
    'company_users' => [],
    'company_directory_users' => [],
    'company_directory_departments' => [],
    'departments' => [],
    'documents' => [],
    'team' => [],
    'notifications' => [],
    'shifts' => [],
    'requests' => [],
    'attendances' => [],
];

$modalCompanies = [];
$modalUsers = [];
$modalDepartments = [];
$modalDocuments = [];
$modalDocumentRecipients = [];
$modalDocumentRequests = [];
$modalOpenShiftChoices = [];
$recentConnections = [];


if ($role === 'super_admin') {
    try {
        $activeConnectionsStmt = $pdo->query(
            'SELECT COUNT(DISTINCT uc.user_id)
             FROM user_connections uc
             INNER JOIN users u ON u.id = uc.user_id
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE uc.logged_out_at IS NULL
               AND uc.last_seen_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stats['connections'] = (int) ($activeConnectionsStmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $stats['connections'] = 0;
    }

    $moduleRows['company_directory'] = $companyModel->directoryWithAdminsAndDepartments();
    $moduleRows['departments'] = $departmentModel->allWithCompany();
    try {
        $recentConnectionsStmt = $pdo->query(
            'SELECT uc.id AS connection_id,
                    uc.user_id,
                    uc.session_id,
                    uc.ip_address,
                    uc.user_agent,
                    uc.logged_in_at,
                    uc.last_seen_at,
                    uc.logged_out_at,
                    CONCAT(u.first_name, " ", u.last_name) AS user_name,
                    u.role,
                    c.id AS company_id,
                    c.name AS company_name,
                    d.name AS department_name
             FROM user_connections uc
             INNER JOIN users u ON u.id = uc.user_id
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN companies c ON c.id = d.company_id
             ORDER BY COALESCE(uc.logged_out_at, uc.last_seen_at, uc.logged_in_at) DESC, uc.id DESC
             LIMIT 12'
        );
        $recentConnections = $recentConnectionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $recentConnections = [];
    }
    $modalUsers = $userModel->allWithRelations();
    $modalDepartments = $departmentModel->allWithCompany();
    $modalDocuments = $pdo->query(
        'SELECT d.id, d.user_id, d.document_type, d.file_name, d.file_path, d.file_blob, d.file_mime_type, d.status, d.upload_date,
            d.signed_at, d.signed_by_user_id, d.signed_page,
                u.first_name, u.last_name, dep.company_id, dep.name AS department_name
         FROM documents d
         INNER JOIN users u ON u.id = d.user_id
         LEFT JOIN departments dep ON dep.id = u.department_id
         ORDER BY d.upload_date DESC, d.id DESC'
    )->fetchAll();
}

if ($role === 'employee') {
    $moduleRows['shifts'] = $userModel->employeeShifts((int) $currentUser['id']);
}

$moduleRows['requests'] = $userModel->employeeRequests((int) $currentUser['id']);

if ($role === 'department_manager' && $departmentId !== null) {
    $moduleRows['team'] = $userModel->teamByDepartmentId($departmentId);
}

if ($role === 'admin' && $companyId !== null) {
    $moduleRows['company_users'] = $userModel->companyUsersByCompanyId($companyId);
    $moduleRows['company_departments'] = $departmentModel->byCompanyId($companyId);
    $moduleRows['company_requests'] = $userModel->companyRequestsByCompanyId($companyId);
    $moduleRows['departments'] = $departmentModel->byCompanyId($companyId);
    $modalCompanies = array_values(array_filter(
        $companyModel->all(),
        static fn (array $company): bool => (int) $company['id'] === $companyId
    ));
    $modalUsers = $userModel->companyUsersByCompanyId($companyId);
    $modalDepartments = $departmentModel->byCompanyId($companyId);
    $modalDocuments = $pdo->prepare(
        'SELECT d.id, d.user_id, d.document_type, d.file_name, d.file_path, d.file_blob, d.file_mime_type, d.status, d.upload_date,
            d.signed_at, d.signed_by_user_id, d.signed_page,
                u.first_name, u.last_name, dep.company_id, dep.name AS department_name
         FROM documents d
         INNER JOIN users u ON u.id = d.user_id
         LEFT JOIN departments dep ON dep.id = u.department_id
         WHERE dep.company_id = :company_id
         ORDER BY d.upload_date DESC, d.id DESC'
    );
    $modalDocuments->execute(['company_id' => $companyId]);
    $modalDocuments = $modalDocuments->fetchAll();
}

if ($role === 'department_manager' && $departmentId !== null) {
    $modalUsers = $companyId !== null
        ? $userModel->companyUsersByCompanyId((int) $companyId)
        : $userModel->teamByDepartmentId($departmentId);
    $modalDepartments = [];
    $companyDepartmentRows = $departmentModel->byCompanyId($companyId ?? 0);
    foreach ($companyDepartmentRows as $departmentRow) {
        if ((int) ($departmentRow['id'] ?? 0) === (int) $departmentId) {
            $modalDepartments[] = $departmentRow;
            break;
        }
    }
    $modalDocuments = $pdo->prepare(
        'SELECT d.id, d.user_id, d.document_type, d.file_name, d.file_path, d.file_blob, d.file_mime_type, d.status, d.upload_date,
            d.signed_at, d.signed_by_user_id, d.signed_page,
                u.first_name, u.last_name, dep.company_id, dep.name AS department_name
         FROM documents d
         INNER JOIN users u ON u.id = d.user_id
         LEFT JOIN departments dep ON dep.id = u.department_id
         WHERE u.department_id = :department_id
         ORDER BY d.upload_date DESC, d.id DESC'
    );
    $modalDocuments->execute(['department_id' => $departmentId]);
    $modalDocuments = $modalDocuments->fetchAll();
}

if ($role === 'employee') {
    $modalDocuments = $pdo->prepare(
        'SELECT d.id, d.user_id, d.document_type, d.file_name, d.file_path, d.file_blob, d.file_mime_type, d.status, d.upload_date,
            d.signed_at, d.signed_by_user_id, d.signed_page,
                u.first_name, u.last_name, dep.company_id, dep.name AS department_name
         FROM documents d
         INNER JOIN users u ON u.id = d.user_id
         LEFT JOIN departments dep ON dep.id = u.department_id
         WHERE u.id = :user_id
         ORDER BY d.upload_date DESC, d.id DESC'
    );
    $modalDocuments->execute(['user_id' => $currentUser['id']]);
    $modalDocuments = $modalDocuments->fetchAll();
}

if (in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
    $recipientSql =
        'SELECT u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.role,
                u.status,
                u.department_id,
                d.name AS department_name,
                d.company_id
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.status = "active"
           AND u.id <> :current_user_id';
    $recipientParams = [
        'current_user_id' => (int) ($currentUser['id'] ?? 0),
    ];

    if ($role === 'admin') {
        $recipientSql .= ' AND ((d.company_id = :company_id AND u.role IN ("employee", "department_manager", "admin")) OR u.role = "super_admin")';
        $recipientParams['company_id'] = (int) ($companyId ?? 0);
    } elseif ($role === 'department_manager') {
        $recipientSql .= ' AND ((d.company_id = :company_id AND u.role IN ("employee", "department_manager", "admin")) OR u.role = "super_admin")';
        $recipientParams['company_id'] = (int) ($companyId ?? 0);
    }

    $recipientSql .= ' ORDER BY u.first_name ASC, u.last_name ASC';

    try {
        $recipientStmt = $pdo->prepare($recipientSql);
        $recipientStmt->execute($recipientParams);
        $modalDocumentRecipients = $recipientStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $modalDocumentRecipients = [];
    }

    $requestSql =
        'SELECT r.id,
                r.type,
                r.title,
                r.status,
                r.document_id,
                  r.shift_id,
                CONCAT(sender.first_name, " ", sender.last_name) AS sender_name,
                CONCAT(recipient.first_name, " ", recipient.last_name) AS recipient_name,
                  s.name AS shift_name,
                sender_dep.company_id AS sender_company_id,
                recipient_dep.company_id AS recipient_company_id
         FROM requests r
         INNER JOIN users sender ON sender.id = r.user_id
         LEFT JOIN users recipient ON recipient.id = r.recipient_id
              LEFT JOIN shifts s ON s.id = r.shift_id
         LEFT JOIN departments sender_dep ON sender_dep.id = sender.department_id
         LEFT JOIN departments recipient_dep ON recipient_dep.id = recipient.department_id
              WHERE r.document_id IS NOT NULL OR r.type = "shift_coverage"';
    $requestParams = [];

    if ($role === 'admin' || $role === 'department_manager') {
        $requestSql .= ' AND (sender_dep.company_id = :company_id_sender OR recipient_dep.company_id = :company_id_recipient)';
        $requestParams['company_id_sender'] = (int) ($companyId ?? 0);
        $requestParams['company_id_recipient'] = (int) ($companyId ?? 0);
    }

    $requestSql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT 120';
    $requestStmt = $pdo->prepare($requestSql);
    $requestStmt->execute($requestParams);
    $modalDocumentRequests = $requestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($role === 'super_admin') {
        $shiftStmt = $pdo->query(
            'SELECT s.id, s.name, s.start_time, s.end_time, d.name AS department_name
             FROM shifts s
             INNER JOIN departments d ON d.id = s.department_id
             WHERE s.kind IN ("work", "overtime")
             ORDER BY d.name ASC, s.name ASC, s.start_time ASC
             LIMIT 200'
        );
        $modalOpenShiftChoices = $shiftStmt ? ($shiftStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } elseif ((int) ($companyId ?? 0) > 0) {
        $shiftStmt = $pdo->prepare(
            'SELECT s.id, s.name, s.start_time, s.end_time, d.name AS department_name
             FROM shifts s
             INNER JOIN departments d ON d.id = s.department_id
             WHERE d.company_id = :company_id
               AND s.kind IN ("work", "overtime")
             ORDER BY d.name ASC, s.name ASC, s.start_time ASC
             LIMIT 200'
        );
        $shiftStmt->execute(['company_id' => (int) $companyId]);
        $modalOpenShiftChoices = $shiftStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$dashboardModalCompanies = $modalCompanies;
$dashboardModalUsers = $modalUsers;
$dashboardModalDepartments = $modalDepartments;
$dashboardModalDocuments = $modalDocuments;
$dashboardModalDocumentRecipients = $modalDocumentRecipients;
$dashboardModalDocumentRequests = $modalDocumentRequests;
$dashboardModalOpenShiftChoices = $modalOpenShiftChoices;
$moduleRows['recent_connections'] = $recentConnections;
$moduleRows['notifications'] = $userModel->userNotifications((int) $currentUser['id']);

// Sign-up requests waiting for a decision, shown on the super admin directory
// dashboard alongside the one-click links already sent by email.
$dashboardPendingApprovals = $isSuperAdminDirectoryView ? pendingCompanyApprovals($pdo) : [];

$dashboardOnboardingSteps = [];
if (in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
    $dashboardOnboardingSteps = [
        [
            'selector' => null,
            'title' => t('onboarding.welcome_title'),
            'body' => t('onboarding.welcome_body'),
        ],
        [
            'selector' => '[data-modal-target="modal-settings"][data-modal-entity="settings"]',
            'title' => t('onboarding.step_settings_title'),
            'body' => t('onboarding.step_settings_body'),
        ],
        [
            'selector' => '[data-dept-create-row]',
            'openSettingsTab' => 'departments',
            'title' => t('onboarding.step_departments_title'),
            'body' => t('onboarding.step_departments_body'),
        ],
        [
            'selector' => '[data-user-create-row]',
            'openSettingsTab' => 'users',
            'title' => t('onboarding.step_users_title'),
            'body' => t('onboarding.step_users_body'),
        ],
        [
            'selector' => '[data-shift-create-row]',
            'openSettingsTab' => 'shifts',
            'title' => t('onboarding.step_shifts_title'),
            'body' => t('onboarding.step_shifts_body'),
        ],
        [
            'selector' => '[data-dashboard-calendar-shell]',
            'closeSettings' => true,
            'title' => t('onboarding.step_calendar_title'),
            'body' => t('onboarding.step_calendar_body'),
        ],
        [
            'selector' => '[data-sidebar-department-toggle]',
            'title' => t('onboarding.step_reception_title'),
            'body' => t('onboarding.step_reception_body'),
        ],
        [
            'selector' => '[data-onboarding-tour-relaunch]',
            'title' => t('onboarding.step_replay_title'),
            'body' => t('onboarding.step_replay_body'),
        ],
    ];
}
