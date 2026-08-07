<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/DepartmentModel.php';

// This controller only handles modal-based CRUD submissions and returns the user to the users modal.
if (!isLoggedIn()) {
    setFlash('error', t('common.login_required'));
    redirectTo('login');
}

$currentUser = currentUser();
$role = $currentUser['role'] ?? 'employee';

if (!in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
    setFlash('error', t('common.access_denied'));
    redirectTo('dashboard');
}

$pdo = getPDO();
$userModel = new UserModel($pdo);
$departmentModel = new DepartmentModel($pdo);
$profile = $userModel->profileWithRelations((int) $currentUser['id']) ?? [];
$scopeCompanyId = isset($profile['company_id']) ? (int) $profile['company_id'] : null;
$scopeDepartmentId = isset($profile['department_id']) ? (int) $profile['department_id'] : null;
$defaultReceptionDepartment = $departmentModel->findByNameAndCompanyId('Reception', $scopeCompanyId);
$defaultReceptionDepartmentId = isset($defaultReceptionDepartment['id']) ? (int) $defaultReceptionDepartment['id'] : null;

$pageTitle = t('common.users') . ' - ' . t('common.management');

/**
 * Redirects back to the dashboard users modal after a CRUD action.
 */
function usersModalRedirect(): never
{
    redirectTo('dashboard', ['modal' => 'users']);
}
$formData = [
    'department_id' => '',
    'company_id' => '',
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'password' => '',
    'role' => 'employee',
    'status' => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $departmentId = $_POST['department_id'] !== '' ? (int) $_POST['department_id'] : null;
    $userRole = trim((string) ($_POST['role'] ?? 'employee'));

    if ($role === 'super_admin') {
        if ($userRole === 'super_admin') {
            $departmentId = null;
        } elseif ($userRole === 'admin') {
            if ($departmentId === null) {
                $departmentId = $defaultReceptionDepartmentId;
            }
        } elseif ($departmentId !== null) {
        }
    } elseif ($role === 'admin') {
        if (!in_array($userRole, ['admin', 'department_manager', 'employee'], true)) {
            $userRole = 'employee';
        }
        if ($userRole === 'admin') {
            if ($departmentId === null) {
                $departmentId = $defaultReceptionDepartmentId;
            }
        } elseif ($departmentId !== null) {
            $department = $departmentModel->findById($departmentId);
            if (isset($department['company_id']) && (int) $department['company_id'] !== $scopeCompanyId) {
                $error = 'The department must belong to your company.';
            }
        }
    } elseif ($role === 'department_manager') {
        $departmentId = $scopeDepartmentId;
        $userRole = 'employee';
    }

    $payload = [
        'department_id' => $departmentId,
        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
        'last_name' => trim((string) ($_POST['last_name'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'role' => $userRole,
        'status' => trim((string) ($_POST['status'] ?? 'active')),
    ];
    $formData = $payload + ['password' => ''];

    if ($action === 'delete') {
        if ($id > 0) {
            $userModel->delete($id);
        }
        usersModalRedirect();
    }

    if ($payload['first_name'] === '' || $payload['last_name'] === '' || $payload['email'] === '') {
        setFlash('error', t('flash.user_required_fields'));
        usersModalRedirect();
    } elseif (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        setFlash('error', t('flash.invalid_email'));
        usersModalRedirect();
    } elseif (!in_array($payload['role'], ['super_admin', 'admin', 'department_manager', 'employee'], true)) {
        setFlash('error', t('flash.invalid_role'));
        usersModalRedirect();
    } elseif (!in_array($payload['status'], ['active', 'inactive'], true)) {
        setFlash('error', t('flash.invalid_status'));
        usersModalRedirect();
    } elseif ($payload['role'] === 'super_admin' && $payload['department_id'] !== null) {
        setFlash('error', t('common.super_admin_no_department'));
        usersModalRedirect();
    } elseif ($payload['role'] === 'admin' && $payload['department_id'] === null) {
        setFlash('error', t('flash.admin_requires_department'));
        usersModalRedirect();
    } elseif (in_array($payload['role'], ['department_manager', 'employee'], true) && $payload['department_id'] === null) {
        setFlash('error', t('flash.user_requires_department'));
        usersModalRedirect();
    } else {
        $password = trim((string) ($_POST['password'] ?? ''));

        if ($action === 'create') {
            if ($password === '') {
                setFlash('error', t('flash.password_required_create'));
                usersModalRedirect();
            }
            $passwordStrengthError = validatePasswordStrength($password);
            if ($passwordStrengthError !== null) {
                setFlash('error', $passwordStrengthError);
                usersModalRedirect();
            }

            $payload['password'] = password_hash($password, PASSWORD_DEFAULT);
            $userModel->create($payload);
            usersModalRedirect();
        } elseif ($action === 'update' && $id > 0) {
            if ($password !== '') {
                $passwordStrengthError = validatePasswordStrength($password);
                if ($passwordStrengthError !== null) {
                    setFlash('error', $passwordStrengthError);
                    usersModalRedirect();
                }
                $payload['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $userModel->update($id, $payload);
            usersModalRedirect();
        } else {
            usersModalRedirect();
        }
    }
}

usersModalRedirect();
