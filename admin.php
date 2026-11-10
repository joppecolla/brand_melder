<?php
session_start();
// Alleen accounts met admin-rechten mogen deze pagina zien
if (empty($_SESSION['gebruiker_id'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['is_admin']))     { header('Location: dashBoard.php'); exit; }
$beginAlarm = 1000; // ppm, beginwaarde tot het bedrijf een eigen waarde ingeeft

require 'db.php';
date_default_timezone_set('Europe/Brussels');

// ---------- Metingen van ALLE devices: laatste 24 uur, gemiddeld per tijdsblok ----------
$pdo->exec("ALTER TABLE sensor_data ADD COLUMN IF NOT EXISTS melder_id text");
$filter  = "melder_id IS NOT NULL AND created_at::timestamptz >= now() - interval '24 hours' AND co2 >= 300 AND temperatuur <> 0";
$spanMin = (float)$pdo->query("SELECT COALESCE(EXTRACT(EPOCH FROM (max(created_at::timestamptz) - min(created_at::timestamptz))) / 60, 0)
                               FROM sensor_data WHERE $filter")->fetchColumn();
$blok    = $spanMin <= 90 ? 120 : ($spanMin <= 360 ? 600 : 1800);   // seconden per punt
$historie = $pdo->query("SELECT melder_id, floor(EXTRACT(EPOCH FROM created_at::timestamptz) / $blok) * $blok AS blok,
                                AVG(temperatuur) AS t, AVG(co2) AS c, AVG(batterij) AS bat,
                                (array_agg(temperatuur ORDER BY created_at::timestamptz DESC))[1] AS t_laatst,
                                (array_agg(co2 ORDER BY created_at::timestamptz DESC))[1] AS c_laatst
                         FROM sensor_data WHERE $filter
                         GROUP BY 1, 2 ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC);

// Per device een eigen lijn; alle devices delen dezelfde tijdas
$blokken   = [];
$perMelder = [];
$laatste   = [];
foreach ($historie as $rij) {
    $t = round((float)$rij['t'], 1);
    $c = (int)round((float)$rij['c']);
    if ($t < -10 || $t > 60 || $c > 5000) { continue; }
    $b = (int)$rij['blok'];
    $blokken[$b] = date('H:i', $b);
    $perMelder[$rij['melder_id']][$b] = [$t, $c, (int)round((float)$rij['bat'])];
    $laatste[$rij['melder_id']] = [$b, round((float)$rij['t_laatst'], 1), (int)$rij['c_laatst']];
}
// Laatste punt van elk device = zijn laatst gemeten waarde (zoals op het dashboard)
foreach ($laatste as $melder => [$b, $t, $c]) {
    if ($t < -10 || $t > 60 || $c > 5000) { continue; }
    $perMelder[$melder][$b][0] = $t;
    $perMelder[$melder][$b][1] = $c;
}
ksort($blokken);
uksort($perMelder, fn($a, $b) => strnatcmp((string)$a, (string)$b));   // 1, 2, 10 in de juiste volgorde
$tijden = array_values($blokken);

$kleuren = ['#e63946', '#27466f', '#2a9d8f', '#f4a261', '#8e44ad', '#6c757d'];
$devices = [];
$nr = 0;
foreach ($perMelder as $melder => $punten) {
    $devices[] = [
        'id'       => (string)$melder,
        'naam'     => 'Melder ' . $melder,   // admin toont gewoon het ID, niet de namen van gebruikers
        'kleur'    => $kleuren[$nr++ % count($kleuren)],
        'temp'     => array_map(fn($b) => $punten[$b][0] ?? null, array_keys($blokken)),
        'co2'      => array_map(fn($b) => $punten[$b][1] ?? null, array_keys($blokken)),
        'batterij' => array_map(fn($b) => $punten[$b][2] ?? null, array_keys($blokken)),
    ];
}

function ico(string $n, string $extra = ''): string {
    $p = [
        'wind' => '<path d="M17.7 7.7a2.5 2.5 0 1 1 1.8 4.3H2M9.6 4.6A2 2 0 1 1 11 8H2M12.6 19.4A2 2 0 1 0 14 16H2"/>',
        'therm' => '<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>',
        'bell' => '<path d="M10.27 21a2 2 0 0 0 3.46 0M3.26 15.33A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.67C19.41 13.96 18 12.5 18 8a6 6 0 0 0-12 0c0 4.5-1.41 5.96-2.74 7.33"/>',
        'cpu' => '<rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6" rx="1"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
        'save' => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7M7 3v4a1 1 0 0 0 1 1h7"/>',
        'pencil' => '<path d="M21.17 6.81a2.83 2.83 0 0 0-4-4L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.35a.5.5 0 0 0 .62.62l4.35-1.32a2 2 0 0 0 .83-.5z"/><path d="m15 5 4 4"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'table' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>',
        'chart' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/>',
    ];
    return '<svg class="ico ' . $extra . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$n] . '</svg>';
}
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin | Brand-Melder</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- BOVENBALK: links naar de aparte pagina's -->
    <header class="sticky-header" id="topbar">
        <a href="index.php" class="logo"><span class="logo-icon">🚨</span>Brand-Melder</a>
        <nav>
            <a href="index.php">Home</a>
            <a href="dashBoard.php">Dashboard</a>
            <a href="admin.php" class="active">Admin</a>
            <?php if (isset($_SESSION['gebruiker_id'])) { ?><a href="logout.php">Uitloggen</a><?php } else { ?><a href="login.php">Login</a><?php } ?>
        </nav>
    </header>

    <main class="dash-page">

        <!-- BANNER met foto -->
        <section class="dash-banner">
            <div class="dash-banner-inner">
                <span class="live-pill"><i></i> <?php echo count($devices); ?> devices • bijgewerkt om <?php echo date('H:i'); ?></span>
                <h1>Admin</h1>
                <p>Instellingen en meetwaarden van alle Brand-Melders</p>
            </div>
        </section>

        <div class="dash-wrap">

            <!-- ALARMWAARDE -->
                <section class="card">
                    <h3 class="card-title">CO₂-alarmwaarde</h3>

                    <div class="control">
                        <span class="tile blue"><?php echo ico('bell'); ?></span>
                        <div><small>Alarm gaat af vanaf</small><strong class="threshold-value" id="alarmWeergave"><?php echo $beginAlarm; ?> <em>ppm</em></strong></div>
                    </div>

                    <form id="alarmForm" class="threshold-form">
                        <label class="input-row">
                            <span>Nieuwe alarmwaarde</span>
                            <span class="input-unit"><input type="number" id="alarmInput" min="1" step="1" value="<?php echo $beginAlarm; ?>" required><em>ppm</em></span>
                        </label>
                        <button type="submit" class="ctrl-btn primary wide"><?php echo ico('save'); ?>Opslaan</button>
                        <p class="save-msg" id="saveMsg" hidden></p>
                    </form>
                </section>
            </div>

            <!-- GRAFIEK -->
            <div class="section-row" id="grafiek"><h3>Verloop per device</h3><span>Laatste 24 uur</span></div>
            <section class="card">
                <div class="chart-toolbar">
                    <div class="chart-head">
                        <span class="tile blue" id="grafiekIcoon"><?php echo ico('chart'); ?></span>
                        <div><small id="grafiekLabel">CO₂-gehalte</small><strong id="grafiekTitel">Alle devices</strong></div>
                    </div>
                    <div class="segmented" role="tablist" aria-label="Meetwaarde">
                        <button type="button" class="active" data-meting="co2">CO₂</button>
                        <button type="button" data-meting="temp">Temperatuur</button>
                    </div>
                </div>
                <div class="legend" id="legende"></div>
                <svg id="adminGrafiek" class="chart" viewBox="0 0 840 300" role="img" aria-label="Grafiek met de meetwaarden van alle devices"></svg>
            </section>

            <!-- TABEL -->
            <div class="section-row" id="tabel"><h3>Meetwaarden</h3><span id="tabelTeller"></span></div>
            <section class="card">
                <div class="table-toolbar">
                    <div class="chart-head">
                        <span class="tile"><?php echo ico('table'); ?></span>
                        <div><small>Overzicht</small><strong class="small-strong">Alle metingen</strong></div>
                    </div>
                    <label class="select-wrap">
                        <span>Device</span>
                        <select id="deviceFilter">
                            <option value="alle">Alle devices</option>
                            <?php foreach ($devices as $d) { ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['naam']); ?></option>
                            <?php } ?>
                        </select>
                    </label>
                </div>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr><th>Tijdstip</th><th>Device</th><th>Temperatuur</th><th>CO₂</th><th>Batterij</th><th>Status</th></tr>                        </thead>
                        <tbody id="tabelBody"></tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>

    <footer class="footer">
        <div>
            <strong>🚨 Brand-Melder</strong>
            <p>Een slim IoT-project rond brandveiligheid.</p>
        </div>
        <div class="footer-links">
            <a href="index.php">Home</a>
            <a href="dashBoard.php">Dashboard</a>
            <?php if (!empty($_SESSION['is_admin'])) { ?><a href="admin.php">Admin</a><?php } ?>
        </div>
        <p class="copyright">© <?php echo date("Y"); ?> Brand-Melder</p>
    </footer>

    <script>
// ---------- Gegevens (voorlopig voorbeeldwaarden uit PHP) ----------
const devices = <?php echo json_encode($devices, JSON_UNESCAPED_UNICODE); ?>;
const tijden  = <?php echo json_encode($tijden); ?>;

// Alarmwaarde wordt opgehaald uit de database via de API
let alarm = <?php echo $beginAlarm; ?>;
const esc = t => String(t).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
let meting = 'co2';
const verborgen = new Set();

const invoer = document.getElementById('alarmInput');
const melding = document.getElementById('saveMsg');
const weergave = document.getElementById('alarmWeergave');

function toonAlarm() {
    weergave.innerHTML = `${alarm} <em>ppm</em>`;
    invoer.value = alarm;
    tekenGrafiek(); vulTabel();}
function toonMelding(tekst, fout) {
    melding.textContent = tekst; melding.classList.toggle('fout', !!fout); melding.hidden = false;
}
async function laadAlarm() {
    try {
        const response = await fetch('api.php', {
            method: 'GET',
            cache: 'no-store'
        });

        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error('Ophalen mislukt');
        }

        const waarde = Number(data.co2_drempel);

        if (!Number.isInteger(waarde) || waarde < 1 || waarde > 5000) {
            throw new Error('Ongeldige alarmwaarde ontvangen');
        }

        alarm = waarde;
        toonAlarm();

    } catch (error) {
        toonMelding('Kon alarmwaarde niet ophalen uit de database.', true);
    }
}
document.getElementById('alarmForm').addEventListener('submit', async e => {
    e.preventDefault();

    const w = Number(invoer.value);

    if (!Number.isInteger(w) || w < 1 || w > 5000) {
        toonMelding('Geef een waarde tussen 1 en 5000 ppm in.', true);
        return;
    }

    const knop = e.currentTarget.querySelector('button[type="submit"]');
    knop.disabled = true;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                actie: 'co2_drempel_inst',
                co2_drempel: w
            })
        });

        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(data.fout || 'Opslaan mislukt');
        }

        alarm = Number(data.co2_drempel);
        toonAlarm();
        toonMelding(`Alarmwaarde opgeslagen: ${alarm} ppm.`);

    } catch (error) {
        toonMelding('Opslaan mislukt: ' + error.message, true);
    } finally {
        knop.disabled = false;
    }
});

// ---------- Grafiek (SVG, zelfde stijl als dashboard) ----------
const NS = 'http://www.w3.org/2000/svg';
function el(naam, attr, tekst) {
    const e = document.createElementNS(NS, naam);
    for (const k in attr) e.setAttribute(k, attr[k]);
    if (tekst !== undefined) e.textContent = tekst;
    return e;
}

function tekenGrafiek() {
    const svg = document.getElementById('adminGrafiek');
    svg.replaceChildren();
    const B = 840, H = 300, L = 48, R = 16, T = 16, Bo = 30;
    const zichtbaar = devices.filter(d => !verborgen.has(d.id));
    const n = tijden.length;
    if (!n) {
        svg.append(el('text', {x: B / 2, y: H / 2, 'text-anchor': 'middle', class: 'axis'}, 'Nog geen metingen in de laatste 24 uur'));
        return;
    }
    let alle = zichtbaar.flatMap(d => d[meting]).filter(v => v !== null);
    if (meting === 'co2') alle.push(alarm);
    if (!alle.length) alle = [0, 1];
    const spreiding = Math.max(...alle) - Math.min(...alle) || 1;
    const stap = meting === 'co2' ? 50 : 0.5;
    let min = Math.floor((Math.min(...alle) - spreiding * .1) / stap) * stap;
    let max = min + Math.ceil((Math.max(...alle) + spreiding * .1 - min) / (4 * stap)) * 4 * stap;
    const x = i => L + (B - L - R) * (n === 1 ? .5 : i / (n - 1));
    const y = v => T + (H - T - Bo) * (1 - (v - min) / (max - min));

    for (let k = 0; k < 5; k++) {
        const yy = T + (H - T - Bo) * k / 4;
        svg.append(el('line', {x1: L, x2: B - R, y1: yy, y2: yy, class: 'grid'}));
        const val = max - (max - min) * k / 4;
        svg.append(el('text', {x: L - 8, y: yy + 4, 'text-anchor': 'end', class: 'axis'}, meting === 'co2' ? Math.round(val) : val.toFixed(1)));
    }
    tijden.forEach((t, i) => {
        // Ongeveer 7 uren onderaan, zodat ze niet over elkaar staan
        const elke = Math.max(1, Math.ceil(n / 7));
        if (i !== n - 1 && (i % elke !== 0 || n - 1 - i < elke / 2)) return;
        svg.append(el('text', {x: x(i), y: H - 8, 'text-anchor': 'middle', class: 'axis'}, i === n - 1 ? 'nu' : t));
    });

    if (meting === 'co2') {
        const ay = y(alarm);
        svg.append(el('line', {x1: L, x2: B - R, y1: ay, y2: ay, class: 'alarm-line'}));
        svg.append(el('text', {x: L + 6, y: ay - 7, 'text-anchor': 'start', class: 'alarm-label'}, `Alarm ${alarm} ppm`));
    }

    zichtbaar.forEach(d => {
        // Alleen tijdsblokken waarin dit device gemeten heeft
        const p = d[meting].map((v, i) => v === null ? null : [x(i), y(v), v, i]).filter(Boolean);
        if (!p.length) return;
        let pad = `M${p[0][0].toFixed(1)},${p[0][1].toFixed(1)}`;
        for (let i = 1; i < p.length; i++) {
            const cx = ((p[i - 1][0] + p[i][0]) / 2).toFixed(1);
            pad += ` C${cx},${p[i - 1][1].toFixed(1)} ${cx},${p[i][1].toFixed(1)} ${p[i][0].toFixed(1)},${p[i][1].toFixed(1)}`;
        }
        svg.append(el('path', {d: pad, fill: 'none', stroke: d.kleur, 'stroke-width': 3, 'stroke-linecap': 'round', class: 'line', pathLength: 1}));
        p.forEach(([px, py, v, i]) => {
            const boven = meting === 'co2' && v >= alarm;
            const c = el('circle', {cx: px, cy: py, r: boven ? 5 : 3.5, fill: boven ? 'var(--red)' : '#fff', stroke: boven ? '#fff' : d.kleur, 'stroke-width': 2, class: 'punt'});
            c.append(el('title', {}, `${d.naam} • ${tijden[i]} • ${v} ${meting === 'co2' ? 'ppm' : '°C'}`));
            svg.append(c);
        });
    });
}

// Legende: klik om een device te verbergen/tonen
const legende = document.getElementById('legende');
devices.forEach(d => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'legend-item';
    b.innerHTML = `<i style="background:${d.kleur}"></i><span></span>`;
    d.legendeLabel = b.querySelector('span');
    b.addEventListener('click', () => {
        verborgen.has(d.id) ? verborgen.delete(d.id) : verborgen.add(d.id);
        b.classList.toggle('off', verborgen.has(d.id));
        tekenGrafiek();
    });
    legende.append(b);
});

document.querySelectorAll('.segmented button').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.segmented button').forEach(x => x.classList.toggle('active', x === b));
    meting = b.dataset.meting;
    tekenGrafiek();
}));

// ---------- Tabel ----------
const filter = document.getElementById('deviceFilter');
filter.addEventListener('change', vulTabel);

function vulTabel() {
    const body = document.getElementById('tabelBody');
    body.replaceChildren();
    const rijen = [];
    devices.forEach(d => {
        if (filter.value !== 'alle' && filter.value !== d.id) return;
        tijden.forEach((t, i) => rijen.push({t, i, d}));
    });
    rijen.sort((a, b) => b.i - a.i || a.d.id.localeCompare(b.d.id));
    rijen.forEach(({t, i, d}) => {
        const co2 = d.co2[i], boven = co2 >= alarm;
        const tr = document.createElement('tr');
        if (boven) tr.className = 'is-alarm';
        tr.innerHTML = `
            <td>${i === tijden.length - 1 ? '<strong>' + t + '</strong> <small>nu</small>' : t}</td>
            <td><span class="dev-dot" style="background:${d.kleur}"></span>${esc(d.naam)}<small class="dev-id">${d.id}</small></td>
            <td>${d.locatie}</td>
            <td>${d.temp[i].toFixed(1)} °C</td>
            <td><strong>${co2}</strong> ppm</td>
            <td>${d.batterij}%</td>
            <td><span class="badge ${boven ? 'alarm' : 'ok'}">${boven ? 'Boven alarm' : 'OK'}</span></td>`;
        body.append(tr);
    });
    const aantalAlarm = rijen.filter(r => r.d.co2[r.i] >= alarm).length;
    document.getElementById('tabelTeller').textContent = `${rijen.length} metingen • ${aantalAlarm} boven alarmwaarde`;
}

// ---------- Namen in de legende en de keuzelijst zetten ----------
function vernieuwNamen() {
    devices.forEach(d => {
        d.legendeLabel.textContent = d.naam;
        filter.querySelector(`option[value="${d.id}"]`).textContent = d.naam;
    });
}

document.querySelectorAll('.device-row').forEach(r => {
    const d = devices.find(x => x.id === r.dataset.id);
    const form = r.querySelector('.naam-form'), naamEl = r.querySelector('.device-naam');
    const bewerk = r.querySelector('.bewerk'), veld = form.querySelector('input');
    const sluit = () => { form.hidden = true; naamEl.hidden = false; bewerk.hidden = false; };
    bewerk.addEventListener('click', () => {
        form.hidden = false; naamEl.hidden = true; bewerk.hidden = true;
        veld.value = d.naam; veld.focus(); veld.select();
    });
    form.querySelector('.annuleer').addEventListener('click', sluit);
    veld.addEventListener('keydown', e => { if (e.key === 'Escape') sluit(); });
    form.addEventListener('submit', e => {
        e.preventDefault();
        const naam = veld.value.trim();
        if (!naam) return;
        d.naam = naam; namen[d.id] = naam;
        localStorage.setItem('deviceNamen', JSON.stringify(namen));
        sluit(); vernieuwNamen();
    });
});

vernieuwNamen();
toonAlarm();
laadAlarm();

// Bovenbalk wordt compacter bij scrollen
const topbar = document.getElementById('topbar');
addEventListener('scroll', () => topbar.classList.toggle('scrolled', scrollY > 30), {passive:true});
    </script>
</body>
</html>
