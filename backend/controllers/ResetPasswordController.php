<?php
/**
 * Consumes a password reset token and stores the new password.
 *
 * The token arrives as a query string on GET (the emailed link) and as a hidden
 * field on POST. It is validated on both, so an expired or already-used link
 * can never reach the update statement.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/UserModel.php';

$pageTitle = t('auth.reset_password_title');
$viewFile = __DIR__ . '/../../public/views/auth/reset-password.php';

if (isLoggedIn()) {
    redirectTo('dashboard');
}

$pdo = getPDO();
ensurePasswordResetSchema($pdo);
$userModel = new UserModel($pdo);

$resetToken = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$resetErrors = [];
$resetDone = false;

$resetRow = findValidPasswordResetToken($pdo, $resetToken);
$resetTokenValid = $resetRow !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = (string) ($_POST['password'] ?? '');
    $newPasswordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $resetErrors[] = t('signup.error_csrf');
    } elseif (!$resetTokenValid) {
        $resetErrors[] = t('auth.reset_token_invalid');
    } else {
        $strengthError = validatePasswordStrength($newPassword);
        if ($strengthError !== null) {
            $resetErrors[] = $strengthError;
        } elseif ($newPassword !== $newPasswordConfirm) {
            $resetErrors[] = t('auth.password_mismatch');
        }
    }

    if (empty($resetErrors) && $resetRow !== null) {
        $pdo->prepare('UPDATE users SET password = :password WHERE id = :id')->execute([
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int) $resetRow['user_id'],
        ]);
        consumePasswordResetToken($pdo, (int) $resetRow['id']);

        $resetDone = true;
        $resetTokenValid = false;
        setFlash('success', t('auth.reset_password_success'));
        redirectTo('login');
    }
}

$resetCsrfToken = csrfToken();
