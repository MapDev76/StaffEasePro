<?php
/**
 * Interactive schedule modal (demo content).
 *
 * This panel is shown in the dashboard as `#modal-schedule`. It contains a
 * simplified schedule editor used for previewing planning behavior.
 */
if (!isLoggedIn()) {
    return;
}

$currentUser = currentUser();
$role = $currentUser['role'] ?? 'employee';
?>
<section class="dashboard-modal dashboard-schedule-modal" id="modal-schedule" hidden>
    <div class="schedule-modal-card">
    <button type="button" class="dashboard-modal-close" data-modal-close aria-label="<?php echo e(t('common.close_message')); ?>">&times;</button>
    <h2><?php echo e(t('schedule.title')); ?></h2>
    <p><?php echo e(t('schedule.subtitle')); ?></p>

    <div class="schedule-toolbar">
        <button type="button" class="schedule-pill is-active"><?php echo e(t('schedule.shift_times')); ?></button>
        <button type="button" class="schedule-pill"><?php echo e(t('schedule.roles')); ?></button>
        <button type="button" class="schedule-pill"><?php echo e(t('schedule.departments')); ?></button>
        <button type="button" class="schedule-pill"><?php echo e(t('schedule.coverage')); ?></button>
        <button type="button" class="schedule-pill"><?php echo e(t('schedule.occupancy')); ?></button>
        <button type="button" class="schedule-pill"><?php echo e(t('schedule.absence_types')); ?></button>
    </div>

    <div class="schedule-board">
        <div class="schedule-card is-highlight">
            <div class="schedule-card-head">
                <span class="schedule-badge"><?php echo e(t('schedule.sample_reception')); ?></span>
                <span class="schedule-color">#b98b12</span>
            </div>
            <div class="schedule-row"><span><?php echo e(t('schedule.start')); ?></span><strong>06:00</strong></div>
            <div class="schedule-row"><span><?php echo e(t('schedule.end')); ?></span><strong>14:00</strong></div>
            <div class="schedule-row"><span><?php echo e(t('schedule.icon')); ?></span><strong>🔑</strong></div>
        </div>
        <div class="schedule-card">
            <div class="schedule-card-head">
                <span class="schedule-badge"><?php echo e(t('schedule.sample_housekeeping')); ?></span>
                <span class="schedule-color">#6c7ae0</span>
            </div>
            <div class="schedule-row"><span><?php echo e(t('schedule.start')); ?></span><strong>08:00</strong></div>
            <div class="schedule-row"><span><?php echo e(t('schedule.end')); ?></span><strong>16:00</strong></div>
            <div class="schedule-row"><span><?php echo e(t('schedule.icon')); ?></span><strong>🧹</strong></div>
        </div>
        <div class="schedule-card">
            <div class="schedule-card-head">
                <span class="schedule-badge"><?php echo e(t('schedule.sample_maintenance')); ?></span>
                <span class="schedule-color">#df7b2b</span>
            </div>
            <div class="schedule-row"><span><?php echo e(t('schedule.start')); ?></span><strong>09:00</strong></div>
            <div class="schedule-row"><span><?php echo e(t('schedule.end')); ?></span><strong>17:00</strong></div>
            <div class="schedule-row"><span><?php echo e(t('schedule.icon')); ?></span><strong>🛠</strong></div>
        </div>
        <div class="schedule-card">
            <div class="schedule-card-head">
                <span class="schedule-badge"><?php echo e(t('schedule.sample_night_auditor')); ?></span>
                <span class="schedule-color">#8e67d9</span>
            </div>
            <div class="schedule-row"><span><?php echo e(t('schedule.start')); ?></span><strong>22:00</strong></div>
            <div class="schedule-row"><span><?php echo e(t('schedule.end')); ?></span><strong>06:00</strong></div>
            <div class="schedule-row"><span><?php echo e(t('schedule.icon')); ?></span><strong>🌙</strong></div>
        </div>
    </div>

    <div class="schedule-form-grid">
        <label class="schedule-field">
            <?php echo e(t('schedule.task_name')); ?>
            <input type="text" value="<?php echo e(t('schedule.sample_reception')); ?>" readonly>
        </label>
        <label class="schedule-field">
            <?php echo e(t('schedule.color')); ?>
            <input type="text" value="#b98b12" readonly>
        </label>
        <label class="schedule-field">
            <?php echo e(t('schedule.icon')); ?>
            <input type="text" value="🔑" readonly>
        </label>
        <label class="schedule-field">
            <?php echo e(t('schedule.department')); ?>
            <input type="text" value="<?php echo e(t('schedule.sample_front_office')); ?>" readonly>
        </label>
    </div>

    <div class="admin-actions settings-actions">
        <button type="button" class="admin-action-link"><?php echo e(t('schedule.create')); ?></button>
        <button type="button" class="admin-action-link"><?php echo e(t('schedule.edit')); ?></button>
        <button type="button" class="admin-action-link"><?php echo e(t('schedule.delete')); ?></button>
    </div>
    </div>
</section>
