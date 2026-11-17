<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);

$host = getenv('NEON_HOST');
$db   = getenv('NEON_DB');
$user = getenv('NEON_USER');
$pass = getenv('NEON_PASS');
$port = getenv('NEON_PORT') ?: '5432';

// Het maximum aantal liedjes op je SD-kaart
$maxLiedjes = 14;

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

// Bediening per melder (muziek, liedje, volume, aan/uit): elke melder krijgt zijn eigen rij
function bedieningVan(PDO $pdo, ?string $melderId, array $old): array {
    if ($melderId === null) { return $old; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS melder_bediening (
        melder_id      text PRIMARY KEY,
        melder_aan     boolean NOT NULL DEFAULT true,
        muziek         boolean NOT NULL DEFAULT false,
        volume         integer NOT NULL DEFAULT 25,
        gekozen_liedje integer NOT NULL DEFAULT 1
    )");
    $pdo->prepare("INSERT INTO melder_bediening (melder_id) VALUES (?) ON CONFLICT (melder_id) DO NOTHING")->execute([$melderId]);
    $st = $pdo->prepare("SELECT melder_aan, muziek, volume, gekozen_liedje FROM melder_bediening WHERE melder_id = ?");
    $st->execute([$melderId]);
    return array_merge($old, $st->fetch(PDO::FETCH_ASSOC));
}

function stuurAlarmMail(string $onderwerp, string $bericht): void {
    $apiKey = getenv('RESEND_API_KEY');
    $ontvanger = getenv('ALARM_EMAIL');
    if (!$apiKey || !$ontvanger) return;

    // Strakke HTML-layout met alarm, noodstappen, noodnummer en reclame (zonder knop)
    $htmlInhoud = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
        <!-- Header -->
        <div style="background-color: #cc0000; color: white; padding: 20px; text-align: center;">
            <h2 style="margin: 0; font-size: 22px;">🚨 Waarschuwing Brand-Melder</h2>
        </div>

        <!-- Inhoud Bericht -->
        <div style="padding: 20px; background-color: #fff5f5;">
            <p style="font-size: 16px; color: #333; line-height: 1.5;">' . nl2br(htmlspecialchars($bericht)) . '</p>
        </div>

        <!-- Wat te doen in geval van nood (Stappenplan) -->
        <div style="background-color: #e8f4fd; border-left: 5px solid #007bff; padding: 15px 20px; margin: 20px;">
            <p style="margin: 0 0 10px 0; font-weight: bold; color: #004085; font-size: 15px;">🛡️ Wat te doen in geval van nood?</p>
            <ol style="margin: 0; padding-left: 20px; color: #004085; font-size: 14px; line-height: 1.6;">
                <li><strong>Blijf kalm</strong> en raak niet in paniek.</li>
                <li><strong>Controleer de ruimte</strong> op rook, vuur of extreme warmte.</li>
                <li><strong>Ventileer direct:</strong> open ramen en deuren als het om een hoge CO₂-waarde gaat.</li>
                <li><strong>Verlaat bij twijfel of rook direct het pand</strong> via de veiligste uitgang.</li>
                <li>Breng jezelf en anderen in veiligheid.</li>
            </ol>
        </div>

        <!-- Noodnummer Blok -->
        <div style="background-color: #fff3cd; border-left: 5px solid #ffc107; padding: 15px 20px; margin: 0 20px 20px 20px;">
            <p style="margin: 0; font-weight: bold; color: #856404; font-size: 15px;">📞 Hulpdiensten bereiken?</p>
            <p style="margin: 5px 0 0 0; color: #856404; font-size: 14px;">
                Zie je daadwerkelijk vuur, rook of vertrouw je het niet? Bel onmiddellijk de brandweer op <strong>112</strong> (noodnummer).
            </p>
        </div>

        <!-- Reclame / Promotie Blok (Zonder knop) -->
        <div style="background-color: #f8f9fa; border-top: 1px solid #eee; padding: 20px; text-align: center;">
            <h3 style="color: #333; margin-top: 0; font-size: 18px;">💡 Slimme beveiliging met de Brand-Melder</h3>
            <p style="color: #666; font-size: 14px; line-height: 1.4; max-width: 450px; margin: 0 auto; text-align: center;">
                Altijd en overal op de hoogte van de luchtkwaliteit en veiligheid in huis? Onze slimme brandmelder combineert CO₂-monitoring, real-time database-updates en zelfs vrolijke melodieën via de ingebouwde spraak- en muziekmodule!
            </p>
        </div>

        <!-- Footer -->
        <div style="background-color: #e9ecef; padding: 10px; text-align: center; font-size: 12px; color: #777;">
            Dit is een automatisch gegenereerd bericht van jouw ESP32 Brand-Melder systeem.
        </div>
    </div>';

    $data = [
        'from' => 'Brand-Melder <onboarding@resend.dev>',
        'to' => [$ontvanger],
        'subject' => $onderwerp,
        'html' => $htmlInhoud
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        error_log("Resend mail fout ($httpCode): " . $response);
    }
}
// GET = status en CO2-drempel opvragen (admin-pagina of ESP)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $r = $pdo->query("SELECT * FROM melder_status WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('api.php GET: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'fout' => 'server', 'detail' => $e->getMessage()]);
        exit;
    }
    if (!$r) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'fout' => 'Melder niet gevonden']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'melder' => pgBool($r['melder_aan']),
        'muziek' => pgBool($r['muziek']),
        'gekozen_liedje' => (int)($r['gekozen_liedje'] ?? 0),
        'volume' => (int)($r['volume'] ?? 25),
        'co2_drempel' => (int)$r['co2_alarm_threshold'],
        'verzend_interval' => (int)($r['verzend_interval'] ?? 120)
    ]);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'fout' => 'ongeldige json']);
    exit;
}

// Website knop: Volgend liedje kiezen
if (($in['actie'] ?? '') === 'volgende') {
    try {
        $sql = "UPDATE melder_status 
                SET gekozen_liedje = CASE 
                    WHEN gekozen_liedje + 1 > :max THEN 1 
                    ELSE gekozen_liedje + 1 
                END 
                WHERE id = 1";
        $st = $pdo->prepare($sql);
        $st->execute(['max' => $maxLiedjes]);
        
        $r = $pdo->query("SELECT gekozen_liedje FROM melder_status WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'gekozen_liedje' => (int)$r['gekozen_liedje']]);
    } catch (Throwable $e) {
        error_log('api.php volgende: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'fout' => 'server', 'detail' => $e->getMessage()]);
    }
    exit;
}

// Website knop: Vorig liedje kiezen
if (($in['actie'] ?? '') === 'vorige') {
    try {
        $sql = "UPDATE melder_status 
                SET gekozen_liedje = CASE 
                    WHEN gekozen_liedje - 1 < 1 THEN :max 
                    ELSE gekozen_liedje - 1 
                END 
                WHERE id = 1";
        $st = $pdo->prepare($sql);
        $st->execute(['max' => $maxLiedjes]);
        
        $r = $pdo->query("SELECT gekozen_liedje FROM melder_status WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'gekozen_liedje' => (int)$r['gekozen_liedje']]);
    } catch (Throwable $e) {
        error_log('api.php vorige: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'fout' => 'server', 'detail' => $e->getMessage()]);
    }
    exit;
}

// Admin-pagina: Verzendinterval instellen
if (($in['actie'] ?? '') === 'verzend_interval_inst') {
    session_start();
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'fout' => 'Alleen admins mogen dit wijzigen.']);
        exit;
    }
    $w = $in['verzend_interval'] ?? null;
    if (!is_numeric($w) || (int)$w != $w || (int)$w < 10 || (int)$w > 3600) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'fout' => 'Geef een waarde tussen 10 en 3600 seconden in.']);
        exit;
    }
    try {
        $pdo->exec("ALTER TABLE melder_status ADD COLUMN IF NOT EXISTS verzend_interval integer NOT NULL DEFAULT 120");
        $st = $pdo->prepare("UPDATE melder_status SET verzend_interval = ? WHERE id = 1");
        $st->execute([(int)$w]);
        if ($st->rowCount() === 0) { throw new RuntimeException('melder_status heeft geen rij met id = 1'); }
        meld($pdo, 'Verzendinterval ingesteld op ' . (int)$w . ' seconden', 'info');
        echo json_encode(['ok' => true, 'verzend_interval' => (int)$w]);
    } catch (Throwable $e) {
        error_log('api.php verzend_interval: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'fout' => 'server', 'detail' => $e->getMessage()]);
    }
    exit;
}

// Admin-pagina: CO2-alarmdrempel instellen
if (($in['actie'] ?? '') === 'co2_drempel_inst') {
    session_start();
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'fout' => 'Alleen admins mogen dit wijzigen.']);
        exit;
    }
    $w = $in['co2_drempel'] ?? null;
    if (!is_numeric($w) || (int)$w != $w || (int)$w < 1 || (int)$w > 5000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'fout' => 'Geef een waarde tussen 1 en 5000 ppm in.']);
        exit;
    }
    try {
        $st = $pdo->prepare("UPDATE melder_status SET co2_alarm_threshold = ? WHERE id = 1");
        $st->execute([(int)$w]);
        if ($st->rowCount() === 0) { throw new RuntimeException('melder_status heeft geen rij met id = 1'); }
        meld($pdo, 'CO₂-alarmwaarde ingesteld op ' . (int)$w . ' ppm', 'info');
        echo json_encode(['ok' => true, 'co2_drempel' => (int)$w]);
    } catch (Throwable $e) {
        error_log('api.php co2_drempel: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'fout' => 'server', 'detail' => $e->getMessage()]);
    }
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
    $reden       = $alarm ? substr(preg_replace('/[^a-z0-9]/', '', strtolower((string)($in['reden'] ?? ''))), 0, 20) : '';        
    
    $melderId    = trim((string)($in['melder_id'] ?? ($in['device_id'] ?? '')));
    if (!preg_match('/^[A-Za-z0-9_-]{1,30}$/', $melderId)) { $melderId = null; }

    $heeftMeting = isset($in['temperatuur'], $in['co2']) && is_numeric($in['temperatuur']) && is_numeric($in['co2']);
    if ($heeftMeting) {
        $stap = 'meting opslaan';
        $pdo->exec("ALTER TABLE sensor_data ADD COLUMN IF NOT EXISTS melder_id text");
        $pdo->prepare("INSERT INTO sensor_data (melder_id, temperatuur, co2, batterij, alarm, gedempt) VALUES (?, ?, ?, ?, CAST(? AS boolean), CAST(? AS boolean))")
            ->execute([$melderId, (float)$in['temperatuur'], (int)$in['co2'], (int)($in['batterij'] ?? 100),
                       $alarm ? 'true' : 'false', $gedempt ? 'true' : 'false']);
    }

    $stap = 'meldingen opslaan';
    $oudAlarm = pgBool($old['alarm_actief']);
    if ($alarm && !$oudAlarm) {
        if ($reden === 'co2')                 $tekst = 'CO₂ te hoog' . ($heeftMeting ? ': ' . (int)$in['co2'] . ' ppm' : '');
        elseif ($reden === 'temperatuur')   $tekst = 'Temperatuur te hoog' . ($heeftMeting ? ': ' . round((float)$in['temperatuur'], 1) . ' °C' : '');
        elseif ($reden === 'handmatig')     $tekst = 'Handmatig alarm op de melder';
        else                                  $tekst = 'Alarm op de melder';
        
        meld($pdo, $tekst, 'alarm');

        // E-mail versturen via Render/Resend
        $melderLabel = $melderId ? "Melder $melderId" : "Onbekende melder";
        $mailTekst = "Let op!\n\nEr is een alarm afgegaan op $melderLabel.\nReden: $tekst\nTijdstip: " . date('d-m-Y H:i:s');
        stuurAlarmMail("🚨 ALARM: $tekst ($melderLabel)", $mailTekst);

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

    if ($melderId !== null) {
        $stap = 'melder_toestand bijwerken';
        $pdo->exec("CREATE TABLE IF NOT EXISTS melder_toestand (
            melder_id        text PRIMARY KEY,
            laatste_contact timestamptz NOT NULL DEFAULT now(),
            modus            text NOT NULL DEFAULT 'online',
            alarm            boolean NOT NULL DEFAULT false,
            gedempt          boolean NOT NULL DEFAULT false,
            reden            text NOT NULL DEFAULT ''
        )");
        $pdo->exec("ALTER TABLE melder_toestand ADD COLUMN IF NOT EXISTS aan boolean NOT NULL DEFAULT true");
        $pdo->prepare("INSERT INTO melder_toestand (melder_id, laatste_contact, modus, alarm, gedempt, reden, aan)
                       VALUES (?, now(), ?, CAST(? AS boolean), CAST(? AS boolean), ?, CAST(? AS boolean))
                       ON CONFLICT (melder_id) DO UPDATE SET laatste_contact = now(), modus = EXCLUDED.modus,
                           alarm = EXCLUDED.alarm, gedempt = EXCLUDED.gedempt, reden = EXCLUDED.reden, aan = EXCLUDED.aan")
            ->execute([$melderId, $status, $alarm ? 'true' : 'false', $gedempt ? 'true' : 'false', $reden, $apparaatAan ? 'true' : 'false']);
    }

    // Antwoord terugsturen naar de ESP32 (inclusief volume en gekozen liedje)
    $stap = 'bediening lezen';
    $old = bedieningVan($pdo, $melderId, $old);
    echo json_encode([
        'ok' => true,
        'melder' => pgBool($old['melder_aan']),
        'muziek' => pgBool($old['muziek']),
        'gekozen_liedje' => (int)($old['gekozen_liedje'] ?? 0),
        'volume' => (int)($old['volume'] ?? 25),
        'co2_drempel' => (int)($old['co2_alarm_threshold'] ?? 1000),
        'verzend_interval' => (int)($old['verzend_interval'] ?? 120)
    ]);

} catch (Throwable $e) {
    error_log('api.php server (' . $stap . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'fout' => 'server',
        'stap' => $stap,
        'detail' => $e->getMessage()
    ]);
}
?>
