<?php
/**
 * Self-service registration controller.
 *
 * Collects the full company and contact details, then creates the company plus
 * its first admin user in a single transaction with approval_status='pending'.
 * The platform owner receives an email with one-click approve/reject links; the
 * applicant can sign in immediately but only sees a waiting screen until the
 * request is approved, which starts the trial. Protected by a honeypot field,
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
ensureCompanyApprovalSchema($pdo);
$userModel = new UserModel($pdo);
$companyModel = new CompanyModel($pdo);
$departmentModel = new DepartmentModel($pdo);

$registerErrors = [];
$formValues = [
    // Company
    'company_name' => trim((string) ($_POST['company_name'] ?? '')),
    'company_type' => trim((string) ($_POST['company_type'] ?? 'other')),
    'vat_number' => trim((string) ($_POST['vat_number'] ?? '')),
    'address' => trim((string) ($_POST['address'] ?? '')),
    'city' => trim((string) ($_POST['city'] ?? '')),
    'province' => trim((string) ($_POST['province'] ?? '')),
    'zip_code' => trim((string) ($_POST['zip_code'] ?? '')),
    'company_phone' => trim((string) ($_POST['company_phone'] ?? '')),
    'company_email' => trim((string) ($_POST['company_email'] ?? '')),
    // Contact person
    'first_name' => trim((string) ($_POST['first_name'] ?? '')),
    'last_name' => trim((string) ($_POST['last_name'] ?? '')),
    'contact_role' => trim((string) ($_POST['contact_role'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'phone' => trim((string) ($_POST['phone'] ?? '')),
];

// Company types accepted by the companies.type enum.
$signupCompanyTypes = ['hotel', 'hospital', 'clinic', 'elderly_center', 'restaurant', 'other'];

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
    } elseif ($formValues['company_name'] === '' || $formValues['city'] === '' || $formValues['first_name'] === '' || $formValues['last_name'] === '' || $formValues['email'] === '' || $password === '') {
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
                'type' => in_array($formValues['company_type'], $signupCompanyTypes, true) ? $formValues['company_type'] : 'other',
                'address' => $formValues['address'] !== '' ? $formValues['address'] : null,
                'city' => $formValues['city'],
                'zip_code' => $formValues['zip_code'] !== '' ? $formValues['zip_code'] : null,
                'phone' => $formValues['company_phone'] !== '' ? $formValues['company_phone'] : null,
                'email' => $formValues['company_email'] !== '' ? $formValues['company_email'] : $formValues['email'],
            ]);

            // Columns CompanyModel::create() does not write: the approval fields it
            // does not know about, plus province which is missing from its column list.
            $pdo->prepare(
                "UPDATE companies
                 SET approval_status = 'pending', vat_number = :vat, contact_role = :contact_role, province = :province
                 WHERE id = :id"
            )->execute([
                'vat' => $formValues['vat_number'] !== '' ? $formValues['vat_number'] : null,
                'contact_role' => $formValues['contact_role'] !== '' ? $formValues['contact_role'] : null,
                'province' => $formValues['province'] !== '' ? $formValues['province'] : null,
                'id' => $companyId,
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
                'phone' => $formValues['phone'] !== '' ? $formValues['phone'] : null,
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
            unset($_SESSION['csrf_token'], $_SESSION['signup_captcha_a'], $_SESSION['signup_captcha_b'], $_SESSION['signup_captcha_answer'], $_SESSION['signup_last_attempt_at']);

            try {
                recordUserConnectionLogin($pdo, $newUser);
            } catch (Throwable $exception) {
                // Ignore login tracking failures.
            }

            // Ask the platform owner to authorize this sign-up.
            try {
                $approvalToken = createCompanyApprovalToken($pdo, $companyId, $userId);
                sendCompanyApprovalRequestEmail(
                    [
                        'name' => $formValues['company_name'],
                        'type' => $formValues['company_type'],
                        'vat_number' => $formValues['vat_number'],
                        'address' => $formValues['address'],
                        'city' => $formValues['city'],
                        'province' => $formValues['province'],
                        'zip_code' => $formValues['zip_code'],
                        'phone' => $formValues['company_phone'],
                        'email' => $formValues['company_email'],
                    ],
                    [
                        'first_name' => $formValues['first_name'],
                        'last_name' => $formValues['last_name'],
                        'contact_role' => $formValues['contact_role'],
                        'email' => $formValues['email'],
                        'phone' => $formValues['phone'],
                    ],
                    $approvalToken
                );
            } catch (Throwable $mailError) {
                mailLog('approval request notification failed: ' . $mailError->getMessage());
            }

            setFlash('success', t('approval.request_sent'));
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
