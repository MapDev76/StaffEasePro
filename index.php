<?php
require_once __DIR__ . '/backend/bootstrap.php';

// Front controller entry point: resolves route, loads controller/view, then renders shared layout.
$route = appRouteFromRequest();
$_GET['route'] = $route;
$targetFile = require __DIR__ . '/app/router.php';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

if (str_starts_with($targetFile, __DIR__ . '/backend/controllers/')) {
        require $targetFile;
} else {
        $viewFile = $targetFile;
}
// Controllers can set $viewFile to override the default view for the route.
$pageTitle = $pageTitle ?? 'StaffEase Pro';
$viewFile = $viewFile ?? $targetFile;
$flashSuccess = getFlash('success');
$flashError = getFlash('error');
$isDashboardRoute = in_array($route, ['dashboard', 'calendar'], true);
$isHomeRoute = $route === 'home';
$isCommercialRoute = $route === 'commercial';
$isLoginRoute = $route === 'login';
$isMySpaceRoute = $route === 'my-space';
$isLegalRoute = $route === 'legal';
$isContactsRoute = $route === 'contacts';
$isCreatorRoute = $route === 'creator';
$isStaticInfoRoute = $isLegalRoute || $isContactsRoute || $isCreatorRoute;
$isPublicRoute = $isHomeRoute || $isCommercialRoute || $isLoginRoute || $isStaticInfoRoute;
$locale = appLocale();
$shouldShowLoadingOverlay = $isDashboardRoute || $isMySpaceRoute;
$requiresApiClient = ($isDashboardRoute || $isMySpaceRoute);
$hasFlashUi = $flashSuccess !== null || $flashError !== null || (($loginError ?? null) !== null);
$useFullAppStyles = $isDashboardRoute || $isMySpaceRoute;
// Compact dashboard mode: removes padding and some UI elements for admins/managers to show more content.
// Super admins get the same compact, admin-like view once they drill into a specific company (settings_company_id).
$isSuperAdminScopedToCompany = $isDashboardRoute && isLoggedIn()
        && (string) (currentUser()['role'] ?? '') === 'super_admin'
        && (int) ($_GET['settings_company_id'] ?? 0) > 0;
$isCompactDashboard = $isDashboardRoute && isLoggedIn()
        && (in_array((currentUser()['role'] ?? ''), ['admin', 'department_manager'], true) || $isSuperAdminScopedToCompany);
$bodyClasses = [];
if ($isDashboardRoute) {
        $bodyClasses[] = 'route-dashboard';
}
if ($isHomeRoute) {
        $bodyClasses[] = 'route-home';
}
if ($isCommercialRoute) {
        $bodyClasses[] = 'route-commercial';
}
if ($isLoginRoute) {
        $bodyClasses[] = 'route-login';
}
if ($isMySpaceRoute) {
        $bodyClasses[] = 'route-my-space';
}
if ($isStaticInfoRoute) {
        $bodyClasses[] = 'route-legal';
}
if ($isPublicRoute) {
        $bodyClasses[] = 'route-public';
}
// Precompute CSS version based on file modification time for cache busting. If the file is missing, use current time to avoid caching issues during development.
$stylesheetBaseFile = $useFullAppStyles ? 'style.css' : 'public.css';
$stylesheetFile = $stylesheetBaseFile;
$cssVersion = (string) (@filemtime(__DIR__ . '/assets/css/' . $stylesheetFile) ?: time());

$localeCode = strtolower(substr((string) $locale, 0, 2));
$routeMetaByLocale = [
        'it' => [
                'home' => [
                        'title' => 'StaffEase Pro | Gestione Turni, Presenze e Documenti HR',
                        'description' => 'StaffEase Pro e una piattaforma cloud per pianificazione turni, rilevazione presenze, firme digitali e gestione documenti aziendali.',
                        'keywords' => 'StaffEase Pro, StaffEasePro, gestione turni, software presenze, firma digitale dipendenti, gestione personale',
                ],
                'commercial' => [
                        'title' => 'StaffEase Pro | Soluzione Gestionale per Team e Hotel',
                        'description' => 'Scopri StaffEase Pro: automazione turni, controllo presenze, documenti e comunicazione interna per team operativi.',
                        'keywords' => 'software turni hotel, pianificazione staff, controllo presenze, gestione documenti HR',
                ],
                'contacts' => [
                        'title' => 'StaffEase Pro | Contatti e Demo',
                        'description' => 'Contatta StaffEase Pro per demo, supporto tecnico e attivazione account aziendale.',
                        'keywords' => 'contatti StaffEase Pro, demo software gestione personale, supporto StaffEase Pro',
                ],
                'legal' => [
                        'title' => 'StaffEase Pro | Informazioni Legali',
                        'description' => 'Note legali e informazioni regolamentari relative a StaffEase Pro.',
                        'keywords' => 'note legali StaffEase Pro, policy piattaforma',
                ],
                'creator' => [
                        'title' => 'StaffEase Pro | Creatore del Progetto',
                        'description' => 'Scopri il creatore di StaffEase Pro e la visione della piattaforma per la gestione operativa del personale.',
                        'keywords' => 'creatore StaffEase Pro, storia progetto StaffEase Pro',
                ],
                'login' => [
                        'title' => 'StaffEase Pro | Accesso Sicuro',
                        'description' => 'Accedi in modo sicuro alla tua area StaffEase Pro per gestire team, presenze e documenti.',
                        'keywords' => 'login StaffEase Pro, accesso area personale',
                ],
                'dashboard' => [
                        'title' => 'StaffEase Pro | Dashboard Operativa',
                        'description' => 'Dashboard StaffEase Pro per coordinare reparti, turni, presenze e documenti in un unico pannello.',
                        'keywords' => 'dashboard gestione personale, turni e presenze, pannello HR',
                ],
                'my-space' => [
                        'title' => 'StaffEase Pro | Spazio Dipendente',
                        'description' => 'Area dipendente per visualizzare turni, registrare presenza e gestire documenti con firma digitale.',
                        'keywords' => 'area dipendente, firma digitale presenze, turni dipendente',
                ],
        ],
        'fr' => [
                'home' => [
                        'title' => 'StaffEase Pro | Planning, Pointage et Documents RH',
                        'description' => 'StaffEase Pro est une application cloud de gestion du personnel: planning des equipes, pointage, signatures numeriques et documents RH.',
                        'keywords' => 'StaffEase Pro, StaffEasePro, logiciel planning equipes, pointage employes, gestion RH',
                ],
                'commercial' => [
                        'title' => 'StaffEase Pro | Solution de Gestion des Equipes',
                        'description' => 'Decouvrez StaffEase Pro: automatisation des plannings, suivi des presences et gestion documentaire pour vos operations.',
                        'keywords' => 'logiciel planning hotel, gestion equipe, pointage entreprise, documents RH',
                ],
                'contacts' => [
                        'title' => 'StaffEase Pro | Contact et Demo',
                        'description' => 'Contactez StaffEase Pro pour une demo, un accompagnement technique et la creation de compte.',
                        'keywords' => 'contact StaffEase Pro, demo logiciel RH',
                ],
                'legal' => [
                        'title' => 'StaffEase Pro | Mentions Legales',
                        'description' => 'Mentions legales et informations reglementaires de StaffEase Pro.',
                        'keywords' => 'mentions legales StaffEase Pro, conformite plateforme',
                ],
                'creator' => [
                        'title' => 'StaffEase Pro | Createur',
                        'description' => 'Decouvrez le createur de StaffEase Pro et la vision produit pour la gestion operationnelle des equipes.',
                        'keywords' => 'createur StaffEase Pro, projet StaffEase Pro',
                ],
                'login' => [
                        'title' => 'StaffEase Pro | Connexion Securisee',
                        'description' => 'Connexion securisee a votre espace StaffEase Pro pour piloter planning, presences et documents.',
                        'keywords' => 'connexion StaffEase Pro, espace personnel',
                ],
                'dashboard' => [
                        'title' => 'StaffEase Pro | Tableau de Bord',
                        'description' => 'Tableau de bord StaffEase Pro pour gerer equipes, services, plannings, presences et documents au meme endroit.',
                        'keywords' => 'tableau de bord RH, gestion plannings, suivi presences',
                ],
                'my-space' => [
                        'title' => 'StaffEase Pro | Espace Employe',
                        'description' => 'Espace employe pour consulter les plannings, signer la presence et suivre les documents.',
                        'keywords' => 'espace employe, pointage signature, planning personnel',
                ],
        ],
        'en' => [
                'home' => [
                        'title' => 'StaffEase Pro | Shift Scheduling, Attendance and HR Documents',
                        'description' => 'StaffEase Pro is a workforce management app for shift scheduling, attendance tracking, digital signatures and internal documents.',
                        'keywords' => 'StaffEase Pro, StaffEasePro, workforce management app, shift scheduling software, attendance tracking',
                ],
                'commercial' => [
                        'title' => 'StaffEase Pro | Workforce Operations Platform',
                        'description' => 'Explore StaffEase Pro features for shift automation, attendance control, document workflows and team communication.',
                        'keywords' => 'workforce software, staff scheduling, attendance control, hr document management',
                ],
                'contacts' => [
                        'title' => 'StaffEase Pro | Contact and Demo',
                        'description' => 'Get in touch with StaffEase Pro for product demos, onboarding and technical support.',
                        'keywords' => 'StaffEase Pro contact, workforce software demo',
                ],
                'legal' => [
                        'title' => 'StaffEase Pro | Legal Information',
                        'description' => 'Legal notices and compliance information for StaffEase Pro.',
                        'keywords' => 'StaffEase Pro legal, compliance',
                ],
                'creator' => [
                        'title' => 'StaffEase Pro | Project Creator',
                        'description' => 'Meet the creator of StaffEase Pro and discover the platform mission.',
                        'keywords' => 'StaffEase Pro creator, product vision',
                ],
                'login' => [
                        'title' => 'StaffEase Pro | Secure Login',
                        'description' => 'Secure login to your StaffEase Pro workspace.',
                        'keywords' => 'StaffEase Pro login, employee platform login',
                ],
                'dashboard' => [
                        'title' => 'StaffEase Pro | Operations Dashboard',
                        'description' => 'Use the StaffEase Pro dashboard to manage teams, departments, shifts, attendance and documents.',
                        'keywords' => 'operations dashboard, team scheduling, attendance dashboard',
                ],
                'my-space' => [
                        'title' => 'StaffEase Pro | Employee Space',
                        'description' => 'Employee portal for schedules, attendance signature and personal documents.',
                        'keywords' => 'employee portal, attendance signature, shift information',
                ],
        ],
];

$routeMeta = $routeMetaByLocale[$localeCode] ?? $routeMetaByLocale['en'];

$resolvedMeta = $routeMeta[$route] ?? [
        'title' => $pageTitle,
        'description' => 'StaffEase Pro workforce management application.',
        'keywords' => 'StaffEase Pro, workforce management',
];

$pageTitle = $pageTitle !== 'StaffEase Pro' ? $pageTitle : $resolvedMeta['title'];
$metaDescription = $resolvedMeta['description'];
$metaKeywords = $resolvedMeta['keywords'] ?? 'StaffEase Pro, workforce management';
$canonicalPath = appCurrentUrl(['lang' => null]);
$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$siteBaseUrl = $requestScheme . '://' . $requestHost;
$canonicalUrl = str_starts_with($canonicalPath, 'http://') || str_starts_with($canonicalPath, 'https://')
        ? $canonicalPath
        : ($siteBaseUrl . $canonicalPath);

// Prefer clean canonical paths for public pages to reduce duplicate indexing of query routes.
$publicCanonicalByRoute = [
        'home' => '/',
        'commercial' => '/commercial',
        'contacts' => '/contacts',
        'legal' => '/legal',
        'creator' => '/creator',
];
if (isset($publicCanonicalByRoute[$route])) {
        $canonicalUrl = rtrim($siteBaseUrl, '/') . $publicCanonicalByRoute[$route];
}
$ogLocale = $localeCode === 'it' ? 'it_IT' : ($localeCode === 'fr' ? 'fr_FR' : 'en_US');
$isPrivateRoute = $isDashboardRoute || $isMySpaceRoute;
$robotsContent = $isPrivateRoute || $isLoginRoute ? 'noindex, nofollow' : 'index, follow';
$structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'StaffEase Pro',
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'url' => rtrim($siteBaseUrl, '/'),
        'description' => $metaDescription,
        'inLanguage' => $localeCode,
        'brand' => [
                '@type' => 'Brand',
                'name' => 'StaffEase Pro',
        ],
        'publisher' => [
                '@type' => 'Organization',
                'name' => 'StaffEase Pro',
        ],
];

$apiFile = __DIR__ . '/assets/js/api.js';
$apiVersion = (string) (@filemtime($apiFile) ?: time());

$publicUiFile = __DIR__ . '/assets/js/public-ui.js';
$publicUiVersion = (string) (@filemtime($publicUiFile) ?: time());

$uiHintsFile = __DIR__ . '/assets/js/ui-hints.js';
$uiHintsVersion = (string) (@filemtime($uiHintsFile) ?: time());



$employeeSpaceFile = __DIR__ . '/assets/js/employee-space.js';
$employeeSpaceVersion = (string) (@filemtime($employeeSpaceFile) ?: time());

$commercialVideosFile = __DIR__ . '/assets/js/dashboard/commercial-videos.js';
$commercialVideosVersion = (string) (@filemtime($commercialVideosFile) ?: time());
?>
<!DOCTYPE html>
<html lang="<?php echo e($locale); ?>">
<head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <meta name="description" content="<?php echo e($metaDescription); ?>">
                <meta name="keywords" content="<?php echo e($metaKeywords); ?>">
                <meta name="robots" content="<?php echo e($robotsContent); ?>">
                <meta name="application-name" content="StaffEase Pro">
                <link rel="icon" href="<?php echo $basePath; ?>/favicon.ico" type="image/x-icon" sizes="any">
                <link rel="shortcut icon" href="<?php echo $basePath; ?>/favicon.ico">
                <link rel="alternate icon" href="<?php echo $basePath; ?>/assets/images/faviconStaffeasePro.jpg" type="image/jpeg" sizes="48x48">
                <link rel="apple-touch-icon" href="<?php echo $basePath; ?>/assets/images/LogoStaffeasePro.png" sizes="180x180">
                <link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
                <meta property="og:title" content="<?php echo e($pageTitle); ?>">
                <meta property="og:description" content="<?php echo e($metaDescription); ?>">
                <meta property="og:site_name" content="StaffEase Pro">
                <meta property="og:locale" content="<?php echo e($ogLocale); ?>">
                <meta property="og:type" content="website">
                <meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
                <meta name="twitter:card" content="summary_large_image">
                <meta name="twitter:title" content="<?php echo e($pageTitle); ?>">
                <meta name="twitter:description" content="<?php echo e($metaDescription); ?>">
                <meta name="google-site-verification" content="yXCBl93H9JHS1gQ_j8dIrmm-aWG3tK0cB2EnDLlNczs" />
                <?php if ($isPublicRoute): ?>
                <script type="application/ld+json"><?php echo json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
                <?php endif; ?>
                <title><?php echo e($pageTitle); ?></title>
        <link rel="icon" href="<?php echo $basePath; ?>/assets/images/faviconStaffeasePro.jpg" type="image/jpeg">
                <link rel="preload" href="<?php echo $basePath; ?>/assets/images/LogoStaffeasePro.png" as="image" fetchpriority="high">
                <link rel="preload" href="<?php echo $basePath; ?>/assets/css/<?php echo e($stylesheetFile); ?>?v=<?php echo e($cssVersion); ?>" as="style">
        <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/<?php echo e($stylesheetFile); ?>?v=<?php echo e($cssVersion); ?>">
                <?php if ($hasFlashUi): ?>
                <script defer src="<?php echo $basePath; ?>/assets/js/flash.js?v=<?php echo filemtime(__DIR__ . '/assets/js/flash.js'); ?>"></script>
                <?php endif; ?>
</head>
<body class="<?php echo e(implode(' ', $bodyClasses)); ?>">
        <?php if ($shouldShowLoadingOverlay): ?>
    <div id="loading-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(255, 255, 255, 0.9); display: flex; justify-content: center; align-items: center; z-index: 9999;">
        <div style="text-align: center;">
            <img src="<?php echo $basePath; ?>/assets/icons/loader-circle.svg" alt="Loading..." style="width: 50px; height: 50px; animation: spin 1s linear infinite; display: block; margin: 0 auto;">
            <p style="margin-top: 10px; font-size: 16px; color: #333;"><?php echo e(t('loading.message')); ?></p>
        </div>
    </div>
    <style>
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
    <script>
        window.addEventListener('load', function() {
            document.getElementById('loading-overlay').style.display = 'none';
        });
    </script>
        <?php endif; ?>

<a class="skip-link" href="#main-content"><?php echo e(t('common.quick_actions')); ?> - Skip to content</a>

<?php
// Shared header used by all pages.
require __DIR__ . '/app/layout/header.php';
?>

<?php if ($isDashboardRoute): ?>
<?php
$currentRole = (string) (currentUser()['role'] ?? '');
$hasScopedCompanyContext = ((int) ($_GET['settings_company_id'] ?? 0) > 0)
        || ((int) ($dashboardPlannerData['company']['id'] ?? 0) > 0);
$showDashboardSidebar = $currentRole !== 'super_admin' || $hasScopedCompanyContext;
?>
<?php if ($showDashboardSidebar): ?>
<?php require __DIR__ . '/app/layout/sidebar.php'; ?>
<?php endif; ?>
<?php require __DIR__ . '/app/layout/settings-panel.php'; ?>
<?php require __DIR__ . '/app/layout/schedule.php'; ?>
<?php require __DIR__ . '/app/layout/print-modal.php'; ?>
<?php endif; ?>

<main id="main-content" class="<?php echo $isCompactDashboard ? 'dashboard-content' : 'content' . ($isDashboardRoute ? ' content-dashboard' : ''); ?>" tabindex="-1">
<?php if ($flashSuccess !== null): ?>
                <div id="flash-backdrop-success" class="flash-backdrop"></div>
                <div id="flash-success" class="flash flash-success" role="alert" aria-live="assertive">
                                <span class="flash-icon" aria-hidden="true">
                                        <img src="<?php echo $basePath; ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" />
                                </span>
                        <div class="flash-body">
                                <div class="flash-title"><?php echo e(t('common.done')); ?></div>
                                <p><?php echo e($flashSuccess); ?></p>
                        </div>
                </div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
                <div id="flash-backdrop-error" class="flash-backdrop"></div>
                <div id="flash-error" class="flash flash-error" role="alert" aria-live="assertive">
                        <span class="flash-icon" aria-hidden="true">
                                <img src="<?php echo $basePath; ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" />
                        </span>
                        <div class="flash-body">
                                <div class="flash-title"><?php echo e(t('common.oops')); ?></div>
                                <p><?php echo e($flashError); ?></p>
                        </div>
                </div>
<?php endif; ?>

<?php
// Final view resolved by the router.
require $viewFile;
?>
</main>

<?php if ($isPublicRoute): ?>
<footer class="site-reveal-footer" data-reveal-footer aria-label="<?php echo e(t('common.quick_actions')); ?>">
        <div class="site-reveal-footer-inner">
                <a class="site-reveal-footer-link" href="<?php echo e(appUrl('legal')); ?>"><?php echo e(t('common.legal_mentions')); ?></a>
                <a class="site-reveal-footer-link" href="<?php echo e(appUrl('commercial')); ?>"><?php echo e(t('common.commercial')); ?></a>
                <a class="site-reveal-footer-link" href="<?php echo e(appUrl('contacts')); ?>"><?php echo e(t('common.contacts')); ?></a>
                <a class="site-reveal-footer-link" href="<?php echo e(appUrl('creator')); ?>"><?php echo e(t('common.app_creator')); ?></a>
        </div>
</footer>
<?php endif; ?>

<?php if ($isDashboardRoute): ?>
<?php require __DIR__ . '/app/layout/crud-modal.php'; ?>
<?php endif; ?>

<?php if ($requiresApiClient): ?>
<script defer src="<?php echo $basePath; ?>/assets/js/api.js?v=<?php echo e($apiVersion); ?>"></script>
<?php endif; ?>
<?php if ($isDashboardRoute || $isMySpaceRoute): ?>
<script defer src="<?php echo $basePath; ?>/assets/js/signature-pad.js?v=<?php echo filemtime(__DIR__ . '/assets/js/signature-pad.js'); ?>"></script>
<?php endif; ?>
<?php if ($isDashboardRoute): ?>
<script>
        window.DashboardConfig = {
                apiCompanies: '<?php echo appUrl('api-companies'); ?>',
                apiDepartments: '<?php echo appUrl('api-departments'); ?>',
                apiUsers: '<?php echo appUrl('api-users'); ?>',
                apiDashboard: '<?php echo appUrl('api-dashboard'); ?>',
                apiCommercial: '<?php echo appUrl('api-commercial'); ?>',
                apiShifts: '<?php echo appUrl('api-shifts'); ?>',
                iconsBase: '<?php echo $basePath; ?>/assets/icons/',
                pdfjsLibSrc: '<?php echo $basePath; ?>/assets/js/vendor/pdfjs/pdf.min.js?v=<?php echo filemtime(__DIR__ . '/assets/js/vendor/pdfjs/pdf.min.js'); ?>',
                pdfjsWorkerSrc: '<?php echo $basePath; ?>/assets/js/vendor/pdfjs/pdf.worker.min.js'
        };
        window.DashboardCurrentUser = <?php echo json_encode([
                'id' => (int) (currentUser()['id'] ?? 0),
                'name' => trim((string) ((currentUser()['first_name'] ?? '') . ' ' . (currentUser()['last_name'] ?? ''))),
                'role' => (string) (currentUser()['role'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        window.DashboardPlannerData = <?php echo json_encode($dashboardPlannerData ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/sidebar.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/sidebar.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/navigator.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/navigator.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/calendar-renderer.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/calendar-renderer.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/calendar.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/calendar.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/feedback.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/feedback.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/dnd.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/dnd.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/departments.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/departments.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/users.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/users.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/shifts.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/shifts.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/companies.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/companies.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/commercial-videos.js?v=<?php echo e($commercialVideosVersion); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/attendances.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/attendances.js'); ?>"></script>
<script defer src="<?php echo $basePath; ?>/assets/js/dashboard/print.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard/print.js'); ?>"></script>
<?php endif; ?>

<?php if ($isMySpaceRoute && isLoggedIn()): ?>
<script defer src="<?php echo $basePath; ?>/assets/js/employee-space.js?v=<?php echo e($employeeSpaceVersion); ?>"></script>
<?php endif; ?>

<script defer src="<?php echo $basePath; ?>/assets/js/public-ui.js?v=<?php echo e($publicUiVersion); ?>"></script>

<script defer src="<?php echo $basePath; ?>/assets/js/ui-hints.js?v=<?php echo e($uiHintsVersion); ?>"></script>

</body>
</html>