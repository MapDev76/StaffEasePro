<?php
/**
 * API endpoint for commercial-page media settings.
 *
 * Only accessible to authenticated Super Admin users. Supports list/update of
 * the YouTube video entries shown on the public commercial page.
 */
require_once __DIR__ . '/../bootstrap.php';

if (!isLoggedIn() || !isSuperAdmin()) {
    jsonResponse(['ok' => false, 'error' => t('common.unauthorized')], 403);
}

$input = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
$action = (string) ($input['action'] ?? 'update');

try {
    switch ($action) {
        case 'list':
            jsonResponse(['ok' => true, 'videos' => getCommercialVideos()]);
            break;

        case 'update':
            $videos = $input['videos'] ?? [];
            if (!is_array($videos)) {
                jsonResponse(['ok' => false, 'error' => 'Invalid videos payload.'], 400);
            }

            $savedVideos = saveCommercialVideos($videos);
            jsonResponse(['ok' => true, 'videos' => $savedVideos]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $exception) {
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 400);
}