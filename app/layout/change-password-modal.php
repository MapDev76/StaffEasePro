<?php
/**
 * Self-service account modal: lets the signed-in user update their name and
 * login email, and optionally set a new password. Rendered on my-space for
 * every role and on the dashboard for super admins only.
 */
if (!isLoggedIn()) {
    return;
}

$accountUser = currentUser() ?? [];
?>
<section class="dashboard-modal change-password-modal" id="modal-change-password" hidden role="dialog" aria-modal="true" aria-labelledby="change-password-modal-title">
    <div class="crud-modal-card">
        <div class="crud-modal-head">
            <h2 id="change-password-modal-title"><?php echo e(t('auth.change_password_title')); ?></h2>
            <button type="button" class="dashboard-modal-close" data-modal-close aria-label="<?php echo e(t('common.close')); ?>">&times;</button>
        </div>
        <form class="admin-form crud-form change-password-form" id="change-password-form">
            <label>
                <?php echo e(t('crud.first_name')); ?>
                <input type="text" id="change-password-first-name" autocomplete="given-name" value="<?php echo e($accountUser['first_name'] ?? ''); ?>" required>
            </label>
            <label>
                <?php echo e(t('crud.last_name')); ?>
                <input type="text" id="change-password-last-name" autocomplete="family-name" value="<?php echo e($accountUser['last_name'] ?? ''); ?>" required>
            </label>
            <label>
                <?php echo e(t('crud.email')); ?>
                <input type="email" id="change-password-email" autocomplete="email" value="<?php echo e($accountUser['email'] ?? ''); ?>" required>
            </label>
            <label>
                <?php echo e(t('auth.current_password_label')); ?>
                <input type="password" id="change-password-current" autocomplete="current-password" required>
            </label>
            <label>
                <?php echo e(t('auth.new_password_label')); ?>
                <span class="password-field-with-generate">
                    <input type="password" id="change-password-new" autocomplete="new-password" minlength="8">
                    <button type="button" class="admin-action-link admin-action-link-secondary" data-generate-password data-generate-password-also="change-password-new-confirm"><?php echo e(t('auth.generate_password')); ?></button>
                </span>
            </label>
            <label>
                <?php echo e(t('auth.new_password_confirm_label')); ?>
                <input type="password" id="change-password-new-confirm" autocomplete="new-password" minlength="8">
            </label>
            <p class="crud-modal-subtitle"><?php echo e(t('auth.password_requirements_hint')); ?></p>
            <p class="crud-modal-subtitle"><?php echo e(t('auth.new_password_optional_hint')); ?></p>
            <div class="form-actions">
                <button type="submit" class="admin-action-link" id="change-password-submit"><?php echo e(t('auth.change_password_submit')); ?></button>
            </div>
        </form>
    </div>
</section>
