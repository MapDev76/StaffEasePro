<!-- Public self-service registration: collects full company + contact details
     and opens an authorization request awaiting the platform owner's approval. -->
<?php
$basePath = $basePath ?? (function () {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
$registerErrors = $registerErrors ?? [];
$formValues = array_merge([
    'company_name' => '', 'company_type' => 'other', 'vat_number' => '', 'address' => '',
    'city' => '', 'province' => '', 'zip_code' => '', 'company_phone' => '', 'company_email' => '',
    'first_name' => '', 'last_name' => '', 'contact_role' => '', 'email' => '', 'phone' => '',
], $formValues ?? []);
$signupCaptcha = $signupCaptcha ?? ['a' => 0, 'b' => 0];
$signupCompanyTypes = $signupCompanyTypes ?? ['hotel', 'hospital', 'clinic', 'elderly_center', 'restaurant', 'other'];
?>
<div class="auth-card signup-card">
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

    <form action="<?php echo appUrl('register'); ?>" method="post" class="login-form signup-form">
        <div class="signup-hp-field" aria-hidden="true">
            <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <fieldset class="signup-fieldset">
            <legend><?php echo e(t('signup.section_company')); ?></legend>

            <label class="signup-field">
                <span><?php echo e(t('signup.company_name_label')); ?> *</span>
                <input type="text" id="company_name" name="company_name" value="<?php echo e($formValues['company_name']); ?>" required>
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.company_type_label')); ?></span>
                <select id="company_type" name="company_type">
                    <?php foreach ($signupCompanyTypes as $typeOption): ?>
                        <option value="<?php echo e($typeOption); ?>" <?php echo $formValues['company_type'] === $typeOption ? 'selected' : ''; ?>>
                            <?php echo e(t('crud.company_type_' . $typeOption)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.vat_number_label')); ?></span>
                <input type="text" id="vat_number" name="vat_number" value="<?php echo e($formValues['vat_number']); ?>">
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.address_label')); ?></span>
                <input type="text" id="address" name="address" value="<?php echo e($formValues['address']); ?>">
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.city_label')); ?> *</span>
                <input type="text" id="city" name="city" value="<?php echo e($formValues['city']); ?>" required>
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.province_label')); ?></span>
                <input type="text" id="province" name="province" value="<?php echo e($formValues['province']); ?>">
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.zip_code_label')); ?></span>
                <input type="text" id="zip_code" name="zip_code" value="<?php echo e($formValues['zip_code']); ?>">
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.company_phone_label')); ?></span>
                <input type="tel" id="company_phone" name="company_phone" value="<?php echo e($formValues['company_phone']); ?>">
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.company_email_label')); ?></span>
                <input type="email" id="company_email" name="company_email" value="<?php echo e($formValues['company_email']); ?>">
            </label>
        </fieldset>

        <fieldset class="signup-fieldset" id="signup-contact-fieldset">
            <legend><?php echo e(t('signup.section_contact')); ?></legend>

            <label class="signup-field">
                <span><?php echo e(t('signup.first_name_label')); ?> *</span>
                <input type="text" id="first_name" name="first_name" value="<?php echo e($formValues['first_name']); ?>" required>
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.last_name_label')); ?> *</span>
                <input type="text" id="last_name" name="last_name" value="<?php echo e($formValues['last_name']); ?>" required>
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.contact_role_label')); ?></span>
                <input type="text" id="contact_role" name="contact_role" value="<?php echo e($formValues['contact_role']); ?>">
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.email_label')); ?> *</span>
                <input type="email" id="email" name="email" value="<?php echo e($formValues['email']); ?>" required>
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.phone_label')); ?></span>
                <input type="tel" id="phone" name="phone" value="<?php echo e($formValues['phone']); ?>">
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.password_label')); ?> *</span>
                <input type="password" id="password" name="password" minlength="8" required>
            </label>
            <label class="signup-field">
                <span><?php echo e(t('signup.password_confirm_label')); ?> *</span>
                <input type="password" id="password_confirm" name="password_confirm" minlength="8" required>
            </label>
            <p class="signup-hint"><?php echo e(t('auth.password_requirements_hint')); ?></p>
        </fieldset>

        <div class="signup-captcha-row">
            <label for="captcha_answer"><?php echo e(t('signup.captcha_label', ['a' => $signupCaptcha['a'], 'b' => $signupCaptcha['b']])); ?></label>
            <input type="text" inputmode="numeric" id="captcha_answer" name="captcha_answer" required>
        </div>

        <p class="signup-hint" data-trial-tour-approval><?php echo e(t('signup.approval_notice', ['days' => (string) trialPeriodDays()])); ?></p>

        <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
        <div>
            <button type="submit" data-trial-tour-submit><?php echo e(t('signup.submit_button')); ?></button>
        </div>
    </form>

    <p><?php echo e(t('signup.login_link_prefix')); ?> <a href="<?php echo appUrl('login'); ?>"><?php echo e(t('signup.login_link_cta')); ?></a></p>
</div>

<?php $trialDays = (string) trialPeriodDays(); ?>
<script>
        window.TrialTourConfig = <?php echo json_encode([
                'autoStart' => true,
                'steps' => [
                        ['selector' => null, 'title' => t('trial_tour.step_welcome_title'), 'body' => t('trial_tour.step_welcome_body')],
                        ['selector' => '.signup-fieldset', 'title' => t('trial_tour.step_company_title'), 'body' => t('trial_tour.step_company_body')],
                        ['selector' => '#signup-contact-fieldset', 'title' => t('trial_tour.step_contact_title'), 'body' => t('trial_tour.step_contact_body')],
                        ['selector' => '[data-trial-tour-approval]', 'title' => t('trial_tour.step_approval_title'), 'body' => t('trial_tour.step_approval_body')],
                        ['selector' => '[data-trial-tour-approval]', 'title' => t('trial_tour.step_trial_title', ['days' => $trialDays]), 'body' => t('trial_tour.step_trial_body', ['days' => $trialDays])],
                        ['selector' => '[data-trial-tour-submit]', 'title' => t('trial_tour.step_submit_title'), 'body' => t('trial_tour.step_submit_body')],
                ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        window.TrialTourLabels = <?php echo json_encode([
                'buttonNext' => t('trial_tour.button_next'),
                'buttonBack' => t('trial_tour.button_back'),
                'buttonSkip' => t('trial_tour.button_skip'),
                'buttonFinish' => t('trial_tour.button_finish'),
                'stepProgress' => t('trial_tour.step_progress'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script defer src="<?php echo e($basePath); ?>/assets/js/trial-tour.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/trial-tour.js'); ?>"></script>
