<?php

/**
 * Read-only export endpoint for sister apps (e.g. HotelEase Pro) to pull
 * companies/departments/users. Additive and self-contained: reuses the
 * existing models and DB connection but has nothing to do with the
 * session-based dashboard auth (AuthController, ApiDispatcher/Api*Controller)
 * — a single shared API key is all that's required.
 *
 * GET /?route=api-sync
 * Header: X-Api-Key: <key from config/sync_api.php>
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/CompanyModel.php';
require_once __DIR__ . '/../models/DepartmentModel.php';
require_once __DIR__ . '/../models/UserModel.php';

$config = require __DIR__ . '/../../config/sync_api.php';
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');

if (!hash_equals((string) $config['api_key'], (string) $providedKey)) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$pdo = getPDO();

$companies = (new CompanyModel($pdo))->all();
$departments = (new DepartmentModel($pdo))->allWithCompany();

$users = array_map(function (array $user) {
    unset($user['password']); // never export password hashes
    return $user;
}, (new UserModel($pdo))->allWithRelations());

jsonResponse([
    'companies' => $companies,
    'departments' => $departments,
    'users' => $users,
    'generated_at' => date('c'),
]);
