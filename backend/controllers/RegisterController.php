<?php
/**
 * Self-service registration controller.
 *
 * Lets an anonymous visitor create a brand-new company plus its first admin
 * user in a single transaction, then logs them in and flags the dashboard to
 * show the first-run onboarding tour. No email verification (no mail
 * infrastructure exists in this app) - protected only by a honeypot field,
 * a server-side math captcha, and a per-IP/session throttle.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/CompanyModel.php';
require_once __DIR__ . '/../models/DepartmentModel.php';

$pageTitle = t('signup.page_title');
$viewFile = __DIR__ . '/../../public/views/register.php';

if (isLoggedIn()) {
    redirectTo('dashboard');
}

$pdo = getPDO();
ensureSignupThrottleSchema($pdo);
$userModel = new UserModel($pdo);
$companyModel = new CompanyModel($pdo);
$departmentModel = new DepartmentModel($pdo);

$registerErrors = [];
$formValues = [
    'company_name' => trim((string) ($_POST['company_name'] ?? '')),
    'first_name' => trim((string) ($_POST['first_name'] ?? '')),
    'last_name' => trim((string) ($_POST['last_name'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $honeypot = trim((string) ($_POST['website'] ?? ''));
    $lastAttemptAt = (int) ($_SESSION['signup_last_attempt_at'] ?? 0);

    if ($lastAttemptAt > 0 && (time() - $lastAttemptAt) < 10) {
        $registerErrors[] = t('signup.error_too_fast');
    } elseif ($ip !== '' && recentSignupAttemptCount($pdo, $ip, 60) >= 5) {
        $registerErrors[] = t('signup.error_too_many_attempts');
    } elseif (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $registerErrors[] = t('signup.error_csrf');
    } elseif ($honeypot !== '') {
        $registerErrors[] = t('signup.error_bot_detected');
    } elseif (!verifySignupCaptcha($_POST['captcha_answer'] ?? null)) {
        $registerErrors[] = t('signup.error_captcha');
    } elseif ($formValues['company_name'] === '' || $formValues['first_name'] === '' || $formValues['last_name'] === '' || $formValues['email'] === '' || $password === '') {
        $registerErrors[] = t('signup.error_required_fields');
    } elseif (!filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
        $registerErrors[] = t('signup.error_invalid_email');
    } elseif (($passwordStrengthError = validatePasswordStrength($password)) !== null) {
        $registerErrors[] = $passwordStrengthError;
    } elseif ($password !== $passwordConfirm) {
        $registerErrors[] = t('signup.error_password_mismatch');
    } elseif ($userModel->findByEmail($formValues['email']) !== null) {
        $registerErrors[] = t('signup.error_email_taken');
    }

    $_SESSION['signup_last_attempt_at'] = time();
    if ($ip !== '') {
        recordSignupAttempt($pdo, $ip);
    }

    if (empty($registerErrors)) {
        $pdo->beginTransaction();
        try {
            $companyId = $companyModel->create([
                'name' => $formValues['company_name'],
                'type' => 'other',
                'email' => $formValues['email'],
            ]);

            $departmentId = $departmentModel->create([
                'company_id' => $companyId,
                'name' => 'Reception',
                'icon' => '🏨',
                'color' => '#b98b12',
                'description' => 'Reception department',
                'head_user_id' => null,
            ]);

            $userId = $userModel->create([
                'department_id' => $departmentId,
                'first_name' => $formValues['first_name'],
                'last_name' => $formValues['last_name'],
                'email' => $formValues['email'],
                'phone' => null,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
                'status' => 'active',
                'company_id' => $companyId,
            ]);

            $pdo->commit();

            $newUser = [
                'id' => $userId,
                'first_name' => $formValues['first_name'],
                'last_name' => $formValues['last_name'],
                'email' => $formValues['email'],
                'role' => 'admin',
                'department_id' => $departmentId,
            ];

            session_regenerate_id(true);
            $_SESSION['auth_user'] = $newUser;
            $_SESSION['show_onboarding_tour'] = true;
            unset($_SESSION['csrf_token'], $_SESSION['signup_captcha_a'], $_SESSION['signup_captcha_b'], $_SESSION['signup_captcha_answer'], $_SESSION['signup_last_attempt_at']);

            try {
                recordUserConnectionLogin($pdo, $newUser);
            } catch (Throwable $exception) {
                // Ignore login tracking failures.
            }

            setFlash('success', t('signup.success_flash'));
            redirectTo('dashboard');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $registerErrors[] = t('signup.error_server_error');
        }
    }
}

$signupCaptcha = newSignupCaptchaChallenge();
