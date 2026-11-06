<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);

$host = getenv('NEON_HOST');
$db   = getenv('NEON_DB');
$user = getenv('NEON_USER');
$pass = getenv('NEON_PASS');
$port = getenv('NEON_PORT') ?: '5432';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db;sslmode=require", $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]);
} catch (PDOException $e) {
    error_log('api.php database: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'fout' => 'database', 'detail' => getenv('API_DEBUG') ? $e->getMessage() : null]);
    exit;
}

function pgBool($v): bool { return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1'; }
function meld(PDO $pdo, string $tekst, string $niveau): void {
    $pdo->prepare("INSERT INTO meldingen (tekst, niveau) VALUES (?, ?)")->execute([$tekst, $niveau]);
}

// GET = status opvragen (handig om te testen)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $r = $pdo->query("SELECT melder_aan, muziek FROM melder_status WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'melder' => pgBool($r['melder_aan']), 'muziek' => pgBool($r['muziek'])]);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'fout' => 'ongeldige json']);
    exit;
}

$stap = 'start';
try {
    $stap = 'melder_status lezen';
    $old = $pdo->query("SELECT * FROM melder_status WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$old) { throw new RuntimeException('melder_status heeft geen rij met id = 1'); }

    $status      = (($in['status'] ?? 'online') === 'lokaal') ? 'lokaal' : 'online';
    $apparaatAan = array_key_exists('melder_aan', $in) ? (bool)$in['melder_aan'] : pgBool($old['apparaat_aan']);
    $alarm       = (bool)($in['alarm'] ?? false);
    $gedempt     = $alarm && (bool)($in['gedempt'] ?? false);
    $reden       = $alarm ? substr(preg_replace('/[^a-z]/', '', strtolower((string)($in['reden'] ?? ''))), 0, 20) : '';

    // Meting opslaan (een ping bevat geen meetwaarden)
    $heeftMeting = isset($in['temperatuur'], $in['co2']) && is_numeric($in['temperatuur']) && is_numeric($in['co2']);
    if ($heeftMeting) {
        $stap = 'meting opslaan';
        $pdo->prepare("INSERT INTO sensor_data (temperatuur, co2, batterij, alarm, gedempt) VALUES (?, ?, ?, CAST(? AS boolean), CAST(? AS boolean))")
            ->execute([(float)$in['temperatuur'], (int)$in['co2'], (int)($in['batterij'] ?? 100),
                       $alarm ? 'true' : 'false', $gedempt ? 'true' : 'false']);
    }

    // Meldingen aanmaken bij veranderingen
    $stap = 'meldingen opslaan';
    $oudAlarm = pgBool($old['alarm_actief']);
    if ($alarm && !$oudAlarm) {
        if ($reden === 'co2')              $tekst = 'CO₂ te hoog' . ($heeftMeting ? ': ' . (int)$in['co2'] . ' ppm' : '');
        elseif ($reden === 'temperatuur')  $tekst = 'Temperatuur te hoog' . ($heeftMeting ? ': ' . round((float)$in['temperatuur'], 1) . ' °C' : '');
        elseif ($reden === 'handmatig')    $tekst = 'Handmatig alarm op de melder';
        else                               $tekst = 'Alarm op de melder';
        meld($pdo, $tekst, 'alarm');
    } elseif (!$alarm && $oudAlarm) {
        meld($pdo, 'Alarm voorbij', 'ok');
    }
    if ($alarm && $gedempt && !pgBool($old['alarm_gedempt'])) {
        meld($pdo, 'Alarm gedempt op de melder', 'info');
    }
    if ($status === 'lokaal' && $old['apparaat_modus'] !== 'lokaal') {
        meld($pdo, 'Melder in lokale modus gezet (op het apparaat)', 'info');
    } elseif ($status === 'online' && $old['apparaat_modus'] === 'lokaal') {
        meld($pdo, 'Melder weer online', 'ok');
    }
    if ($apparaatAan !== pgBool($old['apparaat_aan'])) {
        meld($pdo, $apparaatAan ? 'Melder ingeschakeld' : 'Melder uitgeschakeld', 'info');
    }

    $stap = 'melder_status bijwerken';
    $pdo->prepare("UPDATE melder_status SET laatste_contact = now(), apparaat_modus = ?, apparaat_aan = CAST(? AS boolean),
                   alarm_actief = CAST(? AS boolean), alarm_gedempt = CAST(? AS boolean), alarm_reden = ? WHERE id = 1")
        ->execute([$status, $apparaatAan ? 'true' : 'false', $alarm ? 'true' : 'false', $gedempt ? 'true' : 'false', $reden]);


    // Antwoord: wat jij op het dashboard hebt ingesteld
    echo json_encode(['ok' => true, 'melder' => pgBool($old['melder_aan']), 'muziek' => pgBool($old['muziek'])]);
} catch (Throwable $e) {
    error_log('api.php server (' . $stap . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'fout' => 'server', 'stap' => $stap, 'detail' => $e->getMessage()]);
}
