<?php
/**
 * Shared header bar for the application.
 *
 * Purpose: render the top navigation, brand/logo and contextual quick actions
 * (home, settings, documents, print). The header adapts based on the current
 * route and the logged-in user's role.
 *
 * Available variables:
 * - `$route` (string) : current route name, falls back to GET['route'] or 'home'
 * - `currentUser()` provides the authenticated user array when available
 *
 * Notes: keep UI strings consistent with the localization layer.
 */
$route = $route ?? ($_GET['route'] ?? 'home');
$currentUser = currentUser();
$locale = appLocale();
$isPublicPage = in_array($route, ['home', 'login', 'register'], true);
$currentRole = $currentUser['role'] ?? null;
$basePath = $basePath ?? (function () {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();

$headerLeft = '';
$leftMySpaceIcon = null;
$wifiAuthorizationKnown = isset($isCurrentNetworkAuthorized);
$showWifiStatus = false;
$isWifiConnected = $showWifiStatus && (bool) ($isCurrentNetworkAuthorized ?? false);
if (!$isPublicPage && $currentUser !== null) {
    $role = $currentUser['role'] ?? 'employee';
    $displayName = trim((string) (($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')));
    $displayLabel = $displayName !== '' ? $displayName : 'StaffEase Pro';

    if ($role === 'super_admin') {
        $headerLeft = [
            'title' => t('common.app_name'),
            'subtitle' => trim(t('roles.super_admin') . ' - ' . $displayLabel),
        ];
    } else {
        $companyName = t('common.app_name');
        $companyLogoPath = null;

        if (isset($dashboardPlannerData['company']['name'])) {
            $plannerCompanyName = trim((string) $dashboardPlannerData['company']['name']);
            if ($plannerCompanyName !== '') {
                $companyName = $plannerCompanyName;
            }
            $plannerCompanyLogo = trim((string) ($dashboardPlannerData['company']['logo_path'] ?? ''));
            if ($plannerCompanyLogo !== '') {
                $companyLogoPath = $plannerCompanyLogo;
            }
        }

        if ($companyName === t('common.app_name') && !empty($currentUser['department_id'])) {
            try {
                $pdo = getPDO();
                $statement = $pdo->prepare(
                    'SELECT c.name AS company_name, c.logo_path AS company_logo_path
                     FROM departments d
                     LEFT JOIN companies c ON c.id = d.company_id
                     WHERE d.id = :department_id
                     LIMIT 1'
                );
                $statement->execute(['department_id' => (int) $currentUser['department_id']]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if (!empty($row['company_name'])) {
                    $companyName = (string) $row['company_name'];
                }
                if (!empty($row['company_logo_path'])) {
                    $companyLogoPath = (string) $row['company_logo_path'];
                }
            } catch (Throwable $e) {
                $companyName = 'StaffEase Pro';
            }
        }

        $companyLogoSrc = null;
        if (is_string($companyLogoPath) && trim($companyLogoPath) !== '') {
            $rawLogoPath = trim($companyLogoPath);
            if (preg_match('/^(https?:)?\/\//i', $rawLogoPath) || str_starts_with($rawLogoPath, 'data:')) {
                $companyLogoSrc = $rawLogoPath;
            } else {
                if (str_starts_with($rawLogoPath, 'uploads/')) {
                    $rawLogoPath = 'public/' . $rawLogoPath;
                }
                $companyLogoSrc = rtrim($basePath, '/') . '/' . ltrim($rawLogoPath, '/');
            }
        }

        $headerLeft = [
            'title' => $displayLabel,
            'subtitle' => trim($companyName),
            'subtitle_role' => trim(t('roles.' . (string) $role)),
            'subtitle_logo_path' => $companyLogoSrc,
        ];
    }
}

if (!$isPublicPage && $currentUser !== null) {
    $role = $currentUser['role'] ?? 'employee';
    if (in_array($role, ['admin', 'department_manager'], true)) {
        $isMySpaceRoute = $route === 'my-space';
        $leftMySpaceIcon = [
            'href' => $isMySpaceRoute ? appUrl('dashboard') : appUrl('my-space'),
            'title' => $isMySpaceRoute ? t('common.back_to_dashboard') : t('common.my_attendance'),
            'icon' => $isMySpaceRoute ? 'chart-candlestick.svg' : 'signature.svg',
            'alt' => t('common.my_attendance'),
        ];
    }
}

$rightIcons = [];
$logoutIcon = null;
$isPublicInfoRoute = in_array($route, ['home', 'commercial', 'legal', 'contacts', 'creator', 'login', 'register'], true);
if ($route === 'home') {
    $rightIcons[] = [
        'type' => 'link',
        'href' => appUrl('login'),
        'title' => t('common.login'),
        'icon' => 'log-in.svg',
        'alt' => t('common.login'),
    ];
} elseif ($route === 'login' || $route === 'register') {
    $rightIcons[] = [
        'type' => 'link',
        'href' => appUrl('home'),
        'title' => t('common.home'),
        'icon' => 'home.svg',
        'alt' => t('common.home'),
    ];
} elseif ($currentUser !== null) {
    $role = $currentUser['role'] ?? 'employee';
    if ($route === 'dashboard') {
        $rightIcons[] = [
            'type' => 'button',
            'title' => t('common.settings'),
            'target' => 'modal-settings',
            'entity' => 'settings',
            'icon' => 'setting.svg',
            'alt' => t('common.settings'),
        ];
        $rightIcons[] = [
            'type' => 'button',
            'title' => t('common.documents'),
            'target' => 'crud-modal',
            'entity' => 'documents',
            'icon' => 'document.svg',
            'alt' => t('common.documents'),
        ];
        $rightIcons[] = [
            'type' => 'button',
            'title' => t('common.print'),
            'target' => 'modal-print',
            'icon' => 'print-outline.svg',
            'alt' => t('common.print'),
        ];
        if (in_array($role, ['super_admin', 'admin', 'department_manager'], true)) {
            $rightIcons[] = [
                'type' => 'tour',
                'title' => t('onboarding.relaunch_button_label'),
                'alt' => t('onboarding.relaunch_button_label'),
            ];
        }

    } else {
        if ($route === 'my-space') {
            if ($role === 'employee') {
                $rightIcons[] = [
                    'type' => 'button',
                    'title' => t('common.documents'),
                    'target' => 'employee-documents-inbox-modal',
                    'icon' => 'document.svg',
                    'alt' => t('common.documents'),
                ];
            }
        }

        if ($role === 'employee') {
        }
        if ($role !== 'employee') {
            if ($route !== 'my-space') {
                $rightIcons[] = [
                    'type' => 'link',
                    'href' => appUrl('dashboard'),
                    'title' => t('common.dashboard'),
                    'icon' => 'setting.svg',
                    'alt' => t('common.dashboard'),
                ];
            }

            if (in_array($role, ['admin', 'department_manager'], true)) {
            }
        }
    }
    $logoutIcon = [
        'type' => 'link',
        'href' => appUrl('logout'),
        'title' => t('common.logout'),
        'icon' => 'log-out.svg',
        'alt' => t('common.logout'),
    ];
}

$mobileMenuItems = [];
if ($isPublicInfoRoute) {
    $mobileMenuItems[] = ['type' => 'link', 'href' => appUrl('home'), 'label' => t('common.home')];
}

$isLeadershipDashboard = $route === 'dashboard' && $currentUser !== null
    && in_array((string) ($currentUser['role'] ?? ''), ['super_admin', 'admin', 'department_manager'], true);

if ($isLeadershipDashboard) {
    $isItalianLocale = str_starts_with(strtolower((string) $locale), 'it');
    $isFrenchLocale = str_starts_with(strtolower((string) $locale), 'fr');
    $settingsLabel = $isItalianLocale ? 'Parametri' : ($isFrenchLocale ? 'Parametres' : t('common.settings'));
    $connectionLabel = $isItalianLocale ? 'Connessione' : ($isFrenchLocale ? 'Connexion' : 'Connection');
    $connectionRouteParams = [
        'modal' => 'settings',
        'settings_tab' => 'attendances',
    ];
    if (($currentUser['role'] ?? '') === 'super_admin' && isset($_GET['settings_company_id']) && (int) $_GET['settings_company_id'] > 0) {
        $connectionRouteParams['settings_company_id'] = (int) $_GET['settings_company_id'];
    }

    $mobileMenuItems[] = [
        'type' => 'button',
        'label' => $settingsLabel,
        'target' => 'modal-settings',
        'entity' => 'settings',
    ];
    $mobileMenuItems[] = [
        'type' => 'link',
        'href' => appUrl('dashboard', $connectionRouteParams),
        'label' => $connectionLabel,
    ];
    $mobileMenuItems[] = [
        'type' => 'button',
        'label' => t('common.documents'),
        'target' => 'crud-modal',
        'entity' => 'documents',
    ];
    $mobileMenuItems[] = [
        'type' => 'button',
        'label' => t('common.print'),
        'target' => 'modal-print',
        'entity' => '',
    ];
    $mobileMenuItems[] = [
        'type' => 'tour',
        'label' => t('onboarding.relaunch_button_label'),
    ];
} else {
    foreach ($rightIcons as $iconItem) {
        if (($iconItem['type'] ?? 'link') === 'button') {
            $mobileMenuItems[] = [
                'type' => 'button',
                'label' => (string) ($iconItem['title'] ?? t('common.action')),
                'target' => (string) ($iconItem['target'] ?? ''),
                'entity' => (string) ($iconItem['entity'] ?? ''),
            ];
            continue;
        }
        $mobileMenuItems[] = [
            'type' => 'link',
            'href' => (string) ($iconItem['href'] ?? appUrl('home')),
            'label' => (string) ($iconItem['title'] ?? t('common.action')),
        ];
    }
}

if (is_array($logoutIcon)) {
    $mobileMenuItems[] = [
        'type' => 'link',
        'href' => (string) ($logoutIcon['href'] ?? appUrl('logout')),
        'label' => (string) ($logoutIcon['title'] ?? t('common.logout')),
    ];
}
?>
<header class="site-header">
    <nav class="site-navbar" aria-label="Primary navigation">
        <div class="site-navbar-inner">
            <div class="site-navbar-left">
                <?php if (is_array($leftMySpaceIcon)): ?>
                    <a href="<?php echo e($leftMySpaceIcon['href']); ?>" class="site-icon-btn site-left-myspace-btn" title="
                    <?php echo e($leftMySpaceIcon['title']); ?>" aria-label="<?php echo e($leftMySpaceIcon['title']); ?>">
            <img src="<?php echo $basePath; ?>/assets/icons/<?php echo e($leftMySpaceIcon['icon']); ?>" alt="<?php echo e($leftMySpaceIcon['alt']); ?>" class="site-icon">
                    </a>
                <?php endif; ?>
                <?php if (is_array($headerLeft)): ?>
                    <div class="site-header-meta">
                        <div class="site-header-title-row">
                            <div class="site-header-title"><?php echo e($headerLeft['title']); ?></div>
                            <?php if ($showWifiStatus): ?>
                                <span class="site-wifi-status <?php echo $isWifiConnected ? 'is-connected' : 'is-blocked';
                                 ?>" title="<?php echo e($isWifiConnected ? t('employee.status_connected') : t('employee.status_restricted_network')); 
                                 ?>" aria-label="<?php echo e($isWifiConnected ? t('employee.status_connected') : t('employee.status_restricted_network')); 
                                 ?>"><img src="<?php echo $basePath; ?>/assets/icons/wifi-high.svg" alt="" aria-hidden="true" class="site-wifi-icon">
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="site-header-subtitle-row">
                            <?php if (!empty($headerLeft['subtitle_logo_path'])): ?>
                                <img src="<?php echo e((string) $headerLeft['subtitle_logo_path']); ?>" alt="" class="site-company-logo-inline" loading="lazy" decoding="async">
                            <?php endif; ?>
                            <div class="site-header-subtitle"><?php echo e($headerLeft['subtitle']); ?></div>
                        </div>
                        <?php if (!empty($headerLeft['subtitle_role'])): ?>
                            <div class="site-header-subrole"><?php echo e($headerLeft['subtitle_role']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="site-navbar-center">
                <a href="<?php
                    if ($isPublicPage) {
                        echo appUrl('home');
                    } elseif ($currentRole === 'employee') {
                        echo appUrl('my-space');
                    } else {
                        echo appUrl('dashboard');
                    }
                ?>" class="site-brand-link" aria-label="<?php echo e(t('common.app_name')); ?>">
                    <img src="<?php echo $basePath; ?>/assets/images/LogoStaffeasePro.png" alt="<?php echo e(t('common.app_name')); ?>" class="site-brand-logo" width="147" height="147" loading="eager" decoding="async" fetchpriority="high">
                </a>
            </div>
            <div class="site-navbar-right">
                <button
                    type="button"
                    class="site-icon-btn site-burger-btn"
                    title="<?php echo e(t('common.quick_actions')); ?>"
                    aria-label="<?php echo e(t('common.quick_actions')); ?>"
                    aria-controls="site-mobile-drawer"
                    aria-expanded="false"
                    data-site-menu-open
                >
                    <img src="<?php echo $basePath; ?>/assets/icons/menu.svg" alt="" class="site-icon" aria-hidden="true">
                </button>
                <div class="site-icon-group" role="toolbar" aria-label="<?php echo e(t('common.quick_actions')); ?>">
                    <?php foreach ($rightIcons as $iconItem): ?>
                        <?php if (($iconItem['type'] ?? 'link') === 'tour'): ?>
                            <button type="button" class="site-icon-btn onboarding-tour-relaunch-btn" data-onboarding-tour-relaunch title="<?php echo e($iconItem['title']); ?>" aria-label="<?php echo e($iconItem['title']); ?>">?</button>
                        <?php elseif (($iconItem['type'] ?? 'link') === 'button'): ?>
                            <button
                                type="button"
                                class="site-icon-btn"
                                title="<?php echo e($iconItem['title']); ?>"
                                aria-label="<?php echo e($iconItem['title']); ?>"
                                <?php if (($iconItem['toggle'] ?? '') === 'calendar-navigator'): ?>
                                    data-calendar-navigator-toggle="true"
                                    aria-controls="dashboard-calendar-navigator"
                                    aria-expanded="false"
                                <?php else: ?>
                                    data-modal-target="<?php echo e($iconItem['target']); ?>"
                                    <?php if (!empty($iconItem['entity'])): ?>data-modal-entity="<?php echo e($iconItem['entity']); ?>"<?php endif; ?>
                                <?php endif; ?>
                            >
                                <?php if (!empty($iconItem['toggle'])): ?>
                                    <img src="<?php echo $basePath; ?>/assets/icons/menu.svg" alt="" class="site-icon" aria-hidden="true">
                                <?php else: ?>
                                    <img src="<?php echo $basePath; ?>/assets/icons/<?php echo e($iconItem['icon']); ?>" alt="<?php echo e($iconItem['alt']); ?>" class="site-icon">
                                <?php endif; ?>
                            </button>
                        <?php else: ?>
                            <a href="<?php echo e($iconItem['href']); ?>" class="site-icon-btn" title="<?php echo e($iconItem['title']); ?>" aria-label="<?php echo e($iconItem['title']); ?>">
                                <img src="<?php echo $basePath; ?>/assets/icons/<?php echo e($iconItem['icon']); ?>" alt="<?php echo e($iconItem['alt']); ?>" class="site-icon">
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="site-lang-switch" aria-label="<?php echo e(t('common.language')); ?>">
                        <details class="site-lang-dropdown">
                            <summary class="site-icon-btn site-lang-trigger" title="<?php echo e(t('common.language')); ?>" aria-label="<?php echo e(t('common.language')); ?>">
                                <img src="<?php echo $basePath; ?>/assets/icons/language.svg" alt="<?php echo e(t('common.language')); ?>" class="site-icon">
                            </summary>
                            <div class="site-lang-menu" role="menu" aria-label="<?php echo e(t('common.language')); ?>">
                                <a href="<?php echo e(appCurrentUrl(['lang' => 'en'])); ?>" class="site-lang-link <?php echo $locale === 'en' ? 'is-active' : ''; ?>" role="menuitem">English</a>
                                <a href="<?php echo e(appCurrentUrl(['lang' => 'fr'])); ?>" class="site-lang-link <?php echo $locale === 'fr' ? 'is-active' : ''; ?>" role="menuitem">Francais</a>
                                <a href="<?php echo e(appCurrentUrl(['lang' => 'it'])); ?>" class="site-lang-link <?php echo $locale === 'it' ? 'is-active' : ''; ?>" role="menuitem">Italiano</a>
                            </div>
                        </details>
                    </div>
                    <?php if (is_array($logoutIcon)): ?>
                        <a href="<?php echo e($logoutIcon['href']); ?>" class="site-icon-btn" title="<?php echo e($logoutIcon['title']); ?>" aria-label="<?php echo e($logoutIcon['title']); ?>">
                            <img src="<?php echo $basePath; ?>/assets/icons/<?php echo e($logoutIcon['icon']); ?>" alt="<?php echo e($logoutIcon['alt']); ?>" class="site-icon">
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <div id="site-mobile-drawer" class="site-mobile-drawer" data-site-mobile-drawer hidden>
        <div class="site-mobile-drawer-backdrop" data-site-menu-close></div>
        <aside class="site-mobile-drawer-panel" role="dialog" aria-modal="true" tabindex="-1" aria-label="<?php echo e(t('common.quick_actions')); ?>">
            <div class="site-mobile-drawer-head">
                <strong><?php echo e(t('common.quick_actions')); ?></strong>
                <button type="button" class="site-mobile-drawer-close" data-site-menu-close aria-label="<?php echo e(t('common.close')); ?>">x</button>
            </div>
            <div class="site-mobile-drawer-links">
                <?php foreach ($mobileMenuItems as $item): ?>
                    <?php if (($item['type'] ?? 'link') === 'tour'): ?>
                        <button type="button" class="site-mobile-drawer-link" data-onboarding-tour-relaunch data-site-menu-close><?php echo e($item['label']); ?></button>
                    <?php elseif (($item['type'] ?? 'link') === 'button' && !empty($item['target'])): ?>
                        <button
                            type="button"
                            class="site-mobile-drawer-link"
                            data-modal-target="<?php echo e($item['target']); ?>"
                            <?php if (!empty($item['entity'])): ?>data-modal-entity="<?php echo e($item['entity']); ?>"<?php endif; ?>
                            data-site-menu-close
                        >
                            <?php echo e($item['label']); ?>
                        </button>
                    <?php elseif (!empty($item['href'])): ?>
                        <a class="site-mobile-drawer-link" href="<?php echo e($item['href']); ?>" data-site-menu-close><?php echo e($item['label']); ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <details class="site-mobile-drawer-lang">
                    <summary class="site-mobile-drawer-link site-mobile-drawer-lang-trigger"><?php echo e(t('common.language')); ?></summary>
                    <div class="site-mobile-drawer-lang-menu">
                        <a class="site-mobile-drawer-link site-mobile-drawer-lang-link <?php echo $locale === 'it' ? 'is-active' : ''; ?>" href="<?php echo e(appCurrentUrl(['lang' => 'it'])); ?>" data-site-menu-close>Italiano</a>
                        <a class="site-mobile-drawer-link site-mobile-drawer-lang-link <?php echo $locale === 'en' ? 'is-active' : ''; ?>" href="<?php echo e(appCurrentUrl(['lang' => 'en'])); ?>" data-site-menu-close>English</a>
                        <a class="site-mobile-drawer-link site-mobile-drawer-lang-link <?php echo $locale === 'fr' ? 'is-active' : ''; ?>" href="<?php echo e(appCurrentUrl(['lang' => 'fr'])); ?>" data-site-menu-close>Francais</a>
                    </div>
                </details>
            </div>
        </aside>
    </div>
</header>
