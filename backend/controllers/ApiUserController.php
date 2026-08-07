<?php
/**
 * API endpoint for user-related AJAX operations used by the dashboard.
 *
 * Expects authenticated requests from a Super Admin. Accepts JSON POSTs or
 * form-encoded requests with an `action` parameter (list_by_company, create,
 * update, delete, assign_head). Responses are JSON objects.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/UserModel.php';

require_once __DIR__ . '/../models/DepartmentModel.php';

$profile = currentUser();
$currentRole = $profile['role'] ?? null;
$isSuperAdmin = $currentRole === 'super_admin';
$isAdmin = $currentRole === 'admin';

if (!isLoggedIn() || (!$isSuperAdmin && !$isAdmin)) {
            jsonResponse(['error' => t('common.unauthorized')], 403);
}

$pdo = getPDO();
ensureSchedulerSchema($pdo);
$userModel = new UserModel($pdo);
$departmentModel = new DepartmentModel($pdo);

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;
$action = $input['action'] ?? ($_GET['action'] ?? 'list');

$requestedCompanyId = (int) ($input['company_id'] ?? ($_GET['company_id'] ?? 0));
$profileCompanyId = (int) ($profile['company_id'] ?? 0);
$effectiveAdminCompanyId = $profileCompanyId > 0 ? $profileCompanyId : $requestedCompanyId;

$normalizeDepartmentIds = static function ($rawValue): array {
    if (is_string($rawValue)) {
        $decoded = json_decode($rawValue, true);
        $rawValue = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($rawValue)) {
        $rawValue = [];
    }

    $ids = [];
    foreach ($rawValue as $candidate) {
        $id = (int) $candidate;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
};

$resolveValidatedDepartments = static function (DepartmentModel $departmentModel, array $departmentIds): array {
    $validated = [];
    foreach ($departmentIds as $departmentId) {
        $department = $departmentModel->findById((int) $departmentId);
        if (!$department) {
            jsonResponse(['ok' => false, 'error' => 'Department not found'], 404);
        }
        $validated[] = $department;
    }
    return $validated;
};

try {
    switch ($action) {
        case 'list_by_company':
            if ($isAdmin) {
                $companyId = $effectiveAdminCompanyId;
            } else {
                $companyId = (int) ($input['company_id'] ?? ($_GET['company_id'] ?? 0));
            }
            if ($companyId <= 0) jsonResponse(['ok' => false, 'error' => 'company_id is required'], 400);
            $rows = $userModel->companyUsersByCompanyId($companyId);
            // Admins must not see super_admin accounts
            if ($isAdmin) {
                $rows = array_values(array_filter($rows, static fn($u) => ($u['role'] ?? '') !== 'super_admin'));
            }
            jsonResponse(['ok' => true, 'users' => $rows]);
            break;

        case 'create':
            $requestedPassword = (string) ($input['password'] ?? '');
            if ($requestedPassword === '') {
                jsonResponse(['ok' => false, 'error' => t('flash.password_required_create')], 400);
            }
            $passwordStrengthError = validatePasswordStrength($requestedPassword);
            if ($passwordStrengthError !== null) {
                jsonResponse(['ok' => false, 'error' => $passwordStrengthError], 400);
            }

            $departmentIds = $normalizeDepartmentIds($input['department_ids'] ?? []);
            $departmentId = $input['department_id'] ?? null;
            $userRole = trim((string) ($input['role'] ?? 'employee'));
            $assignedCompanyId = $isAdmin ? $effectiveAdminCompanyId : (int) ($input['company_id'] ?? 0);

            if (!empty($departmentIds) && ($departmentId === null || $departmentId === '')) {
                $departmentId = (int) ($departmentIds[0] ?? 0);
            }
            if ((int) $departmentId > 0 && !in_array((int) $departmentId, $departmentIds, true)) {
                array_unshift($departmentIds, (int) $departmentId);
                $departmentIds = array_values(array_unique(array_map('intval', $departmentIds)));
            }

            if ($userRole === 'super_admin') {
                if ($isAdmin) jsonResponse(['ok' => false, 'error' => 'Forbidden role'], 403);
                $departmentId = null;
                $departmentIds = [];
            } elseif ($userRole === 'admin') {
                if (($departmentId === null || $departmentId === '') && $assignedCompanyId > 0) {
                    $reception = $departmentModel->findByNameAndCompanyId('Reception', (int) $assignedCompanyId);
                    if ($reception) {
                        $departmentId = (int) $reception['id'];
                        $departmentIds = [$departmentId];
                    }
                }
            } else {
                if (($departmentId === null || $departmentId === '') && empty($departmentIds)) {
                    jsonResponse(['ok' => false, 'error' => 'department_id is required for department_manager/employee'], 400);
                }
                if (empty($departmentIds) && (int) $departmentId > 0) {
                    $departmentIds = [(int) $departmentId];
                }
                $validatedDepartments = $resolveValidatedDepartments($departmentModel, $departmentIds);
                if (empty($validatedDepartments)) {
                    jsonResponse(['ok' => false, 'error' => 'Department not found'], 404);
                }
                $departmentId = (int) ($departmentIds[0] ?? 0);
                $assignedCompanyId = (int) ($validatedDepartments[0]['company_id'] ?? $assignedCompanyId);

                foreach ($validatedDepartments as $validatedDepartment) {
                    if ((int) ($validatedDepartment['company_id'] ?? 0) !== (int) $assignedCompanyId) {
                        jsonResponse(['ok' => false, 'error' => 'All departments must belong to the same company'], 400);
                    }
                }
            }

            if ((int) $assignedCompanyId <= 0 && !empty($departmentId)) {
                $deptForCompany = $departmentModel->findById((int) $departmentId);
                $assignedCompanyId = (int) ($deptForCompany['company_id'] ?? 0);
            }

            $data = [
                'department_id' => $departmentId,
                'company_id' => $assignedCompanyId > 0 ? $assignedCompanyId : null,
                'first_name' => trim((string) ($input['first_name'] ?? '')),
                'last_name' => trim((string) ($input['last_name'] ?? '')),
                'email' => trim((string) ($input['email'] ?? '')),
                'phone' => $input['phone'] ?? null,
                'password' => password_hash($requestedPassword, PASSWORD_DEFAULT),
                'role' => $userRole,
                'status' => $input['status'] ?? 'active',
            ];
            if ($isAdmin && !empty($departmentId)) {
                $validatedDepartments = $resolveValidatedDepartments($departmentModel, !empty($departmentIds) ? $departmentIds : [(int) $departmentId]);
                foreach ($validatedDepartments as $dept) {
                    if ($effectiveAdminCompanyId > 0 && (int) ($dept['company_id'] ?? 0) !== $effectiveAdminCompanyId) {
                        jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
                    }
                }
            }
            if ($data['first_name'] === '' || $data['last_name'] === '' || $data['email'] === '') {
                jsonResponse(['ok' => false, 'error' => 'Missing required fields'], 400);
            }
            $id = $userModel->create($data);
            if (!empty($departmentIds)) {
                $userModel->setDepartmentLinks($id, $departmentIds);
            }
            $user = $userModel->findById($id);
            jsonResponse(['ok' => true, 'user' => $user]);
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) jsonResponse(['ok' => false, 'error' => 'id required'], 400);
            $departmentIds = $normalizeDepartmentIds($input['department_ids'] ?? []);
            $departmentId = $input['department_id'] ?? null;
            $userRole = trim((string) ($input['role'] ?? 'employee'));
            $assignedCompanyId = $isAdmin ? $effectiveAdminCompanyId : (int) ($input['company_id'] ?? 0);

            if (!empty($departmentIds) && ($departmentId === null || $departmentId === '')) {
                $departmentId = (int) ($departmentIds[0] ?? 0);
            }
            if ((int) $departmentId > 0 && !in_array((int) $departmentId, $departmentIds, true)) {
                array_unshift($departmentIds, (int) $departmentId);
                $departmentIds = array_values(array_unique(array_map('intval', $departmentIds)));
            }

            if ($userRole === 'super_admin') {
                if ($isAdmin) jsonResponse(['ok' => false, 'error' => 'Forbidden role'], 403);
                $departmentId = null;
                $departmentIds = [];
            } elseif ($userRole === 'admin') {
                if (($departmentId === null || $departmentId === '') && $assignedCompanyId > 0) {
                    $reception = $departmentModel->findByNameAndCompanyId('Reception', (int) $assignedCompanyId);
                    if ($reception) {
                        $departmentId = (int) $reception['id'];
                        $departmentIds = [$departmentId];
                    }
                }
            } else {
                if (($departmentId === null || $departmentId === '') && empty($departmentIds)) {
                    jsonResponse(['ok' => false, 'error' => 'department_id is required for department_manager/employee'], 400);
                }
                if (empty($departmentIds) && (int) $departmentId > 0) {
                    $departmentIds = [(int) $departmentId];
                }
                $validatedDepartments = $resolveValidatedDepartments($departmentModel, $departmentIds);
                if (empty($validatedDepartments)) {
                    jsonResponse(['ok' => false, 'error' => 'Department not found'], 404);
                }
                $departmentId = (int) ($departmentIds[0] ?? 0);
                $assignedCompanyId = (int) ($validatedDepartments[0]['company_id'] ?? $assignedCompanyId);
                foreach ($validatedDepartments as $validatedDepartment) {
                    if ((int) ($validatedDepartment['company_id'] ?? 0) !== (int) $assignedCompanyId) {
                        jsonResponse(['ok' => false, 'error' => 'All departments must belong to the same company'], 400);
                    }
                }
            }

            if ((int) $assignedCompanyId <= 0 && !empty($departmentId)) {
                $deptForCompany = $departmentModel->findById((int) $departmentId);
                $assignedCompanyId = (int) ($deptForCompany['company_id'] ?? 0);
            }
            // Prevent admins from updating super_admin accounts
            $targetUser = $userModel->findById($id);
            if ($targetUser && ($targetUser['role'] ?? '') === 'super_admin' && $isAdmin) {
                jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
            }

            $payload = [
                'department_id' => $departmentId,
                'company_id' => $assignedCompanyId > 0 ? $assignedCompanyId : null,
                'first_name' => $input['first_name'] ?? '',
                'last_name' => $input['last_name'] ?? '',
                'email' => $input['email'] ?? '',
                'phone' => $input['phone'] ?? null,
                'role' => $userRole,
                'status' => $input['status'] ?? 'active',
            ];
            if (!empty($input['password'])) {
                $passwordStrengthError = validatePasswordStrength((string) $input['password']);
                if ($passwordStrengthError !== null) {
                    jsonResponse(['ok' => false, 'error' => $passwordStrengthError], 400);
                }
                $payload['password'] = password_hash($input['password'], PASSWORD_DEFAULT);
            }
            if ($isAdmin && !empty($departmentId)) {
                $validatedDepartments = $resolveValidatedDepartments($departmentModel, !empty($departmentIds) ? $departmentIds : [(int) $departmentId]);
                foreach ($validatedDepartments as $dept) {
                    if ($effectiveAdminCompanyId > 0 && (int) ($dept['company_id'] ?? 0) !== $effectiveAdminCompanyId) {
                        jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
                    }
                }
            }
            $userModel->update($id, $payload);
            if (!empty($departmentIds)) {
                $userModel->setDepartmentLinks($id, $departmentIds);
            } elseif ($departmentId !== null && $departmentId !== '') {
                $userModel->setDepartmentLinks($id, [(int) $departmentId]);
            } else {
                $userModel->setDepartmentLinks($id, []);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'transfer_department':
            $userId = (int) ($input['user_id'] ?? 0);
            $departmentId = (int) ($input['department_id'] ?? 0);
            if ($userId <= 0 || $departmentId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'user_id and department_id are required'], 400);
            }

            $targetUser = $userModel->findById($userId);
            if (!$targetUser) {
                jsonResponse(['ok' => false, 'error' => 'User not found'], 404);
            }

            if (($targetUser['role'] ?? '') !== 'employee') {
                jsonResponse(['ok' => false, 'error' => 'Only employees can be transferred from sidebar'], 400);
            }

            $targetDepartment = $departmentModel->findById($departmentId);
            if (!$targetDepartment) {
                jsonResponse(['ok' => false, 'error' => 'Department not found'], 404);
            }

            $targetCompanyId = (int) ($targetDepartment['company_id'] ?? 0);
            $sourceDepartment = $departmentModel->findById((int) ($targetUser['department_id'] ?? 0));
            $sourceCompanyId = (int) ($sourceDepartment['company_id'] ?? 0);

            if ($isAdmin) {
                $adminCompanyId = $effectiveAdminCompanyId > 0 ? $effectiveAdminCompanyId : $targetCompanyId;
                if ($adminCompanyId <= 0) {
                    jsonResponse(['ok' => false, 'error' => 'company_id is required'], 400);
                }
                if ($targetCompanyId !== $adminCompanyId || ($sourceCompanyId > 0 && $sourceCompanyId !== $adminCompanyId)) {
                    jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
                }
            }

            if ($sourceCompanyId > 0 && $targetCompanyId > 0 && $sourceCompanyId !== $targetCompanyId) {
                jsonResponse(['ok' => false, 'error' => 'Cross-company transfer is not allowed'], 400);
            }

            $payload = [
                'department_id' => $departmentId,
                'company_id' => $targetCompanyId > 0 ? $targetCompanyId : null,
                'first_name' => (string) ($targetUser['first_name'] ?? ''),
                'last_name' => (string) ($targetUser['last_name'] ?? ''),
                'email' => (string) ($targetUser['email'] ?? ''),
                'phone' => $targetUser['phone'] ?? null,
                'role' => (string) ($targetUser['role'] ?? 'employee'),
                'status' => (string) ($targetUser['status'] ?? 'active'),
            ];

            $userModel->update($userId, $payload);
            $userModel->setDepartmentLinks($userId, [$departmentId]);

            jsonResponse([
                'ok' => true,
                'user_id' => $userId,
                'department_id' => $departmentId,
            ]);
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) jsonResponse(['ok' => false, 'error' => 'id required'], 400);
            $targetUser = $userModel->findById($id);
            if ($targetUser && ($targetUser['role'] ?? '') === 'super_admin' && $isAdmin) {
                jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            // Admins may only delete users within their company
            if ($isAdmin) {
                $adminScopeCompanyId = $effectiveAdminCompanyId;
                if ($adminScopeCompanyId <= 0) {
                    $adminScopeCompanyId = (int) ($targetUser['company_id'] ?? 0);
                }
                if ($adminScopeCompanyId <= 0) {
                    $targetDept = $departmentModel->findById((int) ($targetUser['department_id'] ?? 0));
                    $adminScopeCompanyId = (int) ($targetDept['company_id'] ?? 0);
                }
                if ($adminScopeCompanyId <= 0) {
                    jsonResponse(['ok' => false, 'error' => 'company_id is required'], 400);
                }

                $isInCompanyScope = false;
                $dept = $departmentModel->findById((int) ($targetUser['department_id'] ?? 0));
                if ($dept && (int) ($dept['company_id'] ?? 0) === $adminScopeCompanyId) {
                    $isInCompanyScope = true;
                }

                if (!$isInCompanyScope) {
                    $companyUsers = $userModel->companyUsersByCompanyId($adminScopeCompanyId);
                    foreach ($companyUsers as $companyUser) {
                        if ((int) ($companyUser['id'] ?? 0) === $id) {
                            $isInCompanyScope = true;
                            break;
                        }
                    }
                }

                if (!$isInCompanyScope) {
                    jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
                }
            }
            $userModel->delete($id);
            jsonResponse(['ok' => true]);
            break;

        case 'assign_head':
            $userId = (int) ($input['user_id'] ?? 0);
            $departmentId = (int) ($input['department_id'] ?? 0);
            if ($userId <= 0 || $departmentId <= 0) jsonResponse(['ok' => false, 'error' => t('common.user_department_required')], 400);
            // Set the user role and department assignment.
            $userModel->update($userId, ['department_id' => $departmentId, 'first_name' => $userModel->findById($userId)['first_name'], 'last_name' => $userModel->findById($userId)['last_name'], 'email' => $userModel->findById($userId)['email'], 'phone' => $userModel->findById($userId)['phone'], 'role' => 'department_manager', 'status' => 'active']);
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
