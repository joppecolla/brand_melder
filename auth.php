<?php
// auth.php - gedeelde functies: database, sessie, inloggen, CSRF-beveiliging en pagina-opmaak
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
date_default_timezone_set('Europe/Brussels');

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = getenv('NEON_HOST');
    $name = getenv('NEON_DB');
    $user = getenv('NEON_USER');
    $pass = getenv('NEON_PASS');
    $port = getenv('NEON_PORT') ?: '5432';
    try {
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name;sslmode=require", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        error_log('auth.php database: ' . $e->getMessage());
        http_response_code(500);
        die('Databaseverbinding mislukt.');
    }
    return $pdo;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- CSRF ----------
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrfVeld(): string { return '<input type="hidden" name="csrf" value="' . csrfToken() . '">'; }
function csrfControle(): void {
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        die('Ongeldig formulier (sessie verlopen). Ga terug, ververs de pagina en probeer opnieuw.');
    }
}

// ---------- Gebruiker en melder ----------
function huidigeGebruiker(PDO $pdo): ?array {
    if (empty($_SESSION['gebruiker_id'])) return null;
    $st = $pdo->prepare("SELECT id, email FROM gebruikers WHERE id = ?");
    $st->execute([(int)$_SESSION['gebruiker_id']]);
    return $st->fetch() ?: null;
}
function vereisLogin(PDO $pdo): array {
    $g = huidigeGebruiker($pdo);
    if (!$g) { header('Location: inloggen.php'); exit; }
    return $g;
}
function eersteMelder(PDO $pdo, int $gebruikerId): ?array {
    $st = $pdo->prepare("SELECT melder_id, naam FROM melders WHERE gebruiker_id = ? ORDER BY created_at LIMIT 1");
    $st->execute([$gebruikerId]);
    return $st->fetch() ?: null;
}
function loginSessie(int $gebruikerId): void {
    session_regenerate_id(true);
    $_SESSION['gebruiker_id'] = $gebruikerId;
}

// Geheime sleutel van de melder: willekeurig, en in de database alleen als hash
function nieuweSleutel(): string { return bin2hex(random_bytes(16)); }
function sleutelHash(string $sleutel): string { return hash('sha256', $sleutel); }

// ---------- Pagina-opmaak ----------
function paginaKop(string $titel): void {
    $ingelogd = !empty($_SESSION['gebruiker_id']);
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>' . h($titel) . ' | Brand-Melder</title><link rel="stylesheet" href="style.css"></head><body>'
       . '<header class="sticky-header" id="topbar"><a href="index.php" class="logo"><span class="logo-icon">🚨</span>Brand-Melder</a><nav>'
       . '<a href="index.php">Home</a><a href="dashBoard.php">Dashboard</a><a href="admin.php">Admin</a>';
    if ($ingelogd) {
        echo '<a href="account.php">Account</a><a href="uitloggen.php" class="btn-dashboard">Uitloggen</a>';
    } else {
        echo '<a href="inloggen.php">Inloggen</a><a href="registreren.php" class="btn-dashboard">Registreren</a>';
    }
    echo '</nav></header><main class="auth-page">';
}
function paginaVoet(): void {
    echo '</main></body></html>';
}
