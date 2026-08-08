<?php

/**
 * Brevo (ex Sendinblue) transactional email configuration.
 *
 * Copy this file to config/mail.php and fill in your own values.
 * config/mail.php is gitignored: the API key must never be committed.
 *
 * The sender address MUST be verified in your Brevo account
 * (Senders, Domains & Dedicated IPs > Senders), otherwise Brevo
 * rejects the request with HTTP 400.
 */
return [
    // Master switch. When false no HTTP call is made and every send is a no-op.
    'enabled' => false,

    // Brevo API v3 key, starts with "xkeysib-".
    'api_key' => '',

    // Verified sender shown as the "From" of every email.
    'sender_name' => 'StaffEase Pro',
    'sender_email' => '',

    // Optional: replies go here instead of the sender address.
    'reply_to_email' => '',

    // Absolute base URL used to build links inside emails (no trailing slash).
    // Emails are read outside the browser session, so relative paths are useless.
    'app_base_url' => 'http://localhost:8888',

    // Absolute, publicly reachable logo URL. Mail clients cannot load anything
    // from localhost, so during local development this must point at a host
    // that exists on the internet. Leave empty to derive it from app_base_url.
    'logo_url' => '',

    // Logo shown at the top of every email, relative to app_base_url.
    // Mail clients fetch it over the public internet: it only renders once
    // app_base_url points at a host reachable from outside your machine.
    'logo_path' => 'assets/images/email-logo.png',

    // Seconds before giving up on the Brevo API.
    'timeout' => 8,

    // When set, every send attempt is appended to this file (useful in dev).
    'log_file' => __DIR__ . '/../storage/logs/mail.log',

    // Per-feature switches, so you can roll the integration out gradually.
    'notify' => [
        'shift_change' => true,
        'user_created' => true,
        'company_created' => true,
        'password_reset' => true,
        'approval_request' => true,
        'approval_decision' => true,
        'company_notice' => true,
    ],
];
