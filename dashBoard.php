<?php
// Verberg eventuele runtime warnings in productie (optioneel, maar houdt het scherm schoon)
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);

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

// ---------- 2. Echte sensordata ophalen uit de database ----------
$stmt = $pdo->query("SELECT temperatuur, co2, batterij, created_at FROM sensor_data ORDER BY created_at DESC LIMIT 1");
$laatsteData = $stmt->fetch(PDO::FETCH_ASSOC);

if ($laatsteData) {
    $huidigeTemp   = (float)$laatsteData['temperatuur'];
    $huidigeCo2    = (int)$laatsteData['co2'];
    $batterij      = (int)$laatsteData['batterij'];
    $verbinding    = "Online";
    $laatsteUpdate = date('H:i', strtotime($laatsteData['created_at']));
} else {
    $huidigeTemp   = 0.0;
    $huidigeCo2    = 0;
    $batterij      = 0;
    $verbinding    = "Geen data";
    $laatsteUpdate = date('H:i');
}

$stmtReeks = $pdo->query("SELECT temperatuur, co2, created_at FROM sensor_data ORDER BY created_at ASC");
$historie = $stmtReeks->fetchAll(PDO::FETCH_ASSOC);

$tempReeks = [];
$co2Reeks  = [];
$tijdReeks = [];

foreach ($historie as $rij) {
    $t = (float)$rij['temperatuur'];
    $c = (int)$rij['co2'];
    
    if ($t >= -10 && $t <= 60) { $tempReeks[] = $t; }
    if ($c >= 200 && $c <= 5000) { $co2Reeks[] = $c; }
    
    // Vul de tijdreeks met uur:minuut
    $tijdReeks[] = !empty($rij['created_at']) ? date('H:i', strtotime($rij['created_at'])) : '';
}

// Test de rook-weergave met dashBoard.php?demo=rook
$rookGedetecteerd = (($_GET['demo'] ?? '') === 'rook');

// ---------- 3. Bediening: toestand bewaren in melder_status.json ----------
$bestand = __DIR__ . '/melder_status.json';
$staat = ['muziek' => false, 'melder' => true];
if (is_file($bestand)) {
    $opgeslagen = json_decode(file_get_contents($bestand), true);
    if (is_array($opgeslagen)) { $staat = array_merge($staat, $opgeslagen); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['actie'] ?? '') {
        case 'muziek_aan': if ($staat['melder']) { $staat['muziek'] = true; } break;
        case 'muziek_uit': $staat['muziek'] = false; break;
        case 'melder_uit': $staat['melder'] = false; $staat['muziek'] = false; break;
        case 'melder_aan': $staat['melder'] = true; break;
    }
    file_put_contents($bestand, json_encode($staat), LOCK_EX);
    header('Location: dashBoard.php');
    exit;
}

// ---------- 4. Status bepalen ----------
if (!$staat['melder'])      { $niveau = 'uit';   $systeemStatus = 'Uitgeschakeld';  $titel = 'Melder uitgeschakeld'; $sub = 'De rookmelder detecteert momenteel niets.'; }
elseif ($rookGedetecteerd)  { $niveau = 'alarm'; $systeemStatus = 'Rook gedetecteerd'; $titel = 'Rook gedetecteerd!'; $sub = 'Er is rook gedetecteerd in de kamer.'; }
else                        { $niveau = 'ok';    $systeemStatus = 'Actief & Veilig'; $titel = 'Alles in orde'; $sub = 'Er is geen rook gedetecteerd.'; }

$meldingen = [
    ['Muziekalarm gestopt', '08:12 - Gisteren', 'info'],
    ['Melder ingeschakeld', '19:30 - Gisteren', 'ok'],
    ['Batterij gecontroleerd (' . $batterij . '%)', '09:00 - Gisteren', 'info'],
];
if ($rookGedetecteerd && $staat['melder']) { array_unshift($meldingen, ['Rook gedetecteerd', date('H:i') . ' - Vandaag', 'alarm']); }

function ico(string $n, string $extra = ''): string {
    $p = [
        'flame' => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
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
    return '<svg class="ico ' . $extra . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$n] . '</svg>';
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
        if (!empty($labels)) {
            $labels = [$labels[0], $labels[0]];
        }
        $n = 2;
    }

    $minVal = min($w);
    $maxVal = max($w);

    $min = floor($minVal - ($maxVal - $minVal) * 0.15);
    $max = ceil($maxVal + ($maxVal - $minVal) * 0.15);

    if ($max == $min) { 
        $max = $min + 5; 
        $min = max(0, $min - 5);
    }

    if ($eenheid === ' ppm' && $min < 0) { $min = 0; }

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
    
    for ($k = 0; $k < 4; $k++) {
        $y = $T + ($H - $T - $Bo) * $k / 3; 
        $val = round($max - ($max - $min) * $k / 3);
        $o .= '<line x1="' . $L . '" x2="' . ($B - $R) . '" y1="' . $y . '" y2="' . $y . '" class="grid"/><text x="' . ($L - 8) . '" y="' . ($y + 4) . '" text-anchor="end" class="axis">' . $val . '</text>';
    }

    $stapLabel = max(1, floor($n / 4));
    for ($i = 0; $i < $n; $i += $stapLabel) {
        if (!empty($labels) && isset($labels[$i])) {
            $label = ($i === $n - 1 || $i >= $n - 2) ? 'nu' : $labels[$i];
        } else {
            $label = ($i === $n - 1 || $i >= $n - 2) ? 'nu' : ('punt ' . ($i + 1));
        }
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

    <main class="dash-page">

        <?php if ($niveau === 'alarm') { ?>
            <div class="alarm-bar"><?php echo ico('alert'); ?><strong>Rook gedetecteerd</strong> in de kamer. Controleer de ruimte en verlaat het gebouw bij brand.</div>
        <?php } ?>

        <!-- BANNER -->
        <section class="dash-banner">
            <div class="dash-banner-inner">
                <span class="live-pill"><i></i> Live • bijgewerkt om <?php echo $laatsteUpdate; ?></span>
                <h1>Dashboard</h1>
                <p>Overzicht en bediening van je Brand-Melder</p>
            </div>
        </section>

        <div class="dash-wrap">

            <!-- STATUS + OVERZICHT -->
            <div class="dash-top">
                <section class="card status-hero <?php echo $niveau; ?>">
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

                <section class="card list-card">
                    <h3 class="card-title">Systeem</h3>
                    <div class="row">
                        <span class="tile <?php echo $niveau; ?>"><?php echo ico('shield'); ?></span>
                        <div><small>Status</small><strong class="txt-<?php echo $niveau; ?>"><?php echo $systeemStatus; ?></strong></div>
                        <?php echo ico('chev', 'chev'); ?>
                    </div>
                    <div class="row">
                        <span class="tile"><?php echo ico('battery'); ?></span>
                        <div><small>Batterij</small><strong><?php echo $batterij; ?>%</strong>
                            <div class="bar"><i style="width:<?php echo $batterij; ?>%"></i></div></div>
                        <?php echo ico('chev', 'chev'); ?>
                    </div>
                    <div class="row">
                        <span class="tile"><?php echo ico('wifi'); ?></span>
                        <div><small>Verbinding</small><strong><i class="online"></i><?php echo $verbinding; ?></strong></div>
                        <?php echo ico('chev', 'chev'); ?>
                    </div>
                </section>
            </div>

            <!-- METINGEN -->
            <div class="section-row" id="grafieken"><h3>Metingen</h3><span>Laatste metingen uit database</span></div>
            <div class="chart-grid">
                <section class="card chart-card">
                    <div class="chart-head">
                        <span class="tile"><?php echo ico('therm'); ?></span>
                        <div><small>Temperatuur</small><strong><?php echo $huidigeTemp; ?> <em>°C</em></strong></div>
                    </div>
                    <?php echo lijngrafiek($tempReeks, '#e63946', ' °C', 't', $tijdReeks); ?>
                </section>
                <section class="card chart-card">
                    <div class="chart-head">
                        <span class="tile blue"><?php echo ico('wind'); ?></span>
                        <div><small>CO₂-gehalte</small><strong><?php echo $huidigeCo2; ?> <em>ppm</em></strong></div>
                    </div>
                    <?php echo lijngrafiek($co2Reeks, '#27466f', ' ppm', 'c', $tijdReeks); ?>
                </section>
            </div>

            <!-- BEDIENING + MELDINGEN -->
            <div class="dash-bottom">
                <section class="card">
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
                        <div><strong>Rookmelder</strong><small><?php echo $staat['melder'] ? 'Actief en bewaakt' : 'Uitgeschakeld'; ?></small></div>
                    </div>
                    <?php if ($staat['melder']) { ?>
                        <button type="button" class="ctrl-btn danger wide" id="openBevestiging"><?php echo ico('power'); ?>Rookmelder uitzetten</button>
                    <?php } else { ?>
                        <form method="post"><button name="actie" value="melder_aan" class="ctrl-btn success wide"><?php echo ico('power'); ?>Rookmelder aanzetten</button></form>
                        <p class="control-warning">De rookmelder staat uit en detecteert geen rook.</p>
                    <?php } ?>
                </section>

                <section class="card">
                    <h3 class="card-title">Recente meldingen</h3>
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

    <script>
        // Event delegation voor het bevestigingsvenster
        document.addEventListener('click', (e) => {
            const openBtn = e.target.closest('#openBevestiging');
            const cancelBtn = e.target.closest('#annuleer');
            const dlg = document.getElementById('bevestiging');

            if (openBtn && dlg) dlg.showModal();
            if (cancelBtn && dlg) dlg.close();
        });

        // Update alleen de live getallen en status op de achtergrond (zonder grafiek-flicker)
        setInterval(async () => {
            try {
                let url = window.location.href.split('?')[0] + '?t=' + Date.now();
                let response = await fetch(url, { cache: 'no-store' });
                let html = await response.text();
                let parser = new DOMParser();
                let doc = parser.parseFromString(html, 'text/html');
                
                // 1. Update temperatuur waarde
                let nieuweTemp = doc.querySelector('.chart-card:nth-of-type(1) .chart-head strong');
                let huidigeTemp = document.querySelector('.chart-card:nth-of-type(1) .chart-head strong');
                if (nieuweTemp && huidigeTemp) huidigeTemp.innerHTML = nieuweTemp.innerHTML;

                // 2. Update CO2 waarde
                let nieuweCo2 = doc.querySelector('.chart-card:nth-of-type(2) .chart-head strong');
                let huidigeCo2 = document.querySelector('.chart-card:nth-of-type(2) .chart-head strong');
                if (nieuweCo2 && huidigeCo2) huidigeCo2.innerHTML = nieuweCo2.innerHTML;

                // 3. Update batterij percentage
                let nieuweBat = doc.querySelector('.list-card .row:nth-child(2) strong');
                let huidigeBat = document.querySelector('.list-card .row:nth-child(2) strong');
                if (nieuweBat && huidigeBat) huidigeBat.innerHTML = nieuweBat.innerHTML;

                // 4. Update live tijd in de banner
                let nieuwePill = doc.querySelector('.live-pill');
                let huidigePill = document.querySelector('.live-pill');
                if (nieuwePill && huidigePill) huidigePill.innerHTML = nieuwePill.innerHTML;

            } catch (error) {
                console.error('Fout bij achtergrond-update:', error);
            }
        }, 10000); // Elke 10 seconden
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
