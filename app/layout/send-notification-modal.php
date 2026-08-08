<?php
/**
 * Compose a general notification to send to all or some employees of the
 * admin's own company. Admin-only, dashboard-only: the employee space is
 * receive-only for these (see notifications-panel.php).
 *
 * Expects $dashboardModalUsers (set by DashboardController for the admin
 * role, scoped to their company already).
 */
if (!isLoggedIn() || (currentUser()['role'] ?? '') !== 'admin') {
    return;
}

$broadcastEmployees = array_values(array_filter(
    is_array($dashboardModalUsers ?? null) ? $dashboardModalUsers : [],
    static fn(array $u): bool => (string) ($u['role'] ?? '') === 'employee' && (string) ($u['status'] ?? 'active') === 'active'
));
?>
<section class="dashboard-modal send-notification-modal" id="modal-send-notification" hidden role="dialog" aria-modal="true" aria-labelledby="send-notification-modal-title">
    <div class="crud-modal-card">
        <div class="crud-modal-head">
            <h2 id="send-notification-modal-title"><?php echo e(t('notifications.compose_title')); ?></h2>
            <button type="button" class="dashboard-modal-close" data-modal-close aria-label="<?php echo e(t('common.close')); ?>">&times;</button>
        </div>
        <form class="admin-form crud-form" id="send-notification-form">
            <label class="span-2">
                <?php echo e(t('notifications.title_label')); ?>
                <input type="text" id="send-notification-title" maxlength="255" required>
            </label>
            <label class="span-2">
                <?php echo e(t('notifications.message_label')); ?>
                <textarea id="send-notification-message" rows="4" required></textarea>
            </label>
            <label class="span-2 crud-inline-choice" for="send-notification-all">
                <input type="checkbox" id="send-notification-all" data-notification-send-all checked>
                <?php echo e(t('notifications.send_all_label')); ?>
            </label>

            <?php if (empty($broadcastEmployees)): ?>
                <p class="span-2 crud-modal-subtitle"><?php echo e(t('notifications.no_employees')); ?></p>
            <?php else: ?>
                <div class="send-notification-recipients span-2" data-notification-recipients hidden>
                    <?php foreach ($broadcastEmployees as $employee): ?>
                        <label class="crud-inline-choice">
                            <input type="checkbox" data-notification-recipient value="<?php echo (int) ($employee['id'] ?? 0); ?>">
                            <?php echo e(trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''))); ?>
                            <?php if (!empty($employee['department_name'])): ?>
                                <span class="notification-recipient-department">(<?php echo e($employee['department_name']); ?>)</span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="form-actions span-2">
                <button type="submit" class="admin-action-link" id="send-notification-submit" <?php echo empty($broadcastEmployees) ? 'disabled' : ''; ?>>
                    <?php echo e(t('notifications.compose_submit')); ?>
                </button>
            </div>
        </form>
    </div>
</section>
