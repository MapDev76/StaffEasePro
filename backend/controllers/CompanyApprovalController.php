<?php
/**
 * Applies an approve/reject decision from the one-click links in the
 * authorization email.
 *
 * The token is single-use and identifies the request on its own, so the
 * platform owner can decide straight from their inbox without signing in.
 * A logged-in super admin reaches the same code path from the dashboard list.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/UserModel.php';

$pageTitle = t('approval.page_title');
$viewFile = __DIR__ . '/../../public/views/company-approval.php';

$pdo = getPDO();
ensureCompanyApprovalSchema($pdo);

$approvalToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$approvalDecision = strtolower(trim((string) ($_GET['decision'] ?? $_POST['decision'] ?? '')));

$approvalOutcome = 'invalid';   // invalid | approved | rejected
$approvalCompanyName = '';
$approvalTrialEndsAt = null;

$approvalRow = findPendingCompanyApproval($pdo, $approvalToken);

if ($approvalRow !== null && in_array($approvalDecision, ['approve', 'reject'], true)) {
    $approved = $approvalDecision === 'approve';
    $approvalCompanyName = (string) ($approvalRow['company_name'] ?? '');

    try {
        $pdo->beginTransaction();
        $decision = decideCompanyApproval($pdo, $approvalRow, $approved);
        $pdo->commit();

        $approvalOutcome = $approved ? 'approved' : 'rejected';
        $approvalTrialEndsAt = $decision['trial_ends_at'];

        // Tell the applicant how it went.
        try {
            $companyRow = $pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
            $companyRow->execute(['id' => $decision['company_id']]);
            $company = $companyRow->fetch(PDO::FETCH_ASSOC) ?: ['name' => $approvalCompanyName];

            $requester = null;
            if (!empty($approvalRow['requested_by_user_id'])) {
                $userLookup = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1');
                $userLookup->execute(['id' => (int) $approvalRow['requested_by_user_id']]);
                $requester = $userLookup->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($requester === null) {
                // Older rows may have no requester recorded: fall back to the
                // company owner so the applicant is always told the outcome.
                $ownerLookup = $pdo->prepare(
                    "SELECT first_name, last_name, email
                     FROM users
                     WHERE company_id = :company_id AND role = 'admin' AND status = 'active'
                     ORDER BY id ASC LIMIT 1"
                );
                $ownerLookup->execute(['company_id' => $decision['company_id']]);
                $requester = $ownerLookup->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($requester !== null) {
                if ($approved) {
                    sendCompanyApprovedEmail($requester, $company, $approvalTrialEndsAt);
                } else {
                    sendCompanyRejectedEmail($requester, $company);
                }
            }
        } catch (Throwable $mailError) {
            mailLog('approval decision notification failed: ' . $mailError->getMessage());
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $approvalOutcome = 'invalid';
        mailLog('approval decision failed: ' . $exception->getMessage());
    }
}
