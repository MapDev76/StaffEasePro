<?php

/**
 * Transactional email through the Brevo REST API (v3), called directly with cURL.
 *
 * Every public helper here is fire-and-forget: a mail failure must never break
 * the user action that triggered it (creating a user, saving a shift, ...), so
 * failures are logged and swallowed. Callers get a bool back if they care.
 */

/**
 * Loads config/mail.php once, falling back to a disabled configuration when the
 * file is missing (fresh checkout without credentials).
 */
function mailConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'enabled' => false,
        'api_key' => '',
        'sender_name' => 'StaffEase Pro',
        'sender_email' => '',
        'reply_to_email' => '',
        'app_base_url' => '',
        'timeout' => 8,
        'log_file' => '',
        'notify' => [],
    ];

    $path = __DIR__ . '/../config/mail.php';
    if (!is_file($path)) {
        return $config = $defaults;
    }

    try {
        $loaded = require $path;
    } catch (Throwable $e) {
        return $config = $defaults;
    }

    if (!is_array($loaded)) {
        return $config = $defaults;
    }

    return $config = array_merge($defaults, $loaded);
}

/**
 * True when a given notification type should produce an email.
 */
function mailNotificationEnabled(string $type): bool
{
    $config = mailConfig();
    if (empty($config['enabled'])) {
        return false;
    }
    if (trim((string) $config['api_key']) === '' || trim((string) $config['sender_email']) === '') {
        return false;
    }

    $notify = is_array($config['notify'] ?? null) ? $config['notify'] : [];

    return (bool) ($notify[$type] ?? true);
}

/**
 * Appends a single line to the configured mail log, if any.
 */
function mailLog(string $message): void
{
    $logFile = trim((string) (mailConfig()['log_file'] ?? ''));
    if ($logFile === '') {
        return;
    }

    try {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    } catch (Throwable $e) {
        // Logging must never throw.
    }
}

/**
 * Absolute URL for a route, for use inside emails (relative links are useless
 * in a mail client). Falls back to appUrl() when no base URL is configured.
 */
function mailAppUrl(string $route, array $params = []): string
{
    $base = rtrim((string) (mailConfig()['app_base_url'] ?? ''), '/');
    $path = function_exists('appUrl') ? appUrl($route, $params) : ('/?route=' . rawurlencode($route));

    if ($base === '') {
        return $path;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return $base . '/' . ltrim($path, '/');
}

/**
 * Absolute URL for a static asset referenced inside an email.
 *
 * Mail clients fetch images over the public internet, so this only resolves to
 * something loadable once app_base_url points at a publicly reachable host.
 * Returns '' when no base URL is configured, and callers then fall back to the
 * text wordmark instead of emitting a broken image.
 */
function mailAssetUrl(string $relativePath): string
{
    $base = rtrim((string) (mailConfig()['app_base_url'] ?? ''), '/');
    if ($base === '') {
        return '';
    }

    return $base . '/' . ltrim($relativePath, '/');
}

/**
 * Logo shown in the email header.
 *
 * Mail clients fetch images from the public internet, so during local
 * development app_base_url (localhost) produces a URL nobody can load. Set
 * logo_url in config/mail.php to an absolute, publicly reachable address to
 * override it; logo_path is only used as the relative fallback.
 */
function mailLogoUrl(): string
{
    $absolute = trim((string) (mailConfig()['logo_url'] ?? ''));
    if ($absolute !== '' && preg_match('#^https?://#i', $absolute)) {
        return $absolute;
    }

    $configured = trim((string) (mailConfig()['logo_path'] ?? ''));

    return mailAssetUrl($configured !== '' ? $configured : 'assets/images/email-logo.png');
}

/**
 * Low-level Brevo call: POST https://api.brevo.com/v3/smtp/email
 *
 * @param array $to        List of ['email' => ..., 'name' => ...]
 * @param array $extra     Optional overrides (cc, bcc, tags, ...)
 * @return array{ok:bool,status:int,body:string,error:string}
 */
function brevoSendEmail(array $to, string $subject, string $htmlContent, string $textContent = '', array $extra = []): array
{
    $config = mailConfig();
    $result = ['ok' => false, 'status' => 0, 'body' => '', 'error' => ''];

    if (!function_exists('curl_init')) {
        $result['error'] = 'cURL extension is not available';
        mailLog('SKIP (no curl): ' . $subject);
        return $result;
    }

    $recipients = [];
    foreach ($to as $recipient) {
        $email = trim((string) ($recipient['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $entry = ['email' => $email];
        $name = trim((string) ($recipient['name'] ?? ''));
        if ($name !== '') {
            $entry['name'] = $name;
        }
        $recipients[] = $entry;
    }

    if (empty($recipients)) {
        $result['error'] = 'No valid recipient';
        mailLog('SKIP (no valid recipient): ' . $subject);
        return $result;
    }

    $payload = [
        'sender' => [
            'name' => (string) $config['sender_name'],
            'email' => (string) $config['sender_email'],
        ],
        'to' => $recipients,
        'subject' => $subject,
        'htmlContent' => $htmlContent,
    ];

    if (trim($textContent) !== '') {
        $payload['textContent'] = $textContent;
    }

    $replyTo = trim((string) ($config['reply_to_email'] ?? ''));
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['replyTo'] = ['email' => $replyTo];
    }

    foreach ($extra as $key => $value) {
        $payload[$key] = $value;
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $result['error'] = 'Unable to encode payload';
        mailLog('FAIL (encode): ' . $subject);
        return $result;
    }

    $curl = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) ($config['timeout'] ?? 8),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . (string) $config['api_key'],
        ],
    ]);

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    $result['status'] = $status;
    $result['body'] = is_string($body) ? $body : '';

    if ($body === false) {
        $result['error'] = $curlError !== '' ? $curlError : 'cURL request failed';
        mailLog('FAIL (curl): ' . $subject . ' | ' . $result['error']);
        return $result;
    }

    // Brevo answers 201 Created with a messageId on success.
    if ($status >= 200 && $status < 300) {
        $result['ok'] = true;
        mailLog('OK ' . $status . ': ' . $subject . ' -> ' . implode(', ', array_column($recipients, 'email')));
        return $result;
    }

    $result['error'] = 'HTTP ' . $status;
    mailLog('FAIL ' . $status . ': ' . $subject . ' | ' . $result['body']);

    return $result;
}

/**
 * Wraps body HTML in the shared branded email layout.
 */
function mailRenderLayout(string $heading, string $introHtml, array $rows = [], ?string $ctaLabel = null, ?string $ctaUrl = null, string $footerNote = '', ?string $secondaryCtaLabel = null, ?string $secondaryCtaUrl = null): string
{
    $esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $rowsHtml .= '<tr>'
            . '<td style="padding:8px 0;color:#6b7280;font-size:14px;width:42%;vertical-align:top;">' . $esc($label) . '</td>'
            . '<td style="padding:8px 0;color:#111827;font-size:14px;font-weight:600;">' . $esc($value) . '</td>'
            . '</tr>';
    }
    if ($rowsHtml !== '') {
        $rowsHtml = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
            . ' style="margin:18px 0;border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;">'
            . $rowsHtml . '</table>';
    }

    $ctaHtml = '';
    if ($ctaLabel !== null && $ctaUrl !== null && $ctaUrl !== '') {
        $ctaHtml = '<p style="margin:26px 0 8px;">'
            . '<a href="' . $esc($ctaUrl) . '"'
            . ' style="display:inline-block;padding:12px 26px;border-radius:999px;background:#b98b12;'
            . 'color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;">' . $esc($ctaLabel) . '</a>';
        if ($secondaryCtaLabel !== null && $secondaryCtaUrl !== null && $secondaryCtaUrl !== '') {
            $ctaHtml .= '&nbsp;&nbsp;'
                . '<a href="' . $esc($secondaryCtaUrl) . '"'
                . ' style="display:inline-block;padding:12px 26px;border-radius:999px;background:#ffffff;'
                . 'border:1px solid #d1d5db;color:#374151;text-decoration:none;font-weight:700;font-size:15px;">'
                . $esc($secondaryCtaLabel) . '</a>';
        }
        $ctaHtml .= '</p>'
            . '<p style="margin:6px 0 0;color:#9ca3af;font-size:12px;word-break:break-all;">' . $esc($ctaUrl) . '</p>';
    }

    $footerHtml = '';
    if (trim($footerNote) !== '') {
        $footerHtml = '<p style="margin:22px 0 0;color:#6b7280;font-size:13px;line-height:1.5;">' . $esc($footerNote) . '</p>';
    }

    // The brand name is live text, not part of the image: clients that force a
    // dark theme recolour text automatically but cannot recolour pixels, and
    // images are blocked by default in many clients anyway. The icon carries its
    // own opaque background so it stays readable on light and dark alike.
    $logoUrl = mailLogoUrl();
    $wordmarkHtml = '<span style="color:#0d1f38;font-size:19px;font-weight:700;vertical-align:middle;">StaffEase</span>'
        . '<span style="color:#b98b12;font-size:19px;font-weight:700;vertical-align:middle;">&nbsp;Pro</span>';
    // Full logo (icon + wordmark). It has a transparent background, so the
    // header cell forces an explicit white bgcolor to keep it legible when a
    // client darkens the palette.
    $brandHtml = $logoUrl !== ''
        ? '<img src="' . $esc($logoUrl) . '" width="150" alt="StaffEase Pro"'
            . ' style="display:block;border:0;outline:none;text-decoration:none;width:150px;max-width:150px;'
            . 'height:auto;color:#0d1f38;font-size:19px;font-weight:700;">'
        : $wordmarkHtml;

    // color-scheme tells Apple Mail and iOS not to auto-invert the palette;
    // Gmail ignores it but preserves explicit bgcolor attributes.
    return '<!doctype html><html><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light">'
        . '<meta name="supported-color-schemes" content="light">'
        . '</head><body style="margin:0;padding:0;background:#f3f4f6;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#f3f4f6" style="background:#f3f4f6;padding:28px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
        . ' bgcolor="#ffffff" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;'
        . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
        . '<tr><td bgcolor="#ffffff" style="background:#ffffff;padding:18px 28px 14px;border-bottom:3px solid #b98b12;">'
        . $brandHtml
        . '</td></tr>'
        . '<tr><td bgcolor="#ffffff" style="background:#ffffff;padding:28px;">'
        . '<h1 style="margin:0 0 12px;color:#111827;font-size:20px;line-height:1.3;">' . $esc($heading) . '</h1>'
        . '<div style="color:#374151;font-size:15px;line-height:1.6;">' . $introHtml . '</div>'
        . $rowsHtml
        . $ctaHtml
        . $footerHtml
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f9fafb;color:#9ca3af;font-size:12px;">'
        . $esc(t('mail.footer_automatic'))
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Plain-text fallback built from the same pieces as the HTML body.
 */
function mailRenderText(string $heading, string $intro, array $rows = [], ?string $ctaUrl = null, string $footerNote = ''): string
{
    $lines = [$heading, '', $intro];
    if (!empty($rows)) {
        $lines[] = '';
        foreach ($rows as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
    }
    if ($ctaUrl !== null && $ctaUrl !== '') {
        $lines[] = '';
        $lines[] = $ctaUrl;
    }
    if (trim($footerNote) !== '') {
        $lines[] = '';
        $lines[] = $footerNote;
    }
    $lines[] = '';
    $lines[] = t('mail.footer_automatic');

    return implode("\n", $lines);
}

/**
 * Formats a Y-m-d date for display inside emails.
 */
function mailFormatDate(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return '-';
    }
    $timestamp = strtotime($date);

    return $timestamp === false ? $date : date('d/m/Y', $timestamp);
}

/**
 * Welcome email sent when an account is created for someone.
 *
 * $plainPassword is only known at creation time (it is hashed right after), so
 * it is passed in explicitly rather than read back from the database.
 */
function sendUserCreatedEmail(array $user, string $plainPassword = '', string $companyName = ''): bool
{
    if (!mailNotificationEnabled('user_created')) {
        return false;
    }

    $email = trim((string) ($user['email'] ?? ''));
    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $loginUrl = mailAppUrl('login');

    $rows = [t('mail.field_email') => $email];
    if ($companyName !== '') {
        $rows[t('mail.field_company')] = $companyName;
    }
    if (!empty($user['role'])) {
        $rows[t('mail.field_role')] = t('roles.' . (string) $user['role']);
    }
    if ($plainPassword !== '') {
        $rows[t('mail.field_temp_password')] = $plainPassword;
    }

    $heading = t('mail.user_created_subject');
    $intro = t('mail.user_created_intro', ['name' => $fullName !== '' ? $fullName : $email]);
    $footer = $plainPassword !== '' ? t('mail.user_created_password_note') : '';

    $result = brevoSendEmail(
        [['email' => $email, 'name' => $fullName]],
        $heading,
        mailRenderLayout($heading, '<p style="margin:0;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>', $rows, t('mail.cta_sign_in'), $loginUrl, $footer),
        mailRenderText($heading, $intro, $rows, $loginUrl, $footer)
    );

    return $result['ok'];
}

/**
 * Notifies the super admins that a new company was created.
 */
function sendCompanyCreatedEmail(array $company, array $recipients, string $createdByName = ''): bool
{
    if (!mailNotificationEnabled('company_created')) {
        return false;
    }

    $to = [];
    foreach ($recipients as $recipient) {
        $to[] = [
            'email' => (string) ($recipient['email'] ?? ''),
            'name' => trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? '')),
        ];
    }
    if (empty($to)) {
        return false;
    }

    $companyName = (string) ($company['name'] ?? '');
    $rows = [t('mail.field_company') => $companyName];
    if (!empty($company['city'])) {
        $rows[t('mail.field_city')] = (string) $company['city'];
    }
    if (!empty($company['email'])) {
        $rows[t('mail.field_email')] = (string) $company['email'];
    }
    if ($createdByName !== '') {
        $rows[t('mail.field_created_by')] = $createdByName;
    }

    $heading = t('mail.company_created_subject');
    $intro = t('mail.company_created_intro', ['company' => $companyName]);
    $dashboardUrl = mailAppUrl('dashboard');

    $result = brevoSendEmail(
        $to,
        $heading . ' - ' . $companyName,
        mailRenderLayout($heading, '<p style="margin:0;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>', $rows, t('mail.cta_open_dashboard'), $dashboardUrl),
        mailRenderText($heading, $intro, $rows, $dashboardUrl)
    );

    return $result['ok'];
}

/**
 * Tells an employee that one of their shifts was assigned, moved or removed.
 *
 * $changeType is one of: assigned, moved, removed.
 */
function sendShiftChangeEmail(array $user, array $details, string $changeType): bool
{
    if (!mailNotificationEnabled('shift_change')) {
        return false;
    }

    $email = trim((string) ($user['email'] ?? ''));
    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

    $subjectKey = match ($changeType) {
        'moved' => 'mail.shift_moved_subject',
        'removed' => 'mail.shift_removed_subject',
        default => 'mail.shift_assigned_subject',
    };
    $introKey = match ($changeType) {
        'moved' => 'mail.shift_moved_intro',
        'removed' => 'mail.shift_removed_intro',
        default => 'mail.shift_assigned_intro',
    };

    $rows = [t('mail.field_date') => mailFormatDate($details['work_date'] ?? null)];
    if (!empty($details['shift_name'])) {
        $rows[t('mail.field_shift')] = (string) $details['shift_name'];
    }
    if (!empty($details['start_time']) || !empty($details['end_time'])) {
        $rows[t('mail.field_hours')] = trim((string) ($details['start_time'] ?? '') . ' - ' . (string) ($details['end_time'] ?? ''), ' -');
    }
    if (!empty($details['department_name'])) {
        $rows[t('mail.field_department')] = (string) $details['department_name'];
    }
    if (!empty($details['previous_work_date']) && $changeType === 'moved') {
        $rows[t('mail.field_previous_date')] = mailFormatDate($details['previous_work_date']);
    }

    $heading = t($subjectKey);
    $intro = t($introKey, ['name' => $fullName !== '' ? $fullName : $email]);
    $spaceUrl = mailAppUrl('my-space');

    $result = brevoSendEmail(
        [['email' => $email, 'name' => $fullName]],
        $heading . ' - ' . mailFormatDate($details['work_date'] ?? null),
        mailRenderLayout($heading, '<p style="margin:0;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>', $rows, t('mail.cta_open_my_space'), $spaceUrl),
        mailRenderText($heading, $intro, $rows, $spaceUrl)
    );

    return $result['ok'];
}

/**
 * Sends the one-time password reset link.
 */
function sendPasswordResetEmail(array $user, string $token, int $expiresInMinutes): bool
{
    if (!mailNotificationEnabled('password_reset')) {
        return false;
    }

    $email = trim((string) ($user['email'] ?? ''));
    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $resetUrl = mailAppUrl('reset-password', ['token' => $token]);

    $heading = t('mail.password_reset_subject');
    $intro = t('mail.password_reset_intro', ['name' => $fullName !== '' ? $fullName : $email]);
    $footer = t('mail.password_reset_note', ['minutes' => (string) $expiresInMinutes]);

    $result = brevoSendEmail(
        [['email' => $email, 'name' => $fullName]],
        $heading,
        mailRenderLayout($heading, '<p style="margin:0;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>', [], t('mail.cta_reset_password'), $resetUrl, $footer),
        mailRenderText($heading, $intro, [], $resetUrl, $footer)
    );

    return $result['ok'];
}

/**
 * Loads the employee and fires a shift-change email, swallowing every failure.
 *
 * Used by the planner actions (assign / move / unassign). Bulk auto-assignment
 * deliberately does not call this: one click there can touch hundreds of rows.
 */
function notifyShiftChange(PDO $pdo, int $userId, array $details, string $changeType): void
{
    if ($userId <= 0 || !mailNotificationEnabled('shift_change')) {
        return;
    }

    try {
        $statement = $pdo->prepare('SELECT id, first_name, last_name, email, status FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $employee = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$employee || ($employee['status'] ?? 'active') !== 'active') {
            return;
        }

        sendShiftChangeEmail($employee, $details, $changeType);
    } catch (Throwable $e) {
        mailLog('shift_change notification failed: ' . $e->getMessage());
    }
}

/**
 * Address that receives sign-up authorization requests.
 */
function mailAdminNotificationEmail(): string
{
    $config = mailConfig();
    $explicit = trim((string) ($config['admin_notification_email'] ?? ''));

    return $explicit !== '' ? $explicit : trim((string) ($config['sender_email'] ?? ''));
}

/**
 * Builds the label/value rows shared by the approval emails.
 */
function mailCompanyRequestRows(array $company, array $requester): array
{
    $rows = [t('mail.field_company') => (string) ($company['name'] ?? '')];

    foreach ([
        'vat_number' => 'mail.field_vat',
        'type' => 'mail.field_type',
        'address' => 'mail.field_address',
        'city' => 'mail.field_city',
        'zip_code' => 'mail.field_zip',
        'province' => 'mail.field_province',
        'phone' => 'mail.field_phone',
        'email' => 'mail.field_company_email',
    ] as $field => $labelKey) {
        $value = trim((string) ($company[$field] ?? ''));
        if ($value !== '') {
            $rows[t($labelKey)] = $value;
        }
    }

    $contactName = trim(($requester['first_name'] ?? '') . ' ' . ($requester['last_name'] ?? ''));
    if ($contactName !== '') {
        $rows[t('mail.field_contact')] = $contactName;
    }
    foreach (['contact_role' => 'mail.field_contact_role', 'email' => 'mail.field_email', 'phone' => 'mail.field_contact_phone'] as $field => $labelKey) {
        $value = trim((string) ($requester[$field] ?? ''));
        if ($value !== '') {
            $rows[t($labelKey)] = $value;
        }
    }

    return $rows;
}

/**
 * Asks the platform owner to authorize a brand-new sign-up.
 * The email carries one-click Approve / Reject links backed by a single token.
 */
function sendCompanyApprovalRequestEmail(array $company, array $requester, string $token): bool
{
    if (!mailNotificationEnabled('approval_request')) {
        return false;
    }

    $adminEmail = mailAdminNotificationEmail();
    if ($adminEmail === '') {
        return false;
    }

    $approveUrl = mailAppUrl('company-approval', ['token' => $token, 'decision' => 'approve']);
    $rejectUrl = mailAppUrl('company-approval', ['token' => $token, 'decision' => 'reject']);

    $heading = t('mail.approval_request_subject');
    $intro = t('mail.approval_request_intro', ['company' => (string) ($company['name'] ?? '')]);
    $footer = t('mail.approval_request_note', ['days' => (string) trialPeriodDays()]);
    $rows = mailCompanyRequestRows($company, $requester);

    $result = brevoSendEmail(
        [['email' => $adminEmail, 'name' => 'StaffEase Pro']],
        $heading . ' - ' . (string) ($company['name'] ?? ''),
        mailRenderLayout(
            $heading,
            '<p style="margin:0;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>',
            $rows,
            t('mail.cta_approve'),
            $approveUrl,
            $footer,
            t('mail.cta_reject'),
            $rejectUrl
        ),
        mailRenderText($heading, $intro, $rows, $approveUrl, t('mail.cta_reject') . ': ' . $rejectUrl . "\n" . $footer)
    );

    return $result['ok'];
}

/**
 * Confirms to the applicant that their trial is open.
 */
function sendCompanyApprovedEmail(array $requester, array $company, ?string $trialEndsAt): bool
{
    if (!mailNotificationEnabled('approval_decision')) {
        return false;
    }

    $fullName = trim(($requester['first_name'] ?? '') . ' ' . ($requester['last_name'] ?? ''));
    $heading = t('mail.approved_subject');
    $intro = t('mail.approved_intro', ['name' => $fullName, 'company' => (string) ($company['name'] ?? '')]);
    $rows = [
        t('mail.field_company') => (string) ($company['name'] ?? ''),
        t('mail.field_trial_days') => (string) trialPeriodDays(),
        t('mail.field_trial_ends') => mailFormatDate($trialEndsAt),
    ];
    $loginUrl = mailAppUrl('login');

    $result = brevoSendEmail(
        [['email' => (string) ($requester['email'] ?? ''), 'name' => $fullName]],
        $heading,
        mailRenderLayout($heading, '<p style="margin:0;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>', $rows, t('mail.cta_sign_in'), $loginUrl, t('mail.approved_note')),
        mailRenderText($heading, $intro, $rows, $loginUrl, t('mail.approved_note'))
    );

    return $result['ok'];
}

/**
 * Tells the applicant the request was declined.
 */
function sendCompanyRejectedEmail(array $requester, array $company): bool
{
    if (!mailNotificationEnabled('approval_decision')) {
        return false;
    }

    $fullName = trim(($requester['first_name'] ?? '') . ' ' . ($requester['last_name'] ?? ''));
    $heading = t('mail.rejected_subject');
    $intro = t('mail.rejected_intro', ['name' => $fullName, 'company' => (string) ($company['name'] ?? '')]);

    $result = brevoSendEmail(
        [['email' => (string) ($requester['email'] ?? ''), 'name' => $fullName]],
        $heading,
        mailRenderLayout($heading, '<p style="margin:0;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>', [], null, null, t('mail.rejected_note')),
        mailRenderText($heading, $intro, [], null, t('mail.rejected_note'))
    );

    return $result['ok'];
}

/**
 * Notice templates the super admin can send to a company owner.
 *
 * Keys are stable identifiers stored in no database: they only travel from the
 * dashboard select to the API, which validates them against this list.
 */
function companyNoticeTemplates(): array
{
    return ['renew_trial', 'renew_payment', 'expiring'];
}

/**
 * Label / subject / body for a notice template, already translated.
 */
function companyNoticeTemplate(string $template, string $companyName): array
{
    if (!in_array($template, companyNoticeTemplates(), true)) {
        $template = 'renew_trial';
    }

    return [
        'label' => t('mail.tpl_' . $template),
        'subject' => t('mail.tpl_' . $template . '_subject'),
        'body' => t('mail.tpl_' . $template . '_body', ['company' => $companyName]),
    ];
}

/**
 * Sends a manual notice to a company owner: an email plus an in-app
 * notification, so it is visible even before they open their mailbox.
 *
 * Replaces the automatic trial-expiry job: the super admin decides when.
 *
 * @return array{email:bool,in_app:bool}
 */
function sendCompanyNotice(PDO $pdo, array $owner, array $company, string $template): array
{
    $companyName = (string) ($company['name'] ?? '');
    $parts = companyNoticeTemplate($template, $companyName);
    $fullName = trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));
    $sent = ['email' => false, 'in_app' => false];

    // In-app first: it does not depend on an external service being reachable.
    try {
        $pdo->prepare(
            "INSERT INTO requests (user_id, type, title, message, status)
             VALUES (:user_id, 'notification', :title, :message, 'pending')"
        )->execute([
            'user_id' => (int) ($owner['id'] ?? 0),
            'title' => $parts['subject'],
            'message' => $parts['body'],
        ]);
        $sent['in_app'] = true;
    } catch (Throwable $e) {
        mailLog('company notice in-app failed: ' . $e->getMessage());
    }

    if (mailNotificationEnabled('company_notice')) {
        $rows = [];
        if ($companyName !== '') {
            $rows[t('mail.field_company')] = $companyName;
        }
        if (!empty($company['trial_ends_at'])) {
            $rows[t('mail.field_trial_ends')] = mailFormatDate((string) $company['trial_ends_at']);
        }

        $result = brevoSendEmail(
            [['email' => (string) ($owner['email'] ?? ''), 'name' => $fullName]],
            $parts['subject'],
            mailRenderLayout(
                $parts['subject'],
                '<p style="margin:0;">' . nl2br(htmlspecialchars($parts['body'], ENT_QUOTES, 'UTF-8')) . '</p>',
                $rows,
                t('mail.cta_sign_in'),
                mailAppUrl('login')
            ),
            mailRenderText($parts['subject'], $parts['body'], $rows, mailAppUrl('login'))
        );
        $sent['email'] = $result['ok'];
    }

    return $sent;
}
