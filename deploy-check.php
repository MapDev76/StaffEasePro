<?php
/**
 * Post-deployment self-check.
 *
 * Upload this file to the web root, open it in a browser, fix whatever is red,
 * then DELETE IT. It reports the environment, the database connection, folder
 * permissions and — most importantly on free hosting — whether outbound HTTPS
 * to the Brevo API is allowed at all.
 *
 * It intentionally does not require bootstrap.php, so it still runs (and
 * explains why) when the app itself is broken.
 */

header('Content-Type: text/html; charset=UTF-8');

$rows = [];
function report(string $section, string $label, bool $ok, string $detail = '', bool $warnOnly = false): void
{
    global $rows;
    $rows[] = compact('section', 'label', 'ok', 'detail', 'warnOnly');
}

// ---------------------------------------------------------------- PHP runtime
report('PHP', 'Versione PHP >= 8.0', PHP_VERSION_ID >= 80000, PHP_VERSION);
foreach (['pdo_mysql', 'curl', 'mbstring', 'json', 'fileinfo', 'gd'] as $ext) {
    $optional = $ext === 'gd';
    report('PHP', "Estensione $ext", extension_loaded($ext),
        extension_loaded($ext) ? 'ok' : ($optional ? 'serve solo per rigenerare il logo email' : 'RICHIESTA'), $optional);
}

// -------------------------------------------------------------------- Rewrite
$rewrite = in_array('mod_rewrite', function_exists('apache_get_modules') ? apache_get_modules() : [], true);
report('Server', 'mod_rewrite', $rewrite || !function_exists('apache_get_modules'),
    function_exists('apache_get_modules') ? ($rewrite ? 'attivo' : 'NON attivo: gli URL puliti daranno 404')
        : 'non verificabile da PHP: prova ad aprire /login');
report('Server', 'File .htaccess presente', is_file(__DIR__ . '/.htaccess'));

// ------------------------------------------------------------------- Database
$dbFile = __DIR__ . '/config/database.php';
report('Database', 'config/database.php presente', is_file($dbFile));
if (is_file($dbFile)) {
    $cfg = require $dbFile;
    report('Database', 'Host NON e localhost', ($cfg['host'] ?? '') !== '127.0.0.1' && ($cfg['host'] ?? '') !== 'localhost',
        'host configurato: ' . ($cfg['host'] ?? '(vuoto)') . ' - su InfinityFree deve essere sqlNNN.infinityfree.com');
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $cfg['host'] ?? '', $cfg['port'] ?? 3306, $cfg['database'] ?? '', $cfg['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        report('Database', 'Connessione riuscita', true, 'server: ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        report('Database', 'Tabelle importate', count($tables) >= 8, count($tables) . ' tabelle: ' . implode(', ', array_slice($tables, 0, 12)));
        foreach (['users', 'companies', 'departments', 'shifts'] as $t) {
            report('Database', "Tabella $t", in_array($t, $tables, true));
        }
        $canAlter = false;
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS _deploy_probe (id INT PRIMARY KEY)');
            $pdo->exec('DROP TABLE _deploy_probe');
            $canAlter = true;
        } catch (Throwable $e) {
        }
        report('Database', 'Permesso CREATE/DROP', $canAlter,
            $canAlter ? 'ok: le tabelle nuove verranno create da sole' : 'senza questo le tabelle password_resets e company_approvals non si creano');
    } catch (Throwable $e) {
        report('Database', 'Connessione riuscita', false, $e->getMessage());
    }
}

// ---------------------------------------------------------------- Permessi FS
foreach (['public/uploads', 'public/uploads/company-logos', 'storage/logs'] as $dir) {
    $path = __DIR__ . '/' . $dir;
    $exists = is_dir($path);
    if (!$exists) {
        @mkdir($path, 0755, true);
        $exists = is_dir($path);
    }
    report('Cartelle', "$dir scrivibile", $exists && is_writable($path),
        $exists ? (is_writable($path) ? 'ok' : 'NON scrivibile: imposta i permessi a 755') : 'cartella mancante');
}

// -------------------------------------------------------------------- Email
$mailFile = __DIR__ . '/config/mail.php';
report('Email', 'config/mail.php presente', is_file($mailFile), is_file($mailFile) ? 'ok' : 'da creare a mano: non viene caricato da git');
if (is_file($mailFile)) {
    $mail = require $mailFile;
    report('Email', 'API key valorizzata', trim((string) ($mail['api_key'] ?? '')) !== '');
    report('Email', 'Mittente valorizzato', trim((string) ($mail['sender_email'] ?? '')) !== '');
    $base = (string) ($mail['app_base_url'] ?? '');
    report('Email', 'app_base_url NON e localhost', $base !== '' && !str_contains($base, 'localhost'),
        'valore: ' . ($base !== '' ? $base : '(vuoto)'));
}

// Il punto piu critico dell'hosting gratuito: molte offerte free bloccano le
// connessioni in uscita, e senza quelle Brevo non e raggiungibile.
if (function_exists('curl_init')) {
    $ch = curl_init('https://api.brevo.com/v3/account');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'api-key: ' . (string) ($mail['api_key'] ?? 'x')],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    report('Email', 'Connessione in uscita verso Brevo', $code > 0,
        $code > 0 ? "HTTP $code" . ($code === 401 ? ' (chiave o IP del server non autorizzati: aggiungi l IP del server su Brevo)' : ' (rete ok)')
                  : "BLOCCATA dall hosting: $err");
}

// ------------------------------------------------------------------ Rendering
$fail = count(array_filter($rows, static fn($r) => !$r['ok'] && !$r['warnOnly']));
$warn = count(array_filter($rows, static fn($r) => !$r['ok'] && $r['warnOnly']));
?>
<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><title>StaffEase Pro - verifica pubblicazione</title>
<style>
 body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f3f4f6;margin:0;padding:28px}
 .card{max-width:860px;margin:0 auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.08)}
 h1{margin:0 0 4px;font-size:22px} .sub{color:#6b7280;margin:0 0 18px}
 table{width:100%;border-collapse:collapse} td{padding:8px 6px;border-bottom:1px solid #eef0f3;font-size:14px;vertical-align:top}
 .sec{font-weight:700;background:#f9fafb;color:#374151}
 .ok{color:#15803d;font-weight:700} .ko{color:#b91c1c;font-weight:700} .wa{color:#b45309;font-weight:700}
 .d{color:#6b7280;font-size:13px} .banner{padding:12px 14px;border-radius:10px;margin-bottom:16px;font-weight:600}
 .b-ok{background:#dcfce7;color:#166534} .b-ko{background:#fee2e2;color:#991b1b}
 .warn{margin-top:18px;padding:12px 14px;background:#fef3c7;color:#92400e;border-radius:10px;font-size:14px}
</style></head>
<body><div class="card">
<h1>StaffEase Pro - verifica pubblicazione</h1>
<p class="sub">Sistema questo elenco, poi <strong>cancella questo file dal server</strong>.</p>
<div class="banner <?php echo $fail === 0 ? 'b-ok' : 'b-ko'; ?>">
<?php echo $fail === 0 ? 'Tutti i controlli obbligatori superati' . ($warn ? " ($warn avviso/i)" : '') : "$fail controlli falliti"; ?>
</div>
<table>
<?php $cur = ''; foreach ($rows as $r): ?>
    <?php if ($r['section'] !== $cur): $cur = $r['section']; ?>
        <tr><td class="sec" colspan="2"><?php echo htmlspecialchars($cur); ?></td></tr>
    <?php endif; ?>
    <tr>
        <td style="width:34px" class="<?php echo $r['ok'] ? 'ok' : ($r['warnOnly'] ? 'wa' : 'ko'); ?>">
            <?php echo $r['ok'] ? '&#10003;' : ($r['warnOnly'] ? '!' : '&#10007;'); ?>
        </td>
        <td><?php echo htmlspecialchars($r['label']); ?>
            <?php if ($r['detail'] !== ''): ?><div class="d"><?php echo htmlspecialchars($r['detail']); ?></div><?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</table>
<div class="warn">Per sicurezza cancella <code>deploy-check.php</code> appena finito: mostra dettagli di configurazione.</div>
</div></body></html>
