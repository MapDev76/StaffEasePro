<?php

/**
 * Write-back endpoint for sister apps (e.g. HotelEase Pro) to update a
 * company's fields here when it's edited on their side. Additive and
 * self-contained, same auth as SyncApiController.php (shared API key) —
 * nothing to do with the session-based dashboard auth.
 *
 * POST /?route=api-sync-update
 * Header: X-Api-Key: <key from config/sync_api.php>
 * Body (JSON): { "id": 2, "name": "...", "city": "...", ... }
 * Only company identity/contact fields — no departments/users writes here.
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
if (!is_array($body) || empty($body['id'])) {
    jsonResponse(['error' => 'Missing id'], 422);
}

$pdo = getPDO();
$companyModel = new CompanyModel($pdo);

$id = (int) $body['id'];
$existing = $companyModel->findById($id);
if (!$existing) {
    jsonResponse(['error' => 'Company not found'], 404);
}

// CompanyModel::update() replaces every field it manages wholesale (its own
// edit form always submits the full set) — including logo_path/signature_ip/
// default_locale when those columns exist — so merge onto the existing row
// for ALL of them, not just the ones this endpoint accepts as input.
// Otherwise untouched columns (e.g. logo_path) get silently nulled out.
$allowedFields = ['name', 'type', 'address', 'city', 'zip_code', 'phone', 'email', 'logo_path'];
$modelManagedFields = [...$allowedFields, 'signature_ip', 'default_locale'];
$incoming = array_intersect_key($body, array_fill_keys($allowedFields, true));

if (empty($incoming)) {
    jsonResponse(['error' => 'No updatable fields provided'], 422);
}

$data = array_merge(array_intersect_key($existing, array_fill_keys($modelManagedFields, true)), $incoming);

$companyModel->update($id, $data);

jsonResponse(['data' => $companyModel->findById($id)]);
