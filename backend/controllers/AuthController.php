<?php
/**
 * Authentication controller: login and logout handling.
 *
 * Presents the login view and processes POSTed credentials. Successful login
 * initializes the session `auth_user` payload used across the app.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/UserModel.php';

$pageTitle = t('auth.page_title');
$viewFile = __DIR__ . '/../../public/views/auth/login.php';
$loginEmail = trim($_POST['email'] ?? '');
$loginError = null;
$userModel = null;

try {
    $userModel = new UserModel(getPDO());
} catch (Throwable $exception) {
    $loginError = t('auth.db_unavailable');
}

if (($_GET['route'] ?? 'login') === 'logout') {
    $logoutUser = currentUser();

    try {
        $logoutPdo = getPDO();
        recordCurrentUserLogout($logoutPdo, $logoutUser);
    } catch (Throwable $exception) {
        // Ignore logout tracking failures.
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
    session_start();
    setFlash('success', t('auth.logout_success'));
    redirectTo('login');
}

if (isLoggedIn()) {
    if ((currentUser()['role'] ?? '') === 'super_admin') {
        redirectTo('dashboard');
    }

    redirectTo('dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');

    if ($userModel === null) {
        $loginError = t('auth.db_unavailable');
    } elseif ($loginEmail === '' || $password === '') {
        $loginError = t('auth.empty_fields');
    } else {
        $user = $userModel->findByEmail($loginEmail);

        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password'])) {
            $loginError = t('auth.invalid_credentials');
        } else {
            session_regenerate_id(true);

            $_SESSION['auth_user'] = [
                'id' => (int) $user['id'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'department_id' => $user['department_id'],
            ];

            $roleLabel = t('roles.' . (string) ($user['role'] ?? 'employee'));
            setFlash('success', t('auth.login_success', ['role' => $roleLabel]));
            try {
                recordUserConnectionLogin(getPDO(), $user);
            } catch (Throwable $exception) {
                // Ignore login tracking failures.
            }
            if (($user['role'] ?? '') === 'employee') {
                redirectTo('my-space');
            }
            redirectTo('dashboard');
        }
    }
}
