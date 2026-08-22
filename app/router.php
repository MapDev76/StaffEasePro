<?php
// Simple router: maps a route value to a controller or view.
$route = function_exists('appRouteFromRequest') ? appRouteFromRequest() : ($_GET['route'] ?? 'home');

$routes = [
    'home' => realpath(__DIR__ . '/../public/views/home.php'),
    'commercial' => realpath(__DIR__ . '/../public/views/commercial.php'),
    'legal' => realpath(__DIR__ . '/../public/views/legal.php'),
    'contacts' => realpath(__DIR__ . '/../public/views/contacts.php'),
    'creator' => realpath(__DIR__ . '/../public/views/creator.php'),
    'giulia' => realpath(__DIR__ . '/../public/views/giulia.php'),
    'login' => realpath(__DIR__ . '/../backend/controllers/AuthController.php'),
    'logout' => realpath(__DIR__ . '/../backend/controllers/AuthController.php'),
    'register' => realpath(__DIR__ . '/../backend/controllers/RegisterController.php'),
    'forgot-password' => realpath(__DIR__ . '/../backend/controllers/ForgotPasswordController.php'),
    'company-approval' => realpath(__DIR__ . '/../backend/controllers/CompanyApprovalController.php'),
    'request-authorization' => realpath(__DIR__ . '/../backend/controllers/RequestAuthorizationController.php'),
    'reset-password' => realpath(__DIR__ . '/../backend/controllers/ResetPasswordController.php'),
    'dashboard' => realpath(__DIR__ . '/../backend/controllers/DashboardController.php'),
    'api-dashboard' => realpath(__DIR__ . '/../backend/controllers/ApiDispatcher.php'),
    'api-companies' => realpath(__DIR__ . '/../backend/controllers/ApiDispatcher.php'),
    'api-departments' => realpath(__DIR__ . '/../backend/controllers/ApiDispatcher.php'),
    'api-users' => realpath(__DIR__ . '/../backend/controllers/ApiDispatcher.php'),
    'api-shifts' => realpath(__DIR__ . '/../backend/controllers/ApiDispatcher.php'),
    'api-commercial' => realpath(__DIR__ . '/../backend/controllers/ApiDispatcher.php'),
    'api-assistant' => realpath(__DIR__ . '/../backend/controllers/ApiDispatcher.php'),
    'api-notifications' => realpath(__DIR__ . '/../backend/controllers/ApiNotificationsController.php'),
    'api-sync' => realpath(__DIR__ . '/../backend/controllers/SyncApiController.php'), // read-only export for sister apps (e.g. HotelEase Pro)
    'api-sync-update' => realpath(__DIR__ . '/../backend/controllers/SyncApiUpdateController.php'), // write-back company updates from sister apps
    'api-sync-create' => realpath(__DIR__ . '/../backend/controllers/SyncApiCreateController.php'), // create a company from a sister app (link flow)
    'document-download' => realpath(__DIR__ . '/../backend/controllers/DocumentDownloadController.php'),
    'my-space' => realpath(__DIR__ . '/../backend/controllers/EmployeeSpaceController.php'),
    'users' => realpath(__DIR__ . '/../backend/controllers/UsersController.php'),
    'companies' => realpath(__DIR__ . '/../backend/controllers/CompaniesController.php'),
    'departments' => realpath(__DIR__ . '/../backend/controllers/DepartmentsController.php'),
    '404' => realpath(__DIR__ . '/../public/views/404.php'),
];

return $routes[$route] ?? $routes['404'];
