<?php
/**
 * General notification panel: system notices addressed to the signed-in
 * account (e.g. trial renewal reminders sent by the super admin), separate
 * from the document-sharing inbox on the employee space.
 *
 * Rendered on both dashboard and my-space, for every role: works the same
 * way regardless of who is looking at it, so the JS is self-contained rather
 * than depending on assets/js/dashboard.js (which is absent on my-space).
 */
if (!isLoggedIn()) {
    return;
}
?>
<section class="dashboard-modal notifications-modal" id="modal-notifications" hidden role="dialog" aria-modal="true" aria-labelledby="notifications-modal-title">
    <div class="crud-modal-card">
        <div class="crud-modal-head">
            <h2 id="notifications-modal-title"><?php echo e(t('notifications.title')); ?></h2>
            <button type="button" class="dashboard-modal-close" data-modal-close aria-label="<?php echo e(t('common.close')); ?>">&times;</button>
        </div>
        <div class="notifications-list" data-notifications-list>
            <p class="notifications-empty" data-notifications-loading><?php echo e(t('settings.loading', ['fallback' => 'Caricamento…'])); ?></p>
        </div>
    </div>
</section>
