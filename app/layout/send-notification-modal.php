<?php
/**
 * Compose a general notification to send to employees. Available to admin,
 * super_admin and department_manager, each scoped to their own reach
 * (department_manager -> own department, admin -> own company, super_admin
 * -> a company picked explicitly since they have none of their own).
 *
 * Expects $dashboardModalUsers and $dashboardModalCompanies (set by
 * DashboardController, already scoped per role).
 */
$notificationSenderRole = currentUser()['role'] ?? '';
if (!isLoggedIn() || !in_array($notificationSenderRole, ['admin', 'super_admin', 'department_manager'], true)) {
    return;
}

$notificationCurrentUser = currentUser();
$broadcastEmployees = array_values(array_filter(
    is_array($dashboardModalUsers ?? null) ? $dashboardModalUsers : [],
    static function (array $u) use ($notificationSenderRole, $notificationCurrentUser): bool {
        if ((string) ($u['role'] ?? '') !== 'employee' || (string) ($u['status'] ?? 'active') !== 'active') {
            return false;
        }
        if ($notificationSenderRole === 'department_manager') {
            return (int) ($u['department_id'] ?? 0) === (int) ($notificationCurrentUser['department_id'] ?? 0);
        }
        return true;
    }
));
$notificationCompanies = is_array($dashboardModalCompanies ?? null) ? $dashboardModalCompanies : [];
?>
<section class="dashboard-modal send-notification-modal" id="modal-send-notification" hidden role="dialog" aria-modal="true" aria-labelledby="send-notification-modal-title">
    <div class="crud-modal-card">
        <div class="crud-modal-head">
            <h2 id="send-notification-modal-title"><?php echo e(t('notifications.compose_title')); ?></h2>
            <button type="button" class="dashboard-modal-close" data-modal-close aria-label="<?php echo e(t('common.close')); ?>">&times;</button>
        </div>
        <form class="admin-form crud-form" id="send-notification-form">
            <?php if ($notificationSenderRole === 'super_admin'): ?>
                <label class="span-2">
                    <?php echo e(t('common.company')); ?>
                    <select id="send-notification-company">
                        <option value=""><?php echo e(t('crud.select_company', ['fallback' => 'Seleziona azienda'])); ?></option>
                        <?php foreach ($notificationCompanies as $companyOption): ?>
                            <option value="<?php echo (int) ($companyOption['id'] ?? 0); ?>"><?php echo e($companyOption['name'] ?? ''); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label class="span-2">
                <?php echo e(t('notifications.title_label')); ?>
                <input type="text" id="send-notification-title" maxlength="255" required>
            </label>
            <label class="span-2">
                <?php echo e(t('notifications.message_label')); ?>
                <textarea id="send-notification-message" rows="4" required></textarea>
            </label>
            <label class="span-2 crud-inline-choice" for="send-notification-require-response">
                <input type="checkbox" id="send-notification-require-response">
                <?php echo e(t('notifications.require_response_label', ['fallback' => 'Richiede approvazione o rifiuto da parte del destinatario'])); ?>
            </label>
            <label class="span-2 crud-inline-choice" for="send-notification-all">
                <input type="checkbox" id="send-notification-all" data-notification-send-all checked>
                <?php echo e(t('notifications.send_all_label')); ?>
            </label>

            <?php if (empty($broadcastEmployees) && $notificationSenderRole !== 'super_admin'): ?>
                <p class="span-2 crud-modal-subtitle"><?php echo e(t('notifications.no_employees')); ?></p>
            <?php endif; ?>
            <div class="send-notification-recipients span-2" data-notification-recipients hidden>
                <?php foreach ($broadcastEmployees as $employee): ?>
                    <label class="crud-inline-choice" data-notification-recipient-row data-company-id="<?php echo (int) ($employee['company_id'] ?? 0); ?>">
                        <input type="checkbox" data-notification-recipient value="<?php echo (int) ($employee['id'] ?? 0); ?>">
                        <?php echo e(trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''))); ?>
                        <?php if (!empty($employee['department_name'])): ?>
                            <span class="notification-recipient-department">(<?php echo e($employee['department_name']); ?>)</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="form-actions span-2">
                <button type="submit" class="admin-action-link" id="send-notification-submit" <?php echo (empty($broadcastEmployees) && $notificationSenderRole !== 'super_admin') ? 'disabled' : ''; ?>>
                    <?php echo e(t('notifications.compose_submit')); ?>
                </button>
            </div>
        </form>
    </div>
</section>
