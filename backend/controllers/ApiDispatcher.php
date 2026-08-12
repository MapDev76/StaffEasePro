<?php
/**
 * API dispatcher (front controller for API routes).
 *
 * Maps `?route=api-...` to the corresponding small API controller file under
 * `backend/controllers`. Controllers are responsible for auth and JSON output.
 */
require_once __DIR__ . '/../bootstrap.php';

$route = $_GET['route'] ?? '';

$map = [
    'api-companies' => __DIR__ . '/ApiCompanyController.php',
    'api-departments' => __DIR__ . '/ApiDepartmentController.php',
    'api-users' => __DIR__ . '/ApiUserController.php',
    'api-shifts' => __DIR__ . '/ApiShiftController.php',
    'api-dashboard' => __DIR__ . '/ApiDashboardController.php',
    'api-commercial' => __DIR__ . '/ApiCommercialController.php',
    'api-assistant' => __DIR__ . '/ApiAssistantController.php',
];

if (!isset($map[$route])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unknown API route']);
    exit;
}

// Include the mapped controller. Controllers will handle auth/response.
require_once $map[$route];
