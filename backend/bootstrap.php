<?php

// Bootstrap file: loads the shared dependencies before controllers and views.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/assistant.php';

date_default_timezone_set(appTimezoneName());

// startAppSession() is defined in helpers.php.  Call it here so every
// controller and view can safely use flash data and auth helpers from the
// very first line.
startAppSession();

try {
	$bootstrapPdo = getPDO();
	ensureConnectionTrackingSchema($bootstrapPdo);
	if (isLoggedIn()) {
		enforceActiveSession($bootstrapPdo);
	}
} catch (Throwable $e) {
	// Ignore tracking failures so the application can continue serving pages.
}
