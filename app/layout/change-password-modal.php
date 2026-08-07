<?php
/**
 * Self-service "change my password" modal, available to every logged-in
 * role on both the dashboard and my-space routes.
 */
if (!isLoggedIn()) {
    return;
}
?>
<section class="dashboard-modal change-password-modal" id="modal-change-password" hidden role="dialog" aria-modal="true" aria-labelledby="change-password-modal-title">
    <div class="crud-modal-card">
        <div class="crud-modal-head">
            <h2 id="change-password-modal-title"><?php echo e(t('auth.change_password_title')); ?></h2>
            <button type="button" class="dashboard-modal-close" data-modal-close aria-label="<?php echo e(t('common.close')); ?>">&times;</button>
        </div>
        <form class="admin-form crud-form" id="change-password-form">
            <label class="span-2">
                <?php echo e(t('auth.current_password_label')); ?>
                <input type="password" id="change-password-current" autocomplete="current-password" required>
            </label>
            <label>
                <?php echo e(t('auth.new_password_label')); ?>
                <span class="password-field-with-generate">
                    <input type="password" id="change-password-new" autocomplete="new-password" minlength="8" required>
                    <button type="button" class="admin-action-link admin-action-link-secondary" data-generate-password data-generate-password-also="change-password-new-confirm"><?php echo e(t('auth.generate_password')); ?></button>
                </span>
            </label>
            <label>
                <?php echo e(t('auth.new_password_confirm_label')); ?>
                <input type="password" id="change-password-new-confirm" autocomplete="new-password" minlength="8" required>
            </label>
            <p class="crud-modal-subtitle span-2"><?php echo e(t('auth.password_requirements_hint')); ?></p>
            <div class="form-actions span-2">
                <button type="submit" class="admin-action-link" id="change-password-submit"><?php echo e(t('auth.change_password_submit')); ?></button>
            </div>
        </form>
    </div>
</section>
