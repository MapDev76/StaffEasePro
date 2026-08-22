<?php

/**
 * Write-back endpoint for sister apps (e.g. HotelEase Pro) to create a new
 * company here — used when a company created locally in HotelEase Pro gets
 * "linked" to StaffEase Pro (becomes the source of truth for it going
 * forward). Additive and self-contained, same auth as the other sync
 * endpoints (shared API key).
 *
 * POST /?route=api-sync-create
 * Header: X-Api-Key: <key from config/sync_api.php>
 * Body (JSON): { "name": "...", "type": "...", "city": "...", ... }
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/CompanyModel.php';

$config = require __DIR__ . '/../../config/sync_api.php';
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');

if (!hash_equals((string) $config['api_key'], (string) $providedKey)) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['name'])) {
    jsonResponse(['error' => 'Missing name'], 422);
}

$allowedFields = ['name', 'type', 'address', 'city', 'zip_code', 'phone', 'email'];
$data = array_intersect_key($body, array_fill_keys($allowedFields, true));

$pdo = getPDO();
$companyModel = new CompanyModel($pdo);
$id = $companyModel->create($data);

jsonResponse(['data' => $companyModel->findById($id)], 201);
