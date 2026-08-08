<?php
/**
 * Lets a signed-in admin whose company was rejected or whose trial expired
 * queue a fresh authorization request for the platform owner.
 *
 * A company that is already pending has nothing to re-send, so it is refused.
 */
require_once __DIR__ . '/../bootstrap.php';

if (!isLoggedIn()) {
    redirectTo('login');
}

$pdo = getPDO();
ensureCompanyApprovalSchema($pdo);

$currentAccount = currentUser() ?? [];
$requestCompanyId = resolveUserCompanyId($pdo, $currentAccount);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    redirectTo('dashboard');
}

$state = companyAccessState($pdo, $requestCompanyId);
if ($requestCompanyId <= 0 || !in_array($state, ['rejected', 'expired'], true)) {
    redirectTo('dashboard');
}

try {
    $companyLookup = $pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
    $companyLookup->execute(['id' => $requestCompanyId]);
    $company = $companyLookup->fetch(PDO::FETCH_ASSOC) ?: [];

    // Back to pending so the lock screen stops offering the button.
    $pdo->prepare("UPDATE companies SET approval_status = 'pending' WHERE id = :id")
        ->execute(['id' => $requestCompanyId]);

    $token = createCompanyApprovalToken($pdo, $requestCompanyId, (int) ($currentAccount['id'] ?? 0));
    sendCompanyApprovalRequestEmail(
        $company,
        [
            'first_name' => $currentAccount['first_name'] ?? '',
            'last_name' => $currentAccount['last_name'] ?? '',
            'contact_role' => $company['contact_role'] ?? '',
            'email' => $currentAccount['email'] ?? '',
            'phone' => '',
        ],
        $token
    );
} catch (Throwable $exception) {
    mailLog('re-request authorization failed: ' . $exception->getMessage());
}

setFlash('success', t('approval.request_sent'));
redirectTo('dashboard');
