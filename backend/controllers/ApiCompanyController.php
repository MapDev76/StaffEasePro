<?php
/**
 * API endpoint for company operations used by the dashboard.
 *
 * Only accessible to authenticated Super Admin users. Supports JSON or
 * form-encoded requests with `action` keys such as list, create, update,
 * delete and set_signature_ip. Returns JSON responses.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../models/CompanyModel.php';
require_once __DIR__ . '/../models/DepartmentModel.php';

if (!isLoggedIn()) {
            jsonResponse(['error' => t('common.unauthorized')], 403);
}

$profile = currentUser();
$isSuperAdmin = isSuperAdmin();
$isAdmin = (($profile['role'] ?? '') === 'admin');
if (!$isSuperAdmin && !$isAdmin) {
            jsonResponse(['error' => t('common.unauthorized')], 403);
}

$pdo = getPDO();
ensureCompanyApprovalSchema($pdo);

try {
    $logoColStmt = $pdo->query("SHOW COLUMNS FROM companies LIKE 'logo_path'");
    $hasLogoColumn = (bool) $logoColStmt->fetch();
    if (!$hasLogoColumn) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN logo_path VARCHAR(255) NULL AFTER email");
    }

    $signatureColStmt = $pdo->query("SHOW COLUMNS FROM companies LIKE 'signature_ip'");
    $hasSignatureColumn = (bool) $signatureColStmt->fetch();
    if (!$hasSignatureColumn) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN signature_ip VARCHAR(45) NULL AFTER logo_path");
    }

    $activeColStmt = $pdo->query("SHOW COLUMNS FROM companies LIKE 'is_active'");
    $hasActiveColumn = (bool) $activeColStmt->fetch();
    if (!$hasActiveColumn) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER signature_ip");
    }
} catch (Throwable $e) {
    // Keep API usable even if ALTER is not allowed in current environment.
}

$companyModel = new CompanyModel($pdo);

$resolveDefaultLocaleInput = static function (array $input): ?string {
    $locale = strtolower(trim((string) ($input['default_locale'] ?? '')));

    return in_array($locale, appSupportedLocales(), true) ? $locale : null;
};

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;
$action = $input['action'] ?? ($_GET['action'] ?? 'list');

$resolveAdminCompanyId = static function () use ($pdo, $profile): int {
    $adminCompanyId = (int) currentUserCompanyId($profile);
    if ($adminCompanyId > 0) {
        return $adminCompanyId;
    }

    $userStmt = $pdo->prepare(
        'SELECT d.company_id
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = :user_id
         LIMIT 1'
    );
    $userStmt->execute(['user_id' => (int) ($profile['id'] ?? 0)]);
    return (int) ($userStmt->fetchColumn() ?: 0);
};

$storeUploadedLogo = static function (string $field = 'logo_file'): ?string {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file)) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logo upload failed.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $fileSize = (int) ($file['size'] ?? 0);
    if ($tmpPath === '' || (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) || $fileSize <= 0 || $fileSize > (4 * 1024 * 1024)) {
        throw new RuntimeException('Invalid logo upload. Max size is 4MB.');
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = @finfo_file($finfo, $tmpPath);
            if (is_string($detected) && $detected !== '') {
                $mime = strtolower(trim($detected));
            }
            @finfo_close($finfo);
        }
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Unsupported logo format. Use PNG, JPG, WEBP, GIF or SVG.');
    }

    $uploadDir = __DIR__ . '/../../public/uploads/company-logos';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create logo upload directory.');
    }

    $fileName = 'company-logo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $destination = $uploadDir . '/' . $fileName;
    if (!@move_uploaded_file($tmpPath, $destination)) {
        if (!@rename($tmpPath, $destination)) {
            if (!@copy($tmpPath, $destination)) {
                throw new RuntimeException('Unable to store uploaded logo.');
            }
        }
    }

    return 'public/uploads/company-logos/' . $fileName;
};

try {
    switch ($action) {
        case 'list':
            $rows = $companyModel->directoryWithAdminsAndDepartments();
            if ($isAdmin && !$isSuperAdmin) {
                $adminCompanyId = $resolveAdminCompanyId();
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => (int) ($row['id'] ?? 0) === $adminCompanyId
                ));
            }
            jsonResponse(['ok' => true, 'companies' => $rows]);
            break;

        case 'create':
            if (!$isSuperAdmin) {
                jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                jsonResponse(['ok' => false, 'error' => 'Name is required'], 400);
            }

            $uploadedLogoPath = $storeUploadedLogo('logo_file');
            $logoPathInput = trim((string) ($input['logo_path'] ?? ''));

            $data = [
                'name' => $name,
                'type' => $input['type'] ?? 'other',
                'address' => $input['address'] ?? null,
                'city' => $input['city'] ?? null,
                'zip_code' => $input['zip_code'] ?? null,
                'phone' => $input['phone'] ?? null,
                'email' => $input['email'] ?? null,
                'logo_path' => $uploadedLogoPath ?? ($logoPathInput !== '' ? $logoPathInput : null),
                'signature_ip' => $input['signature_ip'] ?? null,
                'default_locale' => $resolveDefaultLocaleInput($input),
            ];

            $id = $companyModel->create($data);
            // Ensure a default 'Reception' department exists for this company.
            $departmentModel = new DepartmentModel($pdo);
            $existing = $departmentModel->findByNameAndCompanyId('Reception', (int) $id);
            if (!$existing) {
                $departmentModel->create([
                    'company_id' => $id,
                    'name' => 'Reception',
                    'icon' => '🏨',
                    'color' => '#b98b12',
                    'description' => 'Reception department',
                    'head_user_id' => null,
                ]);
            }
            $company = $companyModel->findById($id);

            // Tell the super admins a new company is live.
            try {
                $superAdmins = $pdo->query(
                    "SELECT first_name, last_name, email FROM users WHERE role = 'super_admin' AND status = 'active'"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $currentActor = currentUser() ?? [];
                $actorName = trim((string) (($currentActor['first_name'] ?? '') . ' ' . ($currentActor['last_name'] ?? '')));
                sendCompanyCreatedEmail($company ?? $data, $superAdmins, $actorName);
            } catch (Throwable $mailError) {
                mailLog('company_created notification failed: ' . $mailError->getMessage());
            }

            jsonResponse(['ok' => true, 'company' => $company]);
            break;

        case 'update':
            if (!$isSuperAdmin) {
                jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(['ok' => false, 'error' => 'Invalid id'], 400);
            }

            $uploadedLogoPath = $storeUploadedLogo('logo_file');
            $logoPathInput = trim((string) ($input['logo_path'] ?? ''));

            $data = [
                'name' => $input['name'] ?? '',
                'type' => $input['type'] ?? 'other',
                'address' => $input['address'] ?? null,
                'city' => $input['city'] ?? null,
                'zip_code' => $input['zip_code'] ?? null,
                'phone' => $input['phone'] ?? null,
                'email' => $input['email'] ?? null,
                'logo_path' => $uploadedLogoPath ?? ($logoPathInput !== '' ? $logoPathInput : null),
                'signature_ip' => $input['signature_ip'] ?? null,
                'default_locale' => $resolveDefaultLocaleInput($input),
            ];

            $companyModel->update($id, $data);
            jsonResponse(['ok' => true]);
            break;

        case 'delete':
            if (!$isSuperAdmin) {
                jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(['ok' => false, 'error' => 'Invalid id'], 400);
            }

            $companyModel->delete($id);
            jsonResponse(['ok' => true]);
            break;

        case 'set_signature_ip':
            $companyId = (int) ($input['company_id'] ?? 0);
            $ip = trim((string) ($input['ip'] ?? ''));
            if ($companyId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'Invalid company_id'], 400);
            }

            if ($isAdmin) {
                $adminCompanyId = $resolveAdminCompanyId();

                if ($adminCompanyId <= 0 || $companyId !== $adminCompanyId) {
                    jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
                }
            }

            // Ensure the column exists before update.
            $colStmt = $pdo->query("SHOW COLUMNS FROM companies LIKE 'signature_ip'");
            $has = $colStmt->fetch();
            if (!$has) {
                $pdo->exec("ALTER TABLE companies ADD COLUMN signature_ip VARCHAR(45) NULL AFTER email");
            }

            $stmt = $pdo->prepare('UPDATE companies SET signature_ip = :ip WHERE id = :id');
            $stmt->execute(['ip' => $ip ?: null, 'id' => $companyId]);

            jsonResponse(['ok' => true]);
            break;

        case 'set_active':
            if (!$isSuperAdmin) {
                jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
            }

            $companyId = (int) ($input['company_id'] ?? 0);
            $isActive = (int) ((int) ($input['is_active'] ?? 1) > 0 ? 1 : 0);
            if ($companyId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'Invalid company_id'], 400);
            }

            $stmt = $pdo->prepare('UPDATE companies SET is_active = :is_active WHERE id = :id');
            $stmt->execute([
                'is_active' => $isActive,
                'id' => $companyId,
            ]);

            jsonResponse(['ok' => true, 'is_active' => $isActive]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
