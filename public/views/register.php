<!-- Public self-service registration: creates a new company + its first admin user. -->
<?php
$basePath = $basePath ?? (function () {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
$registerErrors = $registerErrors ?? [];
$formValues = $formValues ?? ['company_name' => '', 'first_name' => '', 'last_name' => '', 'email' => ''];
$signupCaptcha = $signupCaptcha ?? ['a' => 0, 'b' => 0];
?>
<div class="auth-card">
    <h1><?php echo e(t('signup.heading')); ?></h1>
    <p><?php echo e(t('signup.subheading')); ?></p>

    <?php if (!empty($registerErrors)): ?>
        <div id="flash-backdrop-register" class="flash-backdrop"></div>
        <div id="flash-register" class="flash flash-error" role="alert" aria-live="assertive" data-backdrop="flash-backdrop-register">
            <span class="flash-icon" aria-hidden="true">
                <img src="<?php echo $basePath; ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" />
            </span>
            <div class="flash-body">
                <div class="flash-title"><?php echo e(t('auth.login_error_title')); ?></div>
                <?php foreach ($registerErrors as $registerError): ?>
                    <p><?php echo e($registerError); ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <form action="<?php echo appUrl('register'); ?>" method="post" class="login-form">
        <div class="signup-hp-field" aria-hidden="true">
            <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>
        <div>
            <input type="text" id="company_name" placeholder="<?php echo e(t('signup.company_name_label')); ?>" name="company_name" value="<?php echo e($formValues['company_name']); ?>" required>
        </div>
        <div>
            <input type="text" id="first_name" placeholder="<?php echo e(t('signup.first_name_label')); ?>" name="first_name" value="<?php echo e($formValues['first_name']); ?>" required>
        </div>
        <div>
            <input type="text" id="last_name" placeholder="<?php echo e(t('signup.last_name_label')); ?>" name="last_name" value="<?php echo e($formValues['last_name']); ?>" required>
        </div>
        <div>
            <input type="email" id="email" placeholder="<?php echo e(t('signup.email_label')); ?>" name="email" value="<?php echo e($formValues['email']); ?>" required>
        </div>
        <div>
            <input type="password" id="password" placeholder="<?php echo e(t('signup.password_label')); ?>" name="password" minlength="8" required>
        </div>
        <div>
            <input type="password" id="password_confirm" placeholder="<?php echo e(t('signup.password_confirm_label')); ?>" name="password_confirm" minlength="8" required>
        </div>
        <div class="signup-captcha-row">
            <label for="captcha_answer"><?php echo e(t('signup.captcha_label', ['a' => $signupCaptcha['a'], 'b' => $signupCaptcha['b']])); ?></label>
            <input type="text" inputmode="numeric" id="captcha_answer" name="captcha_answer" required>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
        <div>
            <button type="submit"><?php echo e(t('signup.submit_button')); ?></button>
        </div>
    </form>

    <p><?php echo e(t('signup.login_link_prefix')); ?> <a href="<?php echo appUrl('login'); ?>"><?php echo e(t('signup.login_link_cta')); ?></a></p>
</div>
