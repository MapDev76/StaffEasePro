<?php
/**
 * Lock screen shown instead of the app when the signed-in user's company is not
 * cleared for use: awaiting approval, rejected, or past its trial.
 *
 * Included by index.php, which stops rendering the normal page afterwards.
 *
 * Expects $accountGateState ('pending'|'rejected'|'expired') and
 * $accountGateCompanyName.
 */
$accountGateState = $accountGateState ?? 'pending';
$accountGateCompanyName = $accountGateCompanyName ?? '';
$accountGateSent = $accountGateSent ?? false;

$gateTitleKey = match ($accountGateState) {
    'rejected' => 'approval.rejected_user_title',
    'expired' => 'approval.expired_title',
    default => 'approval.pending_title',
};
$gateBodyKey = match ($accountGateState) {
    'rejected' => 'approval.rejected_user_body',
    'expired' => 'approval.expired_body',
    default => 'approval.pending_body',
};
// A pending request is already queued; the other two states can ask again.
$canRequestAgain = $accountGateState !== 'pending';
?>
<div class="auth-card account-gate-card">
    <h1><?php echo e(t($gateTitleKey)); ?></h1>
    <p><?php echo e(t($gateBodyKey, ['company' => $accountGateCompanyName])); ?></p>

    <?php if ($accountGateSent): ?>
        <p class="account-gate-sent"><?php echo e(t('approval.request_sent')); ?></p>
    <?php elseif ($canRequestAgain): ?>
        <form action="<?php echo appUrl('request-authorization'); ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
            <button type="submit" class="admin-action-link"><?php echo e(t('approval.request_again')); ?></button>
        </form>
    <?php endif; ?>

    <p><a href="<?php echo appUrl('logout'); ?>"><?php echo e(t('common.logout')); ?></a></p>
</div>
