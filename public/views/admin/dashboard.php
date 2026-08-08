<!-- Shared dashboard: sidebar navigation and centered modals by logged-in role. -->
<?php
$currentUser = currentUser();
$role = $currentUser['role'] ?? 'employee';
$profile = $profile ?? [];
$moduleRows = $moduleRows ?? [];
$dashboardPlannerData = isset($dashboardPlannerData) && is_array($dashboardPlannerData) ? $dashboardPlannerData : [];
$roleLabels = [
    'super_admin' => t('roles.super_admin'),
    'admin' => t('roles.admin'),
    'department_manager' => t('roles.department_manager'),
    'employee' => t('roles.employee'),
];
$requestStatusLabels = [
    'pending' => t('employee.status_pending', ['fallback' => 'Pending']),
    'approved' => t('employee.status_approved', ['fallback' => 'Approved']),
    'rejected' => t('employee.status_rejected', ['fallback' => 'Rejected']),
];
$requestTypeLabels = [
    'notification' => t('common.notification', ['fallback' => 'Notification']),
    'leave' => t('common.leave', ['fallback' => 'Leave']),
    'shift_change' => t('common.shift_change', ['fallback' => 'Shift change']),
    'other' => t('common.other', ['fallback' => 'Other']),
    'admin_note' => t('common.admin_note', ['fallback' => 'Admin note']),
];
$basePath = $basePath ?? (function () {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
$resolveCompanyLogoUrl = static function (?string $rawPath) use ($basePath): string {
    $path = trim((string) $rawPath);
    if ($path === '') {
        return '';
    }
    if (preg_match('/^(https?:)?\/\//i', $path) || str_starts_with($path, 'data:')) {
        return $path;
    }

    if (str_starts_with($path, 'uploads/')) {
        $path = 'public/' . $path;
    }

    return rtrim($basePath, '/') . '/' . ltrim($path, '/');
};
$selectedCompanyId = (int) ($_GET['settings_company_id'] ?? ($dashboardPlannerData['company']['id'] ?? 0));
$isSuperAdminCompanyDashboard = ($role === 'super_admin' && $selectedCompanyId > 0);
$plannerCompany = is_array($dashboardPlannerData['company'] ?? null) ? $dashboardPlannerData['company'] : [];
$plannerCompanyName = trim((string) ($plannerCompany['name'] ?? ''));
$plannerCompanyLogoUrl = $resolveCompanyLogoUrl((string) ($plannerCompany['logo_path'] ?? ''));

?>

<section class="admin-shell dashboard-shell" aria-label="<?php echo e(t('common.dashboard')); ?>">
    <header class="admin-hero">
    </header>

        <section class="dashboard-main" aria-label="<?php echo e(t('common.quick_actions')); ?>">
            <?php if ($role === 'super_admin' && !$isSuperAdminCompanyDashboard): ?>
                <?php if (!empty($dashboardPendingApprovals ?? [])): ?>
                    <section class="admin-card dashboard-approvals-section" data-approvals-section>
                        <h2 class="dashboard-approvals-title">
                            <?php echo e(t('approval.pending_list_title')); ?>
                            <span class="dashboard-approvals-count"><?php echo count($dashboardPendingApprovals); ?></span>
                        </h2>

                        <?php foreach ($dashboardPendingApprovals as $request): ?>
                            <?php
                                $requestContact = trim((string) (($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? '')));
                                $requestTypeKey = (string) ($request['company_type'] ?? 'other');
                                $requestDetails = array_filter([
                                    t('mail.field_type') => t('crud.company_type_' . $requestTypeKey) !== 'crud.company_type_' . $requestTypeKey
                                        ? t('crud.company_type_' . $requestTypeKey) : $requestTypeKey,
                                    t('mail.field_vat') => (string) ($request['vat_number'] ?? ''),
                                    t('mail.field_address') => trim(implode(' ', array_filter([
                                        (string) ($request['address'] ?? ''),
                                        (string) ($request['zip_code'] ?? ''),
                                        (string) ($request['city'] ?? ''),
                                        (string) ($request['province'] ?? '') !== '' ? '(' . $request['province'] . ')' : '',
                                    ]))),
                                    t('mail.field_company_email') => (string) ($request['company_email'] ?? ''),
                                    t('mail.field_phone') => (string) ($request['company_phone'] ?? ''),
                                    t('mail.field_contact') => $requestContact,
                                    t('mail.field_contact_role') => (string) ($request['contact_role'] ?? ''),
                                    t('mail.field_email') => (string) ($request['contact_email'] ?? ''),
                                    t('mail.field_contact_phone') => (string) ($request['contact_phone'] ?? ''),
                                ], static fn($value) => trim((string) $value) !== '');
                            ?>
                            <article class="dashboard-approval-card" data-approval-row data-company-id="<?php echo (int) $request['company_id']; ?>">
                                <div class="dashboard-approval-head">
                                    <h3><?php echo e($request['company_name']); ?></h3>
                                    <span class="dashboard-approval-date">
                                        <?php echo e(t('approval.requested_at')); ?>
                                        <?php echo e(!empty($request['requested_at']) ? date('d/m/Y H:i', strtotime((string) $request['requested_at'])) : '-'); ?>
                                    </span>
                                </div>

                                <dl class="dashboard-approval-details">
                                    <?php foreach ($requestDetails as $detailLabel => $detailValue): ?>
                                        <dt><?php echo e($detailLabel); ?></dt>
                                        <dd><?php echo e($detailValue); ?></dd>
                                    <?php endforeach; ?>
                                </dl>

                                <div class="dashboard-approval-actions">
                                    <button type="button"
                                            class="admin-action-link dashboard-approval-decide"
                                            data-company-id="<?php echo (int) $request['company_id']; ?>"
                                            data-decision="approve"
                                            data-confirm-message="<?php echo e(t('approval.approve') . ' - ' . $request['company_name']); ?>">
                                        <?php echo e(t('approval.approve')); ?>
                                    </button>
                                    <button type="button"
                                            class="admin-action-link admin-action-link-secondary dashboard-approval-decide"
                                            data-company-id="<?php echo (int) $request['company_id']; ?>"
                                            data-decision="reject"
                                            data-confirm-message="<?php echo e(t('approval.reject') . ' - ' . $request['company_name']); ?>">
                                        <?php echo e(t('approval.reject')); ?>
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                <section class="admin-card company-directory-section">

                    <div class="dashboard-company-grid">
                        <?php if (empty($moduleRows['company_directory'] ?? [])): ?>
                            <p><?php echo e(t('common.no_companies_to_display')); ?></p>
                        <?php endif; ?>

                        <?php foreach (($moduleRows['company_directory'] ?? []) as $company): ?>
                            <?php $companyLogoUrl = $resolveCompanyLogoUrl((string) ($company['logo_path'] ?? '')); ?>
                            <a class="dashboard-company-card-link" href="<?php echo e(appUrl('dashboard', ['settings_company_id' => (int) ($company['id'] ?? 0)])); ?>">
                            <article class="dashboard-company-card">
                                <div class="dashboard-company-card-head">
                                    <div>
                                        <h3><?php echo e($company['name']); ?></h3>
                                        <p><?php echo e($company['city'] ?? '-'); ?></p>
                                    </div>
                                    <?php if ($companyLogoUrl !== ''): ?>
                                        <img src="<?php echo e($companyLogoUrl); ?>" alt="<?php echo e($company['name']); ?> logo" class="dashboard-company-logo" loading="lazy" decoding="async">
                                    <?php endif; ?>
                                </div>

                                <div class="dashboard-company-metrics">
                                    <div><span><?php echo e(t('common.users')); ?></span><strong><?php echo e($company['users_count'] ?? 0); ?></strong></div>
                                    <div><span><?php echo e(t('common.departments')); ?></span><strong><?php echo e($company['departments_count'] ?? 0); ?></strong></div>
                                    <div><span><?php echo e(t('settings.active_connections', ['fallback' => 'Connessioni attive'])); ?></span><strong><?php echo e($company['active_connections'] ?? 0); ?></strong></div>
                                    <div><span><?php echo e(t('common.signature_ip')); ?></span><strong><?php echo e($company['signature_ip'] ?: '-'); ?></strong></div>
                                </div>

                                <p><strong><?php echo e(t('common.admins')); ?>:</strong> <?php echo e(empty($company['admins']) ? t('common.none') : implode(', ', $company['admins'])); ?></p>
                                <p><strong><?php echo e(t('common.department_heads')); ?>:</strong> <?php echo e(empty($company['heads']) ? t('common.none') : implode(', ', $company['heads'])); ?></p>
                                <p><strong><?php echo e(t('common.departments')); ?>:</strong> <?php echo e(empty($company['departments']) ? t('common.none') : implode(', ', $company['departments'])); ?></p>
                                <p><strong><?php echo e(t('settings.connected_users', ['fallback' => 'Utenti connessi'])); ?>:</strong> <?php echo e(empty($company['connected_users']) ? t('common.none') : implode(', ', $company['connected_users'])); ?></p>
                                <?php
                                    // Connection recency and trial countdown: with no automatic
                                    // expiry job the super admin needs to see both at a glance.
                                    $lastConnectionRaw = (string) ($company['last_connection_at'] ?? '');
                                    $lastConnectionTs = $lastConnectionRaw !== '' ? strtotime($lastConnectionRaw) : false;
                                    $hoursSinceLogin = $lastConnectionTs !== false
                                        ? (int) floor((time() - $lastConnectionTs) / 3600)
                                        : null;

                                    $trialEndsRaw = $company['trial_ends_at'] ?? null;
                                    $trialEndsTs = $trialEndsRaw !== null && $trialEndsRaw !== '' ? strtotime((string) $trialEndsRaw) : false;
                                    if ($trialEndsTs === false) {
                                        $trialLabel = t('mail.trial_none');
                                        $trialState = 'none';
                                    } else {
                                        $daysLeft = (int) ceil(($trialEndsTs - time()) / 86400);
                                        $trialLabel = $daysLeft >= 0
                                            ? t('mail.trial_days_left', ['days' => (string) $daysLeft])
                                            : t('mail.trial_over', ['days' => (string) abs($daysLeft)]);
                                        $trialState = $daysLeft < 0 ? 'over' : ($daysLeft <= 7 ? 'soon' : 'ok');
                                    }
                                ?>
                                <p><strong><?php echo e(t('settings.last_connection', ['fallback' => 'Ultima connessione'])); ?>:</strong>
                                    <?php echo e($lastConnectionTs !== false ? date('d/m/Y H:i', $lastConnectionTs) : '-'); ?>
                                    <?php if ($lastConnectionTs !== false): ?>
                                        <span class="dashboard-company-since">(<?php echo e(timeAgo($lastConnectionRaw)); ?>)</span>
                                    <?php endif; ?>
                                </p>
                                <p><strong><?php echo e(t('mail.field_hours_since_login')); ?>:</strong>
                                    <?php echo e($hoursSinceLogin !== null ? $hoursSinceLogin . ' h' : '-'); ?></p>
                                <p><strong><?php echo e(t('mail.field_trial_status')); ?>:</strong>
                                    <span class="dashboard-company-trial is-<?php echo e($trialState); ?>"><?php echo e($trialLabel); ?></span>
                                    <?php if ($trialEndsTs !== false): ?>
                                        <span class="dashboard-company-since">(<?php echo e(date('d/m/Y', $trialEndsTs)); ?>)</span>
                                    <?php endif; ?>
                                </p>

                                <div class="dashboard-company-trial-edit" data-company-trial-edit>
                                    <label>
                                        <span><?php echo e(t('mail.trial_edit_label')); ?></span>
                                        <input type="date"
                                               data-trial-date
                                               value="<?php echo e($trialEndsTs !== false ? date('Y-m-d', $trialEndsTs) : ''); ?>"
                                               <?php echo $trialEndsTs === false ? 'disabled' : ''; ?>>
                                    </label>
                                    <button type="button"
                                            class="admin-action-link admin-action-link-secondary dashboard-company-trial-no-expiry-toggle<?php echo $trialEndsTs === false ? ' is-active' : ''; ?>"
                                            data-trial-no-expiry
                                            data-no-expiry="<?php echo $trialEndsTs === false ? '1' : '0'; ?>"
                                            aria-pressed="<?php echo $trialEndsTs === false ? 'true' : 'false'; ?>">
                                        <?php echo e(t('mail.trial_no_expiry_label')); ?>
                                    </button>
                                    <button type="button"
                                            class="admin-action-link admin-action-link-secondary dashboard-company-trial-save"
                                            data-company-id="<?php echo (int) ($company['id'] ?? 0); ?>">
                                        <?php echo e(t('mail.trial_save')); ?>
                                    </button>
                                </div>

                                <div class="dashboard-company-notice" data-company-notice>
                                    <label>
                                        <span><?php echo e(t('mail.notice_section_title')); ?></span>
                                        <select data-notice-template>
                                            <?php foreach (companyNoticeTemplates() as $noticeTemplate): ?>
                                                <option value="<?php echo e($noticeTemplate); ?>"><?php echo e(t('mail.tpl_' . $noticeTemplate)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button type="button"
                                            class="admin-action-link dashboard-company-notice-send"
                                            data-company-id="<?php echo (int) ($company['id'] ?? 0); ?>"
                                            data-confirm-message="<?php echo e(t('mail.notice_send') . ' - ' . ($company['name'] ?? '')); ?>">
                                        <?php echo e(t('mail.notice_send')); ?>
                                    </button>
                                </div>

                                <div class="dashboard-company-card-actions">
                                    <button type="button"
                                            class="admin-action-link admin-action-link-secondary dashboard-company-toggle-active"
                                            data-company-id="<?php echo (int) ($company['id'] ?? 0); ?>"
                                            data-company-active="<?php echo (int) ($company['is_active'] ?? 1); ?>"
                                            data-label-when-active="<?php echo e(t('settings.company_deactivate')); ?>"
                                            data-label-when-inactive="<?php echo e(t('settings.company_activate')); ?>">
                                        <?php echo ((int) ($company['is_active'] ?? 1) === 1) ? e(t('settings.company_deactivate')) : e(t('settings.company_activate')); ?>
                                    </button>
                                    <button type="button"
                                            class="admin-action-link admin-action-link-secondary dashboard-company-connections-toggle"
                                            data-company-id="<?php echo (int) ($company['id'] ?? 0); ?>"
                                            aria-expanded="false">
                                        <?php echo e(t('settings.company_connections_toggle', ['fallback' => 'Connessioni'])); ?>
                                    </button>
                                </div>

                                <div class="dashboard-company-connections-panel"
                                     data-company-connections-panel
                                     data-label-loading="<?php echo e(t('settings.loading', ['fallback' => 'Caricamento…'])); ?>"
                                     data-label-empty="<?php echo e(t('common.none')); ?>"
                                     data-label-error="<?php echo e(t('common.error', ['fallback' => 'Errore'])); ?>"
                                     data-label-active="<?php echo e(t('settings.active', ['fallback' => 'Active'])); ?>"
                                     data-label-done="<?php echo e(t('common.done')); ?>"
                                     data-label-delete="<?php echo e(t('schedule.delete')); ?>"
                                     data-confirm-delete="<?php echo e(t('settings.confirm_delete_connection')); ?>"
                                     hidden>
                                    <div class="table-wrap">
                                        <table class="admin-table">
                                            <thead>
                                                <tr>
                                                    <th><?php echo e(t('settings.user_label', ['fallback' => 'Utente'])); ?></th>
                                                    <th><?php echo e(t('common.department')); ?></th>
                                                    <th><?php echo e(t('settings.time_ago_label', ['fallback' => 'Tempo trascorso'])); ?></th>
                                                    <th><?php echo e(t('common.status')); ?></th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody data-company-connections-body>
                                                <tr class="dashboard-company-connections-placeholder">
                                                    <td colspan="5"><?php echo e(t('settings.loading', ['fallback' => 'Caricamento…'])); ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </article>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php elseif ($role === 'admin' || $role === 'department_manager' || $isSuperAdminCompanyDashboard): ?>
                <?php if ($isSuperAdminCompanyDashboard): ?>
                    <section class="admin-card dashboard-directory-card">
                        <div class="dashboard-company-card-head">
                            <div>
                                <h3><?php echo e($plannerCompanyName !== '' ? $plannerCompanyName : t('common.company', ['fallback' => 'Company'])); ?></h3>
                            </div>
                            <?php if ($plannerCompanyLogoUrl !== ''): ?>
                                <img src="<?php echo e($plannerCompanyLogoUrl); ?>" alt="<?php echo e($plannerCompanyName !== '' ? $plannerCompanyName : 'Company'); ?> logo" class="dashboard-company-logo" loading="lazy" decoding="async">
                            <?php endif; ?>
                        </div>
                        <div class="settings-inline-actions" style="margin-top: 0.55rem;">
                            <a class="admin-action-link admin-action-link-secondary" href="<?php echo e(appUrl('dashboard', ['view' => 'directory'])); ?>"><?php echo e(t('common.back_to_dashboard')); ?></a>
                        </div>
                    </section>
                <?php endif; ?>
                <section class="admin-card dashboard-calendar-shell">
                    <div class="dashboard-calendar-headline">
                        <div class="dashboard-calendar-headline-main">
                            <div class="dashboard-calendar-top-row">
                                <div class="dashboard-calendar-range-actions">
                                    <input type="text" class="dashboard-calendar-navigator-range" value="<?php echo e(date('d/m/y')); ?> - <?php echo e(date('d/m/y')); ?>" readonly data-calendar-range-display>
                                    <button type="button" class="dashboard-calendar-navigator-action is-square" data-calendar-nav="today"><?php echo e(date('d/m/Y', strtotime((string) ($dashboardCalendarToday ?? date('Y-m-d'))))); ?></button>
                                </div>
                                <div class="dashboard-calendar-title-nav">
                                    <button type="button" class="dashboard-calendar-navigator-action" data-calendar-nav="prev" aria-label="Prev">‹</button>
                                    <h2 class="dashboard-calendar-title" data-calendar-title><?php echo e($dashboardCalendarScopeLabel ?? t('common.calendar')); ?></h2>
                                    <button type="button" class="dashboard-calendar-navigator-action" data-calendar-nav="next" aria-label="Next">›</button>
                                </div>
                                <p class="dashboard-calendar-title-meta" data-calendar-stats><?php echo e(date('d M Y')); ?></p>
                                <div class="dashboard-calendar-navigator-actions is-inline">
                                    <button type="button" class="dashboard-calendar-navigator-pill" data-calendar-mode="day"><?php echo e(t('common.one_day', ['fallback' => '1 day'])); ?></button>
                                    <button type="button" class="dashboard-calendar-navigator-pill" data-calendar-mode="week"><?php echo e(t('common.seven_days')); ?></button>
                                    <button type="button" class="dashboard-calendar-navigator-pill is-active" data-calendar-mode="fortnight"><?php echo e(t('common.fifteen_days')); ?></button>
                                    <button type="button" class="dashboard-calendar-navigator-pill" data-calendar-mode="month"><?php echo e(t('common.one_month')); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-calendar-frame">
                        <div
                            class="dashboard-calendar-grid"
                            data-dashboard-calendar-shell
                            data-calendar-mode="<?php echo e($dashboardCalendarMode ?? 'week'); ?>"
                            data-calendar-today="<?php echo e($dashboardCalendarToday ?? date('Y-m-d')); ?>"
                            data-calendar-events="<?php echo e(json_encode($dashboardCalendarEvents ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
                        ></div>
                    </div>
                </section>
            <?php else: ?>
                <section class="admin-card">
                    <h2><?php echo e(t('common.my_shifts')); ?></h2>
                    <div class="table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th><?php echo e(t('common.date', ['fallback' => 'Date'])); ?></th>
                                    <th><?php echo e(t('common.shift')); ?></th>
                                    <th><?php echo e(t('common.department')); ?></th>
                                    <th><?php echo e(t('common.status')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($moduleRows['shifts'] ?? [])): ?>
                                    <tr>
                                        <td colspan="4"><?php echo e(t('common.no_shifts_available')); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach (($moduleRows['shifts'] ?? []) as $shift): ?>
                                    <tr>
                                        <td><?php echo e($shift['work_date']); ?></td>
                                        <td><?php echo e($shift['shift_name']); ?></td>
                                        <td><?php echo e($shift['department_name']); ?></td>
                                        <td><?php echo e($shift['status']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
    </section>
</section>

