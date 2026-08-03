<?php
/**
 * Shared CRUD modal shell and embedded templates.
 *
 * This file includes a single modal card (`#crud-modal`) used as a shell for
 * multiple entity templates. Each `<template id="crud-template-...">`
 * contains the form and listing for an entity (companies, users, departments,
 * documents, messages). The dashboard JavaScript copies the selected template
 * into `#crud-modal-body` and wires interactive behavior.
 *
 * Required runtime data provided by the dashboard controller:
 * - `$dashboardModalCompanies` : array of companies for selects/lists
 * - `$dashboardModalDepartments` : array of departments
 * - `$dashboardModalUsers` : array of users
 * - `$dashboardModalDocuments` : array of documents
 */
if (!isLoggedIn()) {
    return;
}

$modalCurrentRole = currentUser()['role'] ?? 'employee';
$modalLocale = strtolower((string) appLocale());
$modalIsFr = str_starts_with($modalLocale, 'fr');
$modalIsIt = str_starts_with($modalLocale, 'it');

$modalRecipientsSelectedLabel = $modalIsFr
    ? 'Destinataires selectionnes'
    : ($modalIsIt ? 'Destinatari selezionati' : 'Selected recipients');

$modalRecipientsAllLabel = match ($modalCurrentRole) {
    'super_admin' => ($modalIsFr ? 'Tous les utilisateurs actifs' : ($modalIsIt ? 'Tutti gli utenti attivi' : 'All active users')),
    'admin' => ($modalIsFr ? 'Tous les collegues de votre entreprise' : ($modalIsIt ? 'Tutti i colleghi della tua azienda' : 'All colleagues in your company')),
    'department_manager' => ($modalIsFr ? 'Tous les managers de votre entreprise' : ($modalIsIt ? 'Tutti i manager della tua azienda' : 'All managers in your company')),
    default => ($modalIsFr ? 'Tous les destinataires disponibles' : ($modalIsIt ? 'Tutti i destinatari disponibili' : 'All available recipients')),
};

$modalDocumentScopeHint = match ($modalCurrentRole) {
    'super_admin' => ($modalIsFr ? 'Portee super admin: vous pouvez partager avec tout utilisateur actif.' : ($modalIsIt ? 'Ambito super admin: puoi condividere con qualsiasi utente attivo.' : 'Super admin scope: you can share with any active user.')),
    'admin' => ($modalIsFr ? 'Portee admin: partage limite a votre entreprise.' : ($modalIsIt ? 'Ambito admin: condivisione limitata alla tua azienda.' : 'Admin scope: sharing is limited to your company.')),
    'department_manager' => ($modalIsFr ? 'Portee manager: partage limite aux managers de votre entreprise.' : ($modalIsIt ? 'Ambito manager: condivisione limitata ai manager della tua azienda.' : 'Manager scope: sharing is limited to managers in your company.')),
    default => ($modalIsFr ? 'Selectionnez les destinataires autorises.' : ($modalIsIt ? 'Seleziona i destinatari consentiti.' : 'Select the allowed recipients.')),
};
$modalCanRequestSignature = in_array($modalCurrentRole, ['admin', 'department_manager'], true);
$modalCanRequestShiftCoverage = in_array($modalCurrentRole, ['admin', 'department_manager'], true);

$basePath = $basePath ?? (function () {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
?>
<div class="dashboard-overlay" id="dashboard-overlay" hidden></div>
<section class="crud-modal" id="crud-modal" hidden role="dialog" aria-modal="true" aria-labelledby="crud-modal-title">
    <div class="crud-modal-card">
        <button type="button" class="dashboard-modal-close" data-modal-close aria-label="<?php echo e(t('common.close')); ?>">&times;</button>

        <div class="crud-modal-head">
            <div>
                <h2 id="crud-modal-title"><?php echo e(t('crud.title')); ?></h2>
                <p id="crud-modal-subtitle" class="crud-modal-subtitle"></p>
            </div>
        </div>

        <div class="crud-modal-body" id="crud-modal-body">
            <div class="crud-empty-state"><?php echo e(t('crud.select_sidebar_item')); ?></div>
        </div>
    </div>
</section>

<template id="crud-template-placeholder">
    <div class="crud-panel">
        <h3><?php echo e(t('crud.common_shell')); ?></h3>
        <p><?php echo e(t('crud.shared_modal')); ?></p>
        <div class="crud-empty-state"><?php echo e(t('crud.no_template')); ?></div>
    </div>
</template>

<template id="crud-template-companies">
    <div class="crud-panel-grid crud-panel-grid-wide" data-crud-entity="companies">
        <section class="crud-panel">
            <h3 id="crud-company-form-heading"><?php echo e(t('crud.create_company')); ?></h3>
            <form method="post" action="<?php echo appUrl('companies'); ?>" class="admin-form crud-form" id="crud-company-form">
                <input type="hidden" name="action" value="create" id="crud-company-action">
                <input type="hidden" name="id" value="" id="crud-company-id">
                <label>
                    <?php echo e(t('crud.company_name')); ?>
                    <input type="text" name="name" id="crud-company-name" required>
                </label>
                <label>
                    <?php echo e(t('crud.type')); ?>
                    <select name="type" id="crud-company-type">
                        <?php foreach (['hotel', 'hospital', 'clinic', 'elderly_center', 'restaurant', 'other'] as $companyType): ?>
                            <option value="<?php echo e($companyType); ?>"><?php echo e(t('crud.company_type_' . $companyType)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?php echo e(t('crud.city')); ?>
                    <input type="text" name="city" id="crud-company-city">
                </label>
                <label>
                    <?php echo e(t('crud.address')); ?>
                    <input type="text" name="address" id="crud-company-address">
                </label>
                <label>
                    <?php echo e(t('crud.zip_code')); ?>
                    <input type="text" name="zip_code" id="crud-company-zip-code">
                </label>
                <label>
                    <?php echo e(t('crud.phone')); ?>
                    <input type="text" name="phone" id="crud-company-phone">
                </label>
                <label>
                    <?php echo e(t('crud.email')); ?>
                    <input type="email" name="email" id="crud-company-email">
                </label>
                <label>
                    <?php echo e(t('crud.logo_path')); ?>
                    <input type="text" name="logo_path" id="crud-company-logo-path" placeholder="assets/images/...">
                </label>
                <label>
                    <?php echo e(t('crud.signature_ip')); ?>
                    <input type="text" name="signature_ip" id="crud-company-signature-ip" placeholder="<?php echo e(t('crud.leave_empty_if_not_used')); ?>">
                </label>
                <div class="form-actions">
                    <button type="submit" id="crud-company-submit"><?php echo e(t('crud.create')); ?></button>
                    <button type="button" class="admin-action-link admin-action-link-secondary" data-crud-reset-company><?php echo e(t('crud.reset')); ?></button>
                </div>
            </form>
        </section>

        <section class="crud-panel">
            <h3><?php echo e(t('crud.companies_list')); ?></h3>
            <div class="company-grid">
                <?php if (empty($dashboardModalCompanies ?? [])): ?>
                    <div class="crud-empty-state"><?php echo e(t('crud.no_companies')); ?></div>
                <?php endif; ?>
                <?php foreach (($dashboardModalCompanies ?? []) as $company): ?>
                    <article class="company-card" data-company-id="<?php echo (int) $company['id']; ?>" data-company-name="<?php echo e($company['name']); ?>" data-company-type="<?php echo e($company['type'] ?? 'other'); ?>" data-company-city="<?php echo e($company['city'] ?? ''); ?>" data-company-address="<?php echo e($company['address'] ?? ''); ?>" data-company-zip-code="<?php echo e($company['zip_code'] ?? ''); ?>" data-company-phone="<?php echo e($company['phone'] ?? ''); ?>" data-company-email="<?php echo e($company['email'] ?? ''); ?>" data-company-logo-path="<?php echo e($company['logo_path'] ?? ''); ?>" data-company-signature-ip="<?php echo e($company['signature_ip'] ?? ''); ?>">
                        <header class="company-card-head">
                            <div class="company-card-title"><?php echo e($company['name']); ?></div>
                        </header>
                        <div class="company-card-actions company-card-actions--inline">
                            <button type="button" class="company-card-action" data-company-action="edit" aria-label="<?php echo e(t('crud.edit_company')); ?>">
                                <span aria-hidden="true">✎</span>
                            </button>
                            <form method="post" action="<?php echo appUrl('companies'); ?>" class="company-card-delete-form" onsubmit="return confirm('<?php echo e(t('crud.company_card_delete')); ?>');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $company['id']; ?>">
                                <button type="submit" class="company-card-action is-danger" aria-label="<?php echo e(t('crud.delete_company')); ?>">
                                    <span aria-hidden="true">×</span>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</template>

<template id="crud-template-users">
    <div class="crud-panel-grid crud-panel-grid-wide" data-crud-entity="users">
        <section class="crud-panel">
            <h3 id="crud-user-form-heading"><?php echo e(t('crud.create_user')); ?></h3>
            <form method="post" action="<?php echo appUrl('users'); ?>" class="admin-form crud-form" id="crud-user-form">
                <input type="hidden" name="action" value="create" id="crud-user-action">
                <input type="hidden" name="id" value="" id="crud-user-id">

                <label>
                    <?php echo e(t('crud.first_name')); ?>
                    <input type="text" name="first_name" id="crud-user-first-name" required>
                </label>
                <label>
                    <?php echo e(t('crud.last_name')); ?>
                    <input type="text" name="last_name" id="crud-user-last-name" required>
                </label>
                <label>
                    <?php echo e(t('crud.email')); ?>
                    <input type="email" name="email" id="crud-user-email" required>
                </label>
                <label>
                    <?php echo e(t('crud.phone')); ?>
                    <input type="text" name="phone" id="crud-user-phone">
                </label>
                <label>
                    <?php echo e(t('crud.password')); ?>
                    <input type="password" name="password" id="crud-user-password" placeholder="<?php echo e(t('crud.leave_blank_password')); ?>">
                </label>
                <label>
                    <?php echo e(t('crud.role')); ?>
                    <select name="role" id="crud-user-role">
                        <?php if ($modalCurrentRole === 'super_admin'): ?>
                            <option value="employee"><?php echo e(t('crud.role_employee')); ?></option>
                            <option value="department_manager"><?php echo e(t('crud.role_department_manager')); ?></option>
                            <option value="admin"><?php echo e(t('crud.role_admin')); ?></option>
                            <option value="super_admin"><?php echo e(t('crud.role_super_admin')); ?></option>
                        <?php elseif ($modalCurrentRole === 'admin'): ?>
                            <option value="employee"><?php echo e(t('crud.role_employee')); ?></option>
                            <option value="department_manager"><?php echo e(t('crud.role_department_manager')); ?></option>
                            <option value="admin"><?php echo e(t('crud.role_admin')); ?></option>
                        <?php else: ?>
                            <option value="employee"><?php echo e(t('crud.role_employee')); ?></option>
                        <?php endif; ?>
                    </select>
                </label>
                <label>
                    <?php echo e(t('crud.status')); ?>
                    <select name="status" id="crud-user-status">
                        <option value="active"><?php echo e(t('crud.active')); ?></option>
                        <option value="inactive"><?php echo e(t('crud.inactive')); ?></option>
                    </select>
                </label>
                <label>
                    <?php echo e(t('crud.company')); ?>
                    <select id="crud-user-company-filter">
                        <option value=""><?php echo e(t('crud.all_companies')); ?></option>
                        <?php foreach (($dashboardModalCompanies ?? []) as $company): ?>
                            <option value="<?php echo (int) $company['id']; ?>"><?php echo e($company['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?php echo e(t('crud.department')); ?>
                    <select name="department_id" id="crud-user-department-id">
                        <option value=""><?php echo e(t('crud.none')); ?></option>
                        <?php foreach (($dashboardModalDepartments ?? []) as $department): ?>
                            <option value="<?php echo (int) $department['id']; ?>" data-company-id="<?php echo (int) $department['company_id']; ?>">
                                <?php echo e(($department['company_name'] ?? '') . ' - ' . $department['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="form-actions span-2">
                    <button type="submit" id="crud-user-submit"><?php echo e(t('crud.create')); ?></button>
                    <button type="button" class="admin-action-link admin-action-link-secondary" data-crud-reset-user><?php echo e(t('crud.reset')); ?></button>
                </div>
            </form>
        </section>

        <section class="crud-panel">
            <h3><?php echo e(t('crud.users_list')); ?></h3>
            <div class="company-grid">
                <?php if (empty($dashboardModalUsers ?? [])): ?>
                    <div class="crud-empty-state"><?php echo e(t('crud.no_users')); ?></div>
                <?php endif; ?>
                <?php foreach (($dashboardModalUsers ?? []) as $user): ?>
                    <article class="company-card company-card--stacked" data-user-id="<?php echo (int) $user['id']; ?>" data-user-first-name="<?php echo e($user['first_name']); ?>" data-user-last-name="<?php echo e($user['last_name']); ?>" data-user-email="<?php echo e($user['email']); ?>" data-user-phone="<?php echo e($user['phone'] ?? ''); ?>" data-user-role="<?php echo e($user['role']); ?>" data-user-status="<?php echo e($user['status'] ?? 'active'); ?>" data-user-department-id="<?php echo (int) ($user['department_id'] ?? 0); ?>">
                        <div class="company-card-head">
                            <div class="company-card-title"><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></div>
                            <div class="company-card-meta"><?php echo e($user['department_name'] ?? t('crud.no_department')); ?></div>
                        </div>
                        <div class="company-card-actions company-card-actions--inline">
                            <span class="company-card-chip"><?php echo e($user['role']); ?></span>
                            <button type="button" class="company-card-action" data-user-action="edit" aria-label="<?php echo e(t('crud.edit_user')); ?>">
                                <span aria-hidden="true">✎</span>
                            </button>
                            <form method="post" action="<?php echo appUrl('users'); ?>" class="company-card-delete-form" onsubmit="return confirm('<?php echo e(t('crud.user_card_delete')); ?>');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                <button type="submit" class="company-card-action is-danger" aria-label="<?php echo e(t('crud.delete_user')); ?>">
                                    <span aria-hidden="true">×</span>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</template>

<template id="crud-template-departments">
    <div class="crud-panel-grid crud-panel-grid-wide" data-crud-entity="departments">
        <section class="crud-panel">
            <h3 id="crud-department-form-heading"><?php echo e(t('crud.create_department')); ?></h3>
            <form method="post" action="<?php echo appUrl('departments'); ?>" class="admin-form crud-form" id="crud-department-edit-form">
                <input type="hidden" name="action" value="create" id="crud-department-action">
                <input type="hidden" name="id" value="" id="crud-department-id">

                <label>
                    <?php echo e(t('crud.company')); ?>
                    <select name="company_id" id="crud-department-company-select" required>
                        <option value=""><?php echo e(t('crud.select_company')); ?></option>
                        <?php foreach (($dashboardModalCompanies ?? []) as $company): ?>
                            <option value="<?php echo (int) $company['id']; ?>"><?php echo e($company['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?php echo e(t('crud.department_name')); ?>
                    <input type="text" name="name" id="crud-department-name-input" required>
                </label>
                <label>
                    <?php echo e(t('crud.department_head')); ?>
                    <select name="head_user_id" id="crud-department-head-user-select">
                        <option value=""><?php echo e(t('crud.none')); ?></option>
                        <?php foreach (($dashboardModalUsers ?? []) as $user): ?>
                            <option value="<?php echo (int) $user['id']; ?>" data-company-id="<?php echo (int) ($user['company_id'] ?? 0); ?>"><?php echo e($user['first_name'] . ' ' . $user['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="span-2">
                    <?php echo e(t('crud.description')); ?>
                    <textarea name="description" id="crud-department-description-input" rows="4"></textarea>
                </label>
                <div class="form-actions span-2">
                    <button type="submit" id="crud-department-submit"><?php echo e(t('crud.create')); ?></button>
                    <button type="button" class="admin-action-link admin-action-link-secondary" data-crud-reset-department><?php echo e(t('crud.reset')); ?></button>
                </div>
            </form>
        </section>

        <section class="crud-panel">
            <h3><?php echo e(t('crud.departments_list')); ?></h3>
            <div class="company-grid">
                <?php if (empty($dashboardModalDepartments ?? [])): ?>
                    <div class="crud-empty-state"><?php echo e(t('crud.no_departments')); ?></div>
                <?php endif; ?>
                <?php foreach (($dashboardModalDepartments ?? []) as $department): ?>
                    <article class="company-card company-card--stacked" data-department-id="<?php echo (int) $department['id']; ?>" data-department-company-id="<?php echo (int) $department['company_id']; ?>" data-department-company-name="<?php echo e($department['company_name'] ?? ''); ?>" data-department-name="<?php echo e($department['name']); ?>" data-department-description="<?php echo e($department['description'] ?? ''); ?>" data-department-head-user-id="<?php echo (int) ($department['head_user_id'] ?? 0); ?>">
                        <div class="company-card-head">
                            <div class="company-card-title"><?php echo e($department['name']); ?></div>
                            <div class="company-card-meta"><?php echo e($department['company_name'] ?? ''); ?></div>
                        </div>
                        <div class="company-card-actions company-card-actions--inline">
                            <span class="company-card-chip"><?php echo e($department['head_user_name'] ?? t('crud.no_department')); ?></span>
                            <button type="button" class="company-card-action" data-department-action="edit" aria-label="<?php echo e(t('crud.edit_department')); ?>">
                                <span aria-hidden="true">✎</span>
                            </button>
                            <form method="post" action="<?php echo appUrl('departments'); ?>" class="company-card-delete-form" onsubmit="return confirm('<?php echo e(t('crud.department_card_delete')); ?>');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $department['id']; ?>">
                                <button type="submit" class="company-card-action is-danger" aria-label="<?php echo e(t('crud.delete_department')); ?>">
                                    <span aria-hidden="true">×</span>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</template>

<template id="crud-template-documents">
    <div class="crud-panel-grid crud-panel-grid-wide" data-crud-entity="documents">
        <section class="crud-panel">
            <h3><?php echo e(t('crud.documents_overview')); ?></h3>
            <p class="crud-modal-subtitle"><?php echo e(t('crud.documents_hint')); ?></p>
            <form class="admin-form crud-form" id="crud-document-share-form" enctype="multipart/form-data">
                <label class="span-2">
                    <?php echo e(t('crud.document')); ?>
                    <input type="file" id="crud-document-file">
                </label>
                <label class="span-2 crud-inline-choice" for="crud-document-share-now">
                    <input type="checkbox" id="crud-document-share-now" value="1" checked>
                    <span class="company-card-chip"><?php echo e(t('crud.share_now')); ?></span>
                </label>
                <label>
                    <?php echo e(t('crud.request_type')); ?>
                    <select id="crud-document-request-type">
                        <option value="notification"><?php echo e(t('crud.document_notification')); ?></option>
                        <?php if ($modalCanRequestShiftCoverage): ?>
                            <option value="shift_coverage"><?php echo e(t('crud.shift_coverage_request')); ?></option>
                        <?php endif; ?>
                    </select>
                </label>
                <label class="crud-document-shift-row is-hidden" data-document-shift-row>
                    <?php echo e(t('crud.shift_to_cover')); ?>
                    <select id="crud-document-shift-id">
                        <option value=""><?php echo e(t('crud.select_shift')); ?></option>
                        <?php foreach (($dashboardModalOpenShiftChoices ?? []) as $shiftChoice): ?>
                            <option value="<?php echo (int) ($shiftChoice['id'] ?? 0); ?>">
                                <?php echo e((string) (($shiftChoice['department_name'] ?? '') . ' - ' . ($shiftChoice['name'] ?? 'Shift') . ' (' . substr((string) ($shiftChoice['start_time'] ?? '00:00:00'), 0, 5) . '-' . substr((string) ($shiftChoice['end_time'] ?? '00:00:00'), 0, 5) . ')')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?php echo e(t('crud.recipients')); ?>
                    <select id="crud-document-recipient-scope">
                        <option value="selected"><?php echo e($modalRecipientsSelectedLabel); ?></option>
                        <option value="all"><?php echo e($modalRecipientsAllLabel); ?></option>
                    </select>
                </label>
                <p class="crud-modal-subtitle span-2"><?php echo e($modalDocumentScopeHint); ?></p>
                <label class="span-2" id="crud-document-recipient-label">
                    <?php echo e(t('crud.recipients')); ?>
                    <select id="crud-document-recipient-ids" multiple size="6">
                        <?php foreach (($dashboardModalDocumentRecipients ?? $dashboardModalUsers ?? []) as $user): ?>
                            <?php
                                $recipientRole = (string) ($user['role'] ?? 'employee');
                                $recipientLabel = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
                                if ($recipientLabel === '') {
                                    $recipientLabel = 'User #' . (int) ($user['id'] ?? 0);
                                }
                                $recipientRoleLabel = match ($recipientRole) {
                                    'super_admin' => 'Super Admin',
                                    'admin' => 'Admin',
                                    'department_manager' => 'Manager',
                                    default => 'Employee',
                                };
                            ?>
                            <option value="<?php echo (int) $user['id']; ?>"
                                    data-role="<?php echo e($recipientRole); ?>"
                                    data-department-id="<?php echo (int) ($user['department_id'] ?? 0); ?>">
                                <?php echo e($recipientLabel); ?>
                                (<?php echo e($recipientRoleLabel); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($modalCanRequestSignature): ?>
                    <label class="span-2 crud-inline-choice" for="crud-document-require-signature">
                        <input type="checkbox" id="crud-document-require-signature" value="1">
                        <span class="company-card-chip"><?php echo e(t('crud.require_digital_signature')); ?></span>
                    </label>
                <?php endif; ?>
                <label class="span-2">
                    <?php echo e(t('crud.title2')); ?>
                    <input type="text" id="crud-document-title" maxlength="255" placeholder="<?php echo e(t('crud.document_title_placeholder')); ?>">
                </label>
                <label class="span-2">
                    <?php echo e(t('crud.message')); ?>
                    <textarea id="crud-document-message" rows="3" placeholder="<?php echo e(t('crud.document_message_placeholder')); ?>"></textarea>
                </label>
                <div class="form-actions span-2">
                    <button type="button" id="crud-document-share-existing-submit" class="admin-action-link admin-action-link-secondary"><?php echo e(t('crud.send_selected_document')); ?></button>
                </div>
            </form>
        </section>

        <section class="crud-panel">
            <h3><?php echo e(t('crud.documents_list')); ?></h3>
            <div class="company-grid">
                <?php if (empty($dashboardModalDocuments ?? [])): ?>
                    <div class="crud-empty-state"><?php echo e(t('crud.no_documents')); ?></div>
                <?php endif; ?>
                <?php foreach (($dashboardModalDocuments ?? []) as $document): ?>
                    <?php
                        $documentFileName = (string) ($document['file_name'] ?? t('crud.document'));
                        $documentExtension = strtoupper((string) pathinfo($documentFileName, PATHINFO_EXTENSION));
                        $documentSignedPage = (int) ($document['signed_page'] ?? 0);
                        $documentMimeType = strtolower((string) ($document['file_mime_type'] ?? ''));
                        $documentIsPdf = str_contains($documentMimeType, 'pdf') || strtolower((string) pathinfo($documentFileName, PATHINFO_EXTENSION)) === 'pdf';
                        $documentPreviewHref = appUrl('document-download', ['id' => (int) $document['id'], 'preview' => '1']);
                        if ($documentSignedPage > 0 && $documentIsPdf) {
                            $documentPreviewHref .= '#page=' . $documentSignedPage;
                        }
                        $documentThumbnail = function_exists('documentThumbnailDataUrl')
                            ? documentThumbnailDataUrl($document, 220, 120)
                            : null;
                        if ($documentExtension === '') {
                            $documentExtension = 'DOC';
                        }
                    ?>
                    <article class="company-card company-card--stacked">
                        <div class="document-preview-vignette" aria-hidden="true">
                            <?php if (is_string($documentThumbnail) && $documentThumbnail !== ''): ?>
                                <img src="<?php echo e($documentThumbnail); ?>" alt="" class="document-preview-vignette-image" loading="lazy">
                            <?php else: ?>
                                <span><?php echo e($documentExtension); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="company-card-head">
                            <div class="company-card-title"><?php echo e($document['file_name'] ?? t('crud.document')); ?></div>
                            <div class="company-card-meta"><?php echo e(($document['first_name'] ?? '') . ' ' . ($document['last_name'] ?? '')); ?> • <?php echo e($document['upload_date'] ?? ''); ?></div>
                        </div>
                        <div class="company-card-actions company-card-actions--inline">
                            <span class="company-card-chip"><?php echo e($document['document_type'] ?? t('crud.other')); ?></span>
                            <span class="company-card-chip" data-document-status-chip><?php echo e($document['status'] ?? t('crud.pending')); ?></span>
                            <?php if (!empty($document['signed_at'])): ?>
                                <span class="company-card-chip"><?php echo e(t('crud.signed')); ?><?php if (!empty($document['signed_page'])): ?> <?php echo e('P.' . (int) $document['signed_page']); ?><?php endif; ?></span>
                            <?php endif; ?>
                            <a class="company-card-action" target="_blank" rel="noopener" href="<?php echo e($documentPreviewHref); ?>" title="<?php echo e(t('crud.preview', ['fallback' => 'Preview'])); ?>">
                                <img src="<?php echo $basePath; ?>/assets/icons/document.svg" alt="" aria-hidden="true" class="company-card-action-icon">
                            </a>
                            <a class="company-card-action" href="<?php echo appUrl('document-download', ['id' => (int) $document['id']]); ?>" title="<?php echo e(t('crud.download_document')); ?>">
                                <img src="<?php echo $basePath; ?>/assets/icons/circle-arrow-out-up-left.svg" alt="" aria-hidden="true" class="company-card-action-icon">
                            </a>
                            <a class="company-card-action" target="_blank" rel="noopener" href="<?php echo appUrl('document-download', ['id' => (int) $document['id'], 'disposition' => 'inline', 'print_preview' => '1']); ?>" title="<?php echo e(t('employee.print_document', ['fallback' => 'Print'])); ?>">
                                <img src="<?php echo $basePath; ?>/assets/icons/printer.svg" alt="" aria-hidden="true" class="company-card-action-icon">
                            </a>
                            <?php if (in_array($modalCurrentRole, ['super_admin', 'admin', 'department_manager'], true)): ?>
                                <button type="button"
                                        class="company-card-action"
                                        title="<?php echo e(t('crud.share_select_title')); ?>"
                                        data-document-share-select-title="<?php echo e(t('crud.share_select_title')); ?>"
                                        data-document-share-unselect-title="<?php echo e(t('crud.share_unselect_title')); ?>"
                                        data-document-share-existing-id="<?php echo (int) ($document['id'] ?? 0); ?>"
                                        data-document-share-existing-name="<?php echo e($document['file_name'] ?? 'Document'); ?>">
                                    <span aria-hidden="true" data-document-share-select-icon>+</span>
                                </button>
                            <?php endif; ?>
                            <?php if (in_array($modalCurrentRole, ['super_admin', 'admin', 'department_manager'], true)): ?>
                                <button type="button"
                                        class="company-card-action"
                                        title="<?php echo e(t('employee.sign_document', ['fallback' => 'Sign document'])); ?>"
                                        data-dashboard-document-sign-open
                                        data-dashboard-document-sign-id="<?php echo (int) ($document['id'] ?? 0); ?>"
                                        data-dashboard-document-sign-name="<?php echo e((string) ($document['file_name'] ?? t('crud.document'))); ?>"
                                        data-dashboard-document-sign-mime-type="<?php echo e((string) ($document['file_mime_type'] ?? '')); ?>"
                                        data-dashboard-document-sign-preview-url="<?php echo appUrl('document-download', ['id' => (int) ($document['id'] ?? 0), 'preview' => '1', 'embed' => '1']); ?>"
                                        <?php if ((string) ($document['status'] ?? 'valid') === 'archived'): ?>hidden<?php endif; ?>>
                                    <img src="<?php echo $basePath; ?>/assets/icons/signature.svg" alt="" aria-hidden="true" class="company-card-action-icon">
                                </button>
                            <?php endif; ?>
                            <?php if (in_array($modalCurrentRole, ['super_admin', 'admin', 'department_manager'], true)): ?>
                                <button type="button"
                                        class="company-card-action is-danger"
                                        title="<?php echo e(t('crud.delete_document')); ?>"
                                        data-document-delete-id="<?php echo (int) ($document['id'] ?? 0); ?>"
                                        data-document-delete-name="<?php echo e($document['file_name'] ?? 'Document'); ?>">
                                    <span aria-hidden="true">×</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

    </div>
</template>