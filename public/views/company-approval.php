<!-- Result page for the approve/reject links sent by email. -->
<?php
$approvalOutcome = $approvalOutcome ?? 'invalid';
$approvalCompanyName = $approvalCompanyName ?? '';
$approvalTrialEndsAt = $approvalTrialEndsAt ?? null;
$trialEndsLabel = $approvalTrialEndsAt !== null && strtotime((string) $approvalTrialEndsAt) !== false
    ? date('d/m/Y', strtotime((string) $approvalTrialEndsAt))
    : '';
?>
<div class="auth-card">
    <?php if ($approvalOutcome === 'approved'): ?>
        <h1><?php echo e(t('approval.approved_title')); ?></h1>
        <p><?php echo e(t('approval.approved_body', ['company' => $approvalCompanyName])); ?></p>
        <?php if ($trialEndsLabel !== ''): ?>
            <p><strong><?php echo e(t('mail.field_trial_ends')); ?>:</strong> <?php echo e($trialEndsLabel); ?></p>
        <?php endif; ?>
    <?php elseif ($approvalOutcome === 'rejected'): ?>
        <h1><?php echo e(t('approval.rejected_title')); ?></h1>
        <p><?php echo e(t('approval.rejected_body', ['company' => $approvalCompanyName])); ?></p>
    <?php else: ?>
        <h1><?php echo e(t('approval.invalid_title')); ?></h1>
        <p><?php echo e(t('approval.invalid_body')); ?></p>
    <?php endif; ?>

    <p><a href="<?php echo appUrl('dashboard'); ?>"><?php echo e(t('common.dashboard')); ?></a></p>
</div>
