<!-- Shared login page: authenticates all active roles. -->
<?php $loginError = $loginError ?? null; ?>
<?php
$basePath = $basePath ?? (function () {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
?>
<div class="auth-card">
    <h1><?php echo e(t('auth.login_title')); ?></h1>
    <p><?php echo e(t('auth.login_info')); ?></p>

    <?php if ($loginError !== null): ?>
        <div id="flash-backdrop-login" class="flash-backdrop"></div>
        <div id="flash-login" class="flash flash-error" role="alert" aria-live="assertive" data-backdrop="flash-backdrop-login">
            <span class="flash-icon" aria-hidden="true">
                <img src="<?php echo $basePath; ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" />
            </span>
            <div class="flash-body">
                <div class="flash-title"><?php echo e(t('auth.login_error_title')); ?></div>
                <p><?php echo e($loginError); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <form action="<?php echo appUrl('login'); ?>" method="post" class="login-form">
        <div>
            <input type="email" id="email" placeholder="<?php echo e(t('auth.email_placeholder')); ?>" name="email" value="<?php echo e($loginEmail ?? ''); ?>" required>
        </div>
        <div>
            <input type="password" id="password" placeholder="<?php echo e(t('auth.password_placeholder')); ?>" name="password" required>
        </div>
        <div>
            <button type="submit"><?php echo e(t('auth.sign_in')); ?></button>
        </div>
    </form>

    <p><a href="<?php echo appUrl('forgot-password'); ?>"><?php echo e(t('auth.forgot_password_link')); ?></a></p>

    <p><?php echo e(t('signup.no_account_prefix')); ?> <a href="<?php echo appUrl('register'); ?>"><?php echo e(t('signup.home_cta')); ?></a></p>
</div>
