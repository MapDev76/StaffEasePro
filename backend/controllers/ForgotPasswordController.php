<?php
/**
 * "I forgot my password" request controller.
 *
 * Issues a one-time reset token and emails it through Brevo. The response is
 * deliberately identical whether or not the address exists, so the form cannot
 * be used to enumerate registered accounts. Abuse is limited by a per-session
 * cooldown and a per-IP hourly cap, mirroring RegisterController.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/UserModel.php';

$pageTitle = t('auth.forgot_password_title');
$viewFile = __DIR__ . '/../../public/views/auth/forgot-password.php';

if (isLoggedIn()) {
    redirectTo('dashboard');
}

$pdo = getPDO();
ensurePasswordResetSchema($pdo);
$userModel = new UserModel($pdo);

$forgotErrors = [];
$forgotSubmitted = false;
$forgotEmail = trim((string) ($_POST['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $honeypot = trim((string) ($_POST['website'] ?? ''));
    $lastAttemptAt = (int) ($_SESSION['forgot_last_attempt_at'] ?? 0);

    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $forgotErrors[] = t('signup.error_csrf');
    } elseif ($honeypot !== '') {
        $forgotErrors[] = t('signup.error_bot_detected');
    } elseif ($lastAttemptAt > 0 && (time() - $lastAttemptAt) < 10) {
        $forgotErrors[] = t('signup.error_too_fast');
    } elseif (recentPasswordResetCount($pdo, $ip) >= 5) {
        $forgotErrors[] = t('signup.error_too_many_attempts');
    } elseif ($forgotEmail === '' || !filter_var($forgotEmail, FILTER_VALIDATE_EMAIL)) {
        $forgotErrors[] = t('auth.email_invalid');
    }

    if (empty($forgotErrors)) {
        $_SESSION['forgot_last_attempt_at'] = time();

        try {
            $account = $userModel->findByEmail($forgotEmail);
            if ($account) {
                $token = createPasswordResetToken($pdo, (int) $account['id'], $ip);
                sendPasswordResetEmail($account, $token, passwordResetTokenTtlMinutes());
            }
        } catch (Throwable $e) {
            mailLog('Password reset request failed: ' . $e->getMessage());
        }

        // Same answer either way: never reveal whether the account exists.
        $forgotSubmitted = true;
    }
}

$forgotCsrfToken = csrfToken();
