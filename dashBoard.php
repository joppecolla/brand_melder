<?php
// Verberg eventuele runtime warnings in productie
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
date_default_timezone_set('Europe/Brussels');

// ---------- 1. Verbinding maken met Neon (PostgreSQL via Environment Variables) ----------
$host = getenv('NEON_HOST');
$db   = getenv('NEON_DB');
$user = getenv('NEON_USER');
$pass = getenv('NEON_PASS');
$port = getenv('NEON_PORT') ?: '5432';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (\PDOException $e) {
    die("Databaseverbinding mislukt: " . $e->getMessage());
}

$stmt = $pdo->query("SELECT temperatuur, co2, batterij, (created_at::timestamptz AT TIME ZONE 'Europe/Brussels') AS lokaal FROM sensor_data WHERE co2 >= 300 AND temperatuur <> 0 ORDER BY created_at DESC LIMIT 1");
$laatsteData = $stmt->fetch(PDO::FETCH_ASSOC);

if ($laatsteData) {
    $huidigeTemp   = (float)$laatsteData['temperatuur'];
    $huidigeCo2    = (int)$laatsteData['co2'];
    $batterij      = (int)$laatsteData['batterij'];
    $laatsteUpdate = date('H:i', strtotime($laatsteData['lokaal']));
} else {
    $huidigeTemp   = 0.0;
    $huidigeCo2    = 0;
    $batterij      = 0;
    $laatsteUpdate = date('H:i');
}

// Grafiekdata: laatste 24 uur, gemiddeld per tijdsblok
$filter = "created_at::timestamptz >= now() - interval '24 hours' AND co2 >= 300 AND temperatuur <> 0";
$spanMin = (float)($pdo->query("SELECT COALESCE(EXTRACT(EPOCH FROM (max(created_at::timestamptz) - min(created_at::timestamptz))) / 60, 0)
                                FROM sensor_data WHERE $filter")->fetchColumn());
$blok = $spanMin <= 90 ? 120 : ($spanMin <= 360 ? 600 : 1800);   // seconden per punt

$historie = $pdo->query("SELECT floor(EXTRACT(EPOCH FROM created_at::timestamptz) / $blok) * $blok AS blok,
                                AVG(temperatuur) AS t, AVG(co2) AS c
                         FROM sensor_data WHERE $filter
                         GROUP BY 1 ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);

$tempReeks = [];
$co2Reeks  = [];
$tijdReeks = [];

foreach ($historie as $rij) {
    $t = round((float)$rij['t'], 1);
    $c = (int)round((float)$rij['c']);
    if ($t < -10 || $t > 60 || $c > 5000) { continue; }
    $tempReeks[] = $t;
    $co2Reeks[]  = $c;
    $tijdReeks[] = date('H:i', (int)$rij['blok']);
}

// Test de alarm-weergave met dashBoard.php?demo=rook
$demoAlarm = (($_GET['demo'] ?? '') === 'rook');

function pgBool($v): bool { return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1'; }
function meld(PDO $pdo, string $tekst, string $niveau): void {
    $pdo->prepare("INSERT INTO meldingen (tekst, niveau) VALUES (?, ?)")->execute([$tekst, $niveau]);
}

// ---------- 3. Bediening: wensen worden in de database bewaard ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['actie'] ?? '') {
        case 'muziek_aan':
            if ($pdo->exec("UPDATE melder_status SET muziek = true WHERE id = 1 AND melder_aan = true AND muziek = false")) {
                meld($pdo, 'Muziekalarm gestart', 'info');
            }
            break;
        case 'muziek_uit':
            if ($pdo->exec("UPDATE melder_status SET muziek = false WHERE id = 1 AND muziek = true")) {
                meld($pdo, 'Muziekalarm gestopt', 'info');
            }
            break;
        case 'melder_uit':
            if ($pdo->exec("UPDATE melder_status SET melder_aan = false, muziek = false WHERE id = 1 AND melder_aan = true")) {
                meld($pdo, 'Uitzetten aangevraagd via dashboard', 'info');
            }
            break;
        case 'melder_aan':
            if ($pdo->exec("UPDATE melder_status SET melder_aan = true WHERE id = 1 AND melder_aan = false")) {
                meld($pdo, 'Aanzetten aangevraagd via dashboard', 'info');
            }
            break;
    }
    header('Location: dashBoard.php');
    exit;
}

// ---------- 4. Toestand van de melder zoals de melder zelf die doorgeeft ----------
try {
    $ms = $pdo->query("SELECT melder_aan, muziek, apparaat_aan, apparaat_modus, alarm_actief, alarm_gedempt, alarm_reden,
                              EXTRACT(EPOCH FROM (now() - laatste_contact)) AS seconden
                       FROM melder_status WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $rijen = $pdo->query("SELECT tekst, niveau, (created_at AT TIME ZONE 'Europe/Brussels') AS lokaal
                          FROM meldingen ORDER BY created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database niet klaar: voer eerst setup.sql uit in Neon. (" . $e->getMessage() . ")");
}

$staat        = ['melder' => pgBool($ms['melder_aan']), 'muziek' => pgBool($ms['muziek'])];
$apparaatAan  = pgBool($ms['apparaat_aan']);
$lokaal       = ($ms['apparaat_modus'] === 'lokaal');
$seconden     = $ms['seconden'] !== null ? (float)$ms['seconden'] : null;
$contactVers  = ($seconden !== null && $seconden <= 90);   // melder pingt elke ~15 s
$alarmActief  = pgBool($ms['alarm_actief']);
$gedempt      = pgBool($ms['alarm_gedempt']);
$wijzigingBezig = $contactVers && !$lokaal && ($staat['melder'] !== $apparaatAan);

// Verbinding
if ($lokaal)                 { $verbinding = 'Lokale modus (geen wifi)'; $onlineKleur = 'offline'; }
elseif ($seconden === null)  { $verbinding = 'Geen data';                $onlineKleur = 'offline'; }
elseif ($contactVers)        { $verbinding = 'Online';                   $onlineKleur = 'online'; }
else                         { $verbinding = 'Offline (geen signaal)';   $onlineKleur = 'offline'; }

// Alarmtekst
$reden = $ms['alarm_reden'];
if ($reden === 'co2')              { $alarmDetail = 'CO₂-gehalte te hoog: ' . $huidigeCo2 . ' ppm'; }
elseif ($reden === 'temperatuur')  { $alarmDetail = 'Temperatuur te hoog: ' . round($huidigeTemp, 1) . ' °C'; }
elseif ($reden === 'handmatig')    { $alarmDetail = 'Handmatig alarm op de melder'; }
else                               { $alarmDetail = 'Alarm op de melder'; }
if ($gedempt) { $alarmDetail .= ' (buzzer gedempt op de melder)'; }
if ($demoAlarm) { $alarmDetail = 'Demo-alarm (Rook gedetecteerd)'; }

// Status bepaling
if ($demoAlarm)           { $niveau = 'alarm'; $systeemStatus = 'Alarm';             $titel = 'Alarm!';               $sub = $alarmDetail . '.'; }
elseif ($lokaal)          { $niveau = 'uit';   $systeemStatus = 'Lokale modus';      $titel = 'Melder in lokale modus'; $sub = 'De melder is op het apparaat offline gezet. Hij stuurt geen gegevens door, dus de status is onbekend.'; }
elseif (!$contactVers)    { $niveau = 'uit';   $systeemStatus = 'Geen verbinding';   $titel = 'Melder offline';       $sub = $seconden === null ? 'Er is nog nooit contact geweest met de melder.' : 'Er is geen signaal van de melder. De status is onbekend.'; }
elseif (!$apparaatAan)    { $niveau = 'uit';   $systeemStatus = 'Uitgeschakeld';     $titel = 'Melder uitgeschakeld'; $sub = 'De rookmelder detecteert momenteel niets.'; }
elseif ($alarmActief)     { $niveau = 'alarm'; $systeemStatus = 'Alarm';             $titel = 'Alarm!';               $sub = $alarmDetail . '.'; }
else                      { $niveau = 'ok';    $systeemStatus = 'Actief & Veilig';   $titel = 'Alles in orde';        $sub = 'De melder is verbonden en er is geen alarm.'; }
if ($wijzigingBezig && $niveau !== 'alarm') { $sub .= ' Wijziging wordt doorgegeven aan de melder…'; }

// Tekst bij de rookmelder-bediening
if ($lokaal)               { $melderTekst = 'Lokale modus, niet bereikbaar'; }
elseif (!$contactVers)     { $melderTekst = 'Geen verbinding'; }
elseif ($wijzigingBezig)   { $melderTekst = 'Wijziging wordt doorgegeven…'; }
else                       { $melderTekst = $apparaatAan ? 'Actief en bewaakt' : 'Uitgeschakeld'; }
$bedieningInfo = ($lokaal || !$contactVers) ? 'De melder is nu niet bereikbaar. Je keuze wordt toegepast zodra hij weer online is.' : '';

// Recente meldingen
$meldingen = [];
foreach ($rijen as $r) {
    $t = strtotime($r['lokaal']);
    $dag = date('Y-m-d', $t) === date('Y-m-d') ? 'Vandaag'
         : (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day')) ? 'Gisteren' : date('d-m', $t));
    $meldingen[] = [$r['tekst'], date('H:i', $t) . ' - ' . $dag, $r['niveau']];
}
if ($demoAlarm) { array_unshift($meldingen, ['Demo-alarm', date('H:i') . ' - Vandaag', 'alarm']); }

// ECHT MOOI EN STRAK VLAM-ICOON (flame)
function ico(string $n, string $extra = ''): string {
    $p = [
        'flame' => '<path d="M12 2c0 4-2.5 6-3.5 8.5C7.2 13.2 7 14.8 7 16.5a5 5 0 0 0 10 0c0-1.7-.2-3.3-1.5-6C14.5 8 12 6 12 2z"/><path d="M12 22a3.5 3.5 0 0 1-3.5-3.5c0-1.5 1-2.8 2-3.8.5-.5 1.5-1.7 1.5-3.2 0 1.5 1 2.7 1.5 3.2 1 1 2 2.3 2 3.8A3.5 3.5 0 0 1 12 22z"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'battery' => '<rect x="2" y="7" width="16" height="10" rx="2"/><path d="M22 11v2"/><path d="M6 11v2M10 11v2"/>',
        'wifi' => '<path d="M12 20h.01M2 8.82a15 15 0 0 1 20 0M5 12.86a10 10 0 0 1 14 0M8.5 16.43a5 5 0 0 1 7 0"/>',
        'therm' => '<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>',
        'wind' => '<path d="M17.7 7.7a2.5 2.5 0 1 1 1.8 4.3H2M9.6 4.6A2 2 0 1 1 11 8H2M12.6 19.4A2 2 0 1 0 14 16H2"/>',
        'power' => '<path d="M12 2v10M18.4 6.6a9 9 0 1 1-12.77.04"/>',
        'play' => '<polygon points="6 3 20 12 6 21 6 3"/>',
        'stop' => '<rect x="5" y="5" width="14" height="14" rx="2"/>',
        'music' => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'bell' => '<path d="M10.27 21a2 2 0 0 0 3.46 0M3.26 15.33A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.67C19.41 13.96 18 12.5 18 8a6 6 0 0 0-12 0c0 4.5-1.41 5.96-2.74 7.33"/>',
        'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3M12 9v4M12 17h.01"/>',
        'chev' => '<path d="m9 18 6-6-6-6"/>',
    ];
return '<svg class="ico ' . $extra . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><g transform="rotate(0 12 12)">' . $p[$n] . '</g></svg>';}

function mooieSchaal(float $minV, float $maxV, int $doel = 3): array {
    $bereik = $maxV - $minV;
    if ($bereik < 1e-9) {
        $bereik = max(abs($maxV) * 0.1, 1.0);
        $minV -= $bereik / 2; $maxV += $bereik / 2;
    }
    $minV -= $bereik * 0.1; $maxV += $bereik * 0.1;
    $ruw  = ($maxV - $minV) / $doel;
    $mag  = pow(10, floor(log10($ruw)));
    $norm = $ruw / $mag;
    $stap = ($norm <= 1 ? 1 : ($norm <= 2 ? 2 : ($norm <= 5 ? 5 : 10))) * $mag;
    return [floor($minV / $stap) * $stap, ceil($maxV / $stap) * $stap, $stap];
}

function lijngrafiek(array $w, string $kleur, string $eenheid, string $id, ?array $labels = null): string {
    $n = count($w);

    if ($n === 0) {
        return '<div style="height: 190px; display: flex; align-items: center; justify-content: center; color: #888; font-size: 0.9rem;">Nog geen metingen beschikbaar</div>';
    }

    $labels = $labels ?? [];
    $B = 420; $H = 190; $L = 40; $R = 12; $T = 14; $Bo = 26;

    if ($n === 1) {
        $w = [$w[0], $w[0]];
        if (!empty($labels)) { $labels = [$labels[0], $labels[0]]; }
        $n = 2;
    }

    [$min, $max, $stap] = mooieSchaal((float)min($w), (float)max($w));
    if ($eenheid === ' ppm' && $min < 0) { $min = 0; }
    if ($max <= $min) { $max = $min + $stap; }

    $xy = [];
    foreach ($w as $i => $v) {
        $xy[] = [$L + ($B - $L - $R) * $i / ($n - 1), $T + ($H - $T - $Bo) * (1 - ($v - $min) / ($max - $min))];
    }

    $d = 'M' . round($xy[0][0], 1) . ',' . round($xy[0][1], 1);
    for ($i = 1; $i < $n; $i++) {
        $cx = ($xy[$i - 1][0] + $xy[$i][0]) / 2;
        $d .= ' C' . round($cx, 1) . ',' . round($xy[$i - 1][1], 1) . ' ' . round($cx, 1) . ',' . round($xy[$i][1], 1) . ' ' . round($xy[$i][0], 1) . ',' . round($xy[$i][1], 1);
    }

    $vlak = $d . ' L' . round($xy[$n - 1][0], 1) . ',' . ($H - $Bo) . ' L' . $L . ',' . ($H - $Bo) . ' Z';
    $o = '<svg viewBox="0 0 ' . $B . ' ' . $H . '" class="chart" role="img" aria-label="Grafiek"><defs><linearGradient id="g' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $kleur . '" stop-opacity=".28"/><stop offset="1" stop-color="' . $kleur . '" stop-opacity="0"/></linearGradient></defs>';

    $aantal = max(1, (int)round(($max - $min) / $stap));
    $dec = $stap < 1 ? 1 : 0;
    for ($k = 0; $k <= $aantal; $k++) {
        $val = $max - $k * $stap;
        $y = $T + ($H - $T - $Bo) * $k / $aantal;
        $o .= '<line x1="' . $L . '" x2="' . ($B - $R) . '" y1="' . $y . '" y2="' . $y . '" class="grid"/><text x="' . ($L - 8) . '" y="' . ($y + 4) . '" text-anchor="end" class="axis">' . number_format($val, $dec, '.', '') . '</text>';
    }

    $aantalLabels = min($n, 5);
    for ($k = 0; $k < $aantalLabels; $k++) {
        $i = $aantalLabels === 1 ? 0 : (int)round($k * ($n - 1) / ($aantalLabels - 1));
        $label = ($i === $n - 1) ? 'nu' : ($labels[$i] ?? '');
        $o .= '<text x="' . round($xy[$i][0], 1) . '" y="' . ($H - 6) . '" text-anchor="middle" class="axis">' . $label . '</text>';
    }

    $lx = round($xy[$n - 1][0], 1); $ly = round($xy[$n - 1][1], 1);
    return $o . '<path d="' . $vlak . '" fill="url(#g' . $id . ')"/><path d="' . $d . '" fill="none" stroke="' . $kleur . '" stroke-width="3" stroke-linecap="round" class="line" pathLength="1"/>'
        . '<circle cx="' . $lx . '" cy="' . $ly . '" r="10" fill="' . $kleur . '" opacity=".15"/><circle cx="' . $lx . '" cy="' . $ly . '" r="5" fill="#fff" stroke="' . $kleur . '" stroke-width="3"/></svg>';
}
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Brand-Melder</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- BOVENBALK -->
    <header class="sticky-header" id="topbar">
        <a href="index.php" class="logo"><span class="logo-icon">🚨</span>Brand-Melder</a>
        <nav>
            <a href="index.php">Home</a>
            <a href="dashBoard.php" class="active">Dashboard</a>
            <a href="admin.php">Admin</a>
        </nav>
    </header>

    <!-- DUIDELIJKE ALARM MELDING BOVENAAN -->
    <div id="alarm-wrap">
    <?php if ($niveau === 'alarm') { ?>
        <div class="alarm-bar" style="background-color: #e63946; color: white; padding: 14px 24px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 12px; font-size: 1.05rem; box-shadow: 0 4px 12px rgba(230, 57, 70, 0.3);">
            <?php echo ico('alert', 'white-ico'); ?>
            <span><strong>ALARM DETECTIE:</strong> <?php echo htmlspecialchars($alarmDetail); ?>. Controleer de ruimte en verlaat het gebouw bij brand!</span>
        </div>
    <?php } ?>
    </div>

    <main class="dash-page">

        <!-- BANNER -->
        <section class="dash-banner">
            <div class="dash-banner-inner">
                <span class="live-pill" id="live-pill"><i></i> Live • bijgewerkt om <?php echo $laatsteUpdate; ?></span>
                <h1>Dashboard</h1>
                <p>Overzicht en bediening van je Brand-Melder</p>
            </div>
        </section>

        <div class="dash-wrap">

            <!-- STATUS + OVERZICHT -->
            <div class="dash-top">
                <section class="card status-hero <?php echo $niveau; ?>" id="status-hero">
                    <div class="gauge">
                        <svg viewBox="0 0 120 120" aria-hidden="true">
                            <circle cx="60" cy="60" r="52" class="gauge-bg" pathLength="100"/>
                            <circle cx="60" cy="60" r="52" class="gauge-ring" pathLength="100"/>
                        </svg>
                        <span class="gauge-core"><?php echo ico($niveau === 'uit' ? 'power' : 'flame'); ?></span>
                    </div>
                    <h2><?php echo $titel; ?></h2>
                    <p><?php echo $sub; ?></p>
                    <a href="#grafieken" class="cta-button">Bekijk live gegevens</a>
                </section>

                <section class="card list-card" id="systeem-card">
                    <h3 class="card-title">Systeem</h3>
                    <div class="row" id="status-row">
                        <span class="tile <?php echo $niveau; ?>"><?php echo ico('shield'); ?></span>
                        <div><small>Status</small><strong class="txt-<?php echo $niveau; ?>"><?php echo $systeemStatus; ?></strong></div>
                        <?php echo ico('chev', 'chev'); ?>
                    </div>
                    <div class="row" id="batterij-row">
                        <span class="tile"><?php echo ico('battery'); ?></span>
                        <div><small>Batterij</small><strong><?php echo $batterij; ?>%</strong>
                            <div class="bar"><i style="width:<?php echo $batterij; ?>%"></i></div></div>
                        <?php echo ico('chev', 'chev'); ?>
                    </div>
                    <div class="row" id="verbinding-row">
                        <span class="tile"><?php echo ico('wifi'); ?></span>
                        <div><small>Verbinding</small><strong><i class="<?php echo $onlineKleur; ?>"></i><?php echo $verbinding; ?></strong></div>
                        <?php echo ico('chev', 'chev'); ?>
                    </div>
                </section>
            </div>

            <!-- METINGEN -->
            <div class="section-row" id="grafieken"><h3>Metingen</h3><span>Laatste 24 uur · gemiddelde per <?php echo (int)($blok / 60); ?> min</span></div>
            <div class="chart-grid">
                <section class="card chart-card" id="temp-chart">
                    <div class="chart-head">
                        <span class="tile"><?php echo ico('therm'); ?></span>
                        <div><small>Temperatuur</small><strong id="temp-waarde"><?php echo $huidigeTemp; ?> <em>°C</em></strong></div>
                    </div>
                    <?php echo lijngrafiek($tempReeks, '#e63946', ' °C', 't', $tijdReeks); ?>
                </section>
                <section class="card chart-card" id="co2-chart">
                    <div class="chart-head">
                        <span class="tile blue"><?php echo ico('wind'); ?></span>
                        <div><small>CO₂-gehalte</small><strong id="co2-waarde"><?php echo $huidigeCo2; ?> <em>ppm</em></strong></div>
                    </div>
                    <?php echo lijngrafiek($co2Reeks, '#27466f', ' ppm', 'c', $tijdReeks); ?>
                </section>
            </div>

            <!-- BEDIENING + MELDINGEN -->
            <div class="dash-bottom">
                <section class="card" id="bediening-card">
                    <h3 class="card-title">Bediening</h3>

                    <div class="control">
                        <span class="tile"><?php echo ico('music'); ?></span>
                        <div><strong>Muziekalarm</strong><small><?php echo $staat['muziek'] ? 'Speelt nu af' : 'Staat uit'; ?></small></div>
                        <?php if ($staat['muziek']) { ?><span class="eq"><i></i><i></i><i></i><i></i></span><?php } ?>
                    </div>
                    <form method="post" class="btn-pair">
                        <button name="actie" value="muziek_aan" class="ctrl-btn primary" <?php echo (!$staat['melder'] || $staat['muziek']) ? 'disabled' : ''; ?>><?php echo ico('play'); ?>Muziek aan</button>
                        <button name="actie" value="muziek_uit" class="ctrl-btn" <?php echo !$staat['muziek'] ? 'disabled' : ''; ?>><?php echo ico('stop'); ?>Muziek uit</button>
                    </form>

                    <div class="divider"></div>

                    <div class="control">
                        <span class="tile <?php echo $niveau === 'uit' ? 'uit' : ''; ?>"><?php echo ico('power'); ?></span>
                        <div><strong>Rookmelder</strong><small><?php echo $melderTekst; ?></small></div>
                    </div>
                    <?php if ($staat['melder']) { ?>
                        <button type="button" class="ctrl-btn danger wide" id="openBevestiging"><?php echo ico('power'); ?>Rookmelder uitzetten</button>
                    <?php } else { ?>
                        <form method="post"><button name="actie" value="melder_aan" class="ctrl-btn success wide"><?php echo ico('power'); ?>Rookmelder aanzetten</button></form>
                        <p class="control-warning">De rookmelder staat uit en detecteert geen rook.</p>
                    <?php } ?>
                    <?php if ($bedieningInfo) { ?><p class="control-warning"><?php echo $bedieningInfo; ?></p><?php } ?>
                </section>

                <section class="card" id="meldingen-card">
                    <h3 class="card-title">Recente meldingen</h3>
                    <?php if (!$meldingen) { ?><p class="control-warning" style="background:none;border:0;color:inherit;opacity:.7">Nog geen meldingen.</p><?php } ?>
                    <?php foreach ($meldingen as $m) { ?>
                        <div class="row note">
                            <span class="tile sm <?php echo $m[2]; ?>"><?php echo ico($m[2] === 'alarm' ? 'flame' : 'bell'); ?></span>
                            <div><strong><?php echo htmlspecialchars($m[0]); ?></strong><small><?php echo $m[1]; ?></small></div>
                        </div>
                    <?php } ?>
                </section>
            </div>
        </div>

        <!-- Bevestigingsvenster -->
        <dialog id="bevestiging" class="confirm-box">
            <span class="tile danger-tile"><?php echo ico('alert'); ?></span>
            <h3>Rookmelder uitzetten?</h3>
            <p>Weet je zeker dat je de rookmelder wilt uitzetten? Er wordt dan <strong>geen rook of brand meer gedetecteerd</strong> en er komt geen alarm.</p>
            <form method="post" class="btn-pair">
                <button type="button" class="ctrl-btn" id="annuleer">Annuleren</button>
                <button name="actie" value="melder_uit" class="ctrl-btn danger solid">Ja, uitzetten</button>
            </form>
        </dialog>
    </main>

    <!-- AUTOMATISCHE ACHTERGROND REFRESH ZONDER FLIKKEREN -->
    <script>
    document.addEventListener('click', (e) => {
        const openBtn = e.target.closest('#openBevestiging');
        const cancelBtn = e.target.closest('#annuleer');
        const dlg = document.getElementById('bevestiging');
    
        if (openBtn && dlg) dlg.showModal();
        if (cancelBtn && dlg) dlg.close();
    });

    setInterval(async () => {
        try {
            const response = await fetch(window.location.pathname + '?t=' + Date.now(), { cache: 'no-store' });
            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            // 1. Update Alarm Banner Bovenaan
            const nieuwAlarmWrap = doc.getElementById('alarm-wrap');
            const huidigAlarmWrap = document.getElementById('alarm-wrap');
            if (nieuwAlarmWrap && huidigAlarmWrap && nieuwAlarmWrap.innerHTML !== huidigAlarmWrap.innerHTML) {
                huidigAlarmWrap.innerHTML = nieuwAlarmWrap.innerHTML;
            }

            // 2. Update Main Status Hero Card
            const nieuweStatusHero = doc.getElementById('status-hero');
            const huidigeStatusHero = document.getElementById('status-hero');
            if (nieuweStatusHero && huidigeStatusHero && nieuweStatusHero.innerHTML !== huidigeStatusHero.innerHTML) {
                huidigeStatusHero.className = nieuweStatusHero.className;
                huidigeStatusHero.innerHTML = nieuweStatusHero.innerHTML;
            }

            // 3. Update Systeem Card Rows & Grafieken
            const elementenToUpdate = ['status-row', 'batterij-row', 'verbinding-row', 'temp-chart', 'co2-chart', 'live-pill', 'meldingen-card'];
            elementenToUpdate.forEach(id => {
                const nieuwEl = doc.getElementById(id);
                const huidigEl = document.getElementById(id);
                if (nieuwEl && huidigEl && nieuwEl.innerHTML !== huidigEl.innerHTML) {
                    huidigEl.innerHTML = nieuwEl.innerHTML;
                }
            });

        } catch (error) {
            console.error('Fout bij achtergrond-update:', error);
        }
    }, 5000);
    </script>

    <footer class="footer">
        <div>
            <strong>🚨 Brand-Melder</strong>
            <p>Een slim IoT-project rond brandveiligheid.</p>
        </div>
        <p class="copyright">© <?php echo date("Y"); ?> Brand-Melder</p>
    </footer>

</body>
</html>
