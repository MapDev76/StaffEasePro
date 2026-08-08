<!-- Sets a new password from a one-time emailed token. -->
<?php
$basePath = $basePath ?? (function () {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
$resetErrors = $resetErrors ?? [];
$resetTokenValid = $resetTokenValid ?? false;
?>
<div class="auth-card">
    <h1><?php echo e(t('auth.reset_password_title')); ?></h1>

    <?php if (!empty($resetErrors)): ?>
        <div id="flash-backdrop-reset" class="flash-backdrop"></div>
        <div id="flash-reset" class="flash flash-error" role="alert" aria-live="assertive" data-backdrop="flash-backdrop-reset">
            <span class="flash-icon" aria-hidden="true">
                <img src="<?php echo $basePath; ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" />
            </span>
            <div class="flash-body">
                <div class="flash-title"><?php echo e(t('auth.login_error_title')); ?></div>
                <?php foreach ($resetErrors as $resetError): ?>
                    <p><?php echo e($resetError); ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$resetTokenValid): ?>
        <p><?php echo e(t('auth.reset_token_invalid')); ?></p>
        <p><a href="<?php echo appUrl('forgot-password'); ?>"><?php echo e(t('auth.forgot_password_retry')); ?></a></p>
    <?php else: ?>
        <p><?php echo e(t('auth.reset_password_info')); ?></p>

        <form action="<?php echo appUrl('reset-password'); ?>" method="post" class="login-form">
            <input type="hidden" name="csrf_token" value="<?php echo e($resetCsrfToken ?? ''); ?>">
            <input type="hidden" name="token" value="<?php echo e($resetToken ?? ''); ?>">
            <div>
                <input type="password" id="reset-password" placeholder="<?php echo e(t('auth.new_password_label')); ?>" name="password" minlength="8" required>
            </div>
            <div>
                <input type="password" id="reset-password-confirm" placeholder="<?php echo e(t('auth.new_password_confirm_label')); ?>" name="password_confirm" minlength="8" required>
            </div>
            <div>
                <button type="submit"><?php echo e(t('auth.reset_password_submit')); ?></button>
            </div>
        </form>

        <p><?php echo e(t('auth.password_requirements_hint')); ?></p>
    <?php endif; ?>
</div>
