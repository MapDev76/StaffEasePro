<!-- Password reset request: emails a one-time link through Brevo. -->
<?php
$basePath = $basePath ?? (function () {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
$forgotErrors = $forgotErrors ?? [];
$forgotSubmitted = $forgotSubmitted ?? false;
?>
<div class="auth-card">
    <h1><?php echo e(t('auth.forgot_password_title')); ?></h1>

    <?php if ($forgotSubmitted): ?>
        <p><?php echo e(t('auth.forgot_password_sent')); ?></p>
        <p><a href="<?php echo appUrl('login'); ?>"><?php echo e(t('auth.back_to_login')); ?></a></p>
    <?php else: ?>
        <p><?php echo e(t('auth.forgot_password_info')); ?></p>

        <?php if (!empty($forgotErrors)): ?>
            <div id="flash-backdrop-forgot" class="flash-backdrop"></div>
            <div id="flash-forgot" class="flash flash-error" role="alert" aria-live="assertive" data-backdrop="flash-backdrop-forgot">
                <span class="flash-icon" aria-hidden="true">
                    <img src="<?php echo $basePath; ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" />
                </span>
                <div class="flash-body">
                    <div class="flash-title"><?php echo e(t('auth.login_error_title')); ?></div>
                    <?php foreach ($forgotErrors as $forgotError): ?>
                        <p><?php echo e($forgotError); ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form action="<?php echo appUrl('forgot-password'); ?>" method="post" class="login-form">
            <input type="hidden" name="csrf_token" value="<?php echo e($forgotCsrfToken ?? ''); ?>">
            <div class="signup-hp-field" aria-hidden="true">
                <label for="forgot-website">Website</label>
                <input type="text" id="forgot-website" name="website" tabindex="-1" autocomplete="off">
            </div>
            <div>
                <input type="email" id="forgot-email" placeholder="<?php echo e(t('auth.email_placeholder')); ?>" name="email" value="<?php echo e($forgotEmail ?? ''); ?>" required>
            </div>
            <div>
                <button type="submit"><?php echo e(t('auth.forgot_password_submit')); ?></button>
            </div>
        </form>

        <p><a href="<?php echo appUrl('login'); ?>"><?php echo e(t('auth.back_to_login')); ?></a></p>
    <?php endif; ?>
</div>
