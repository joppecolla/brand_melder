<?php
$beginAlarm = 1000; // ppm, beginwaarde tot het bedrijf een eigen waarde ingeeft

// ---------- Voorbeeldwaarden (later vervangen door echte sensordata uit de backend) ----------
$devices = [
    ['id' => 'BM-01', 'naam' => 'Melder Woonkamer',  'locatie' => 'Woonkamer',  'online' => true,  'batterij' => 92, 'kleur' => '#27466f',
     'co2'  => [420, 430, 445, 440, 460, 470, 455, 465, 480, 475, 490, 480],
     'temp' => [20.6, 20.8, 21.0, 20.9, 21.2, 21.1, 21.4, 21.3, 21.5, 21.4, 21.6, 21.5]],
    ['id' => 'BM-02', 'naam' => 'Melder Keuken',     'locatie' => 'Keuken',     'online' => true,  'batterij' => 78, 'kleur' => '#2a9d8f',
     'co2'  => [610, 650, 720, 810, 900, 980, 1060, 1120, 1040, 960, 900, 870],
     'temp' => [21.8, 22.1, 22.9, 23.6, 24.2, 24.8, 25.1, 24.6, 23.9, 23.4, 23.0, 22.7]],
    ['id' => 'BM-03', 'naam' => 'Melder Slaapkamer', 'locatie' => 'Slaapkamer', 'online' => false, 'batterij' => 34, 'kleur' => '#f4a261',
     'co2'  => [700, 720, 740, 760, 790, 810, 830, 850, 860, 880, 890, 905],
     'temp' => [18.9, 18.8, 18.8, 18.7, 18.6, 18.6, 18.5, 18.5, 18.4, 18.4, 18.3, 18.3]],
];

// Tijdstippen: om de 10 minuten, laatste meting = nu
$tijden = [];
for ($i = 11; $i >= 0; $i--) { $tijden[] = date('H:i', time() - $i * 600); }

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

            <!-- ALARMWAARDE + DEVICES -->
            <div class="dash-top">
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

                <section class="card list-card">
                    <h3 class="card-title">Devices</h3>
                    <?php foreach ($devices as $d) { $laatste = end($d['co2']); ?>
                        <div class="row device-row" data-id="<?php echo $d['id']; ?>" data-co2="<?php echo $laatste; ?>">
                            <span class="tile sm device-tile" style="--dc: <?php echo $d['kleur']; ?>"><?php echo ico('cpu'); ?></span>
                            <div>
                                <strong class="device-naam"><?php echo htmlspecialchars($d['naam']); ?></strong>
                                <form class="naam-form" hidden>
                                    <input type="text" maxlength="40" required aria-label="Naam van <?php echo $d['id']; ?>">
                                    <button type="submit" class="icon-btn ok" title="Naam opslaan"><?php echo ico('save'); ?></button>
                                    <button type="button" class="icon-btn annuleer" title="Annuleren"><?php echo ico('x'); ?></button>
                                </form>
                                <small>
                                    <?php if ($d['online']) { ?><i class="online"></i>Online<?php } else { ?><i class="online offline"></i>Offline<?php } ?>
                                    • <?php echo $d['id']; ?> • Batterij <?php echo $d['batterij']; ?>%
                                </small>
                            </div>
                            <button type="button" class="icon-btn bewerk" title="Naam aanpassen"><?php echo ico('pencil'); ?></button>
                            <span class="device-co2"><span><strong><?php echo $laatste; ?></strong> <em>ppm</em></span><span class="badge"></span></span>
                        </div>
                    <?php } ?>
                </section>
            </div>

            <!-- GRAFIEK -->
            <div class="section-row" id="grafiek"><h3>Verloop per device</h3><span>Laatste 2 uur</span></div>
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
                            <tr><th>Tijdstip</th><th>Device</th><th>Locatie</th><th>Temperatuur</th><th>CO₂</th><th>Batterij</th><th>Status</th></tr>
                        </thead>
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
            <a href="admin.php">Admin</a>
        </div>
        <p class="copyright">© <?php echo date("Y"); ?> Brand-Melder</p>
    </footer>

    <script>
// ---------- Gegevens (voorlopig voorbeeldwaarden uit PHP) ----------
const devices = <?php echo json_encode($devices, JSON_UNESCAPED_UNICODE); ?>;
const tijden  = <?php echo json_encode($tijden); ?>;

// Alarmwaarde wordt voorlopig enkel in de browser bewaard (geen backend)
let alarm = Number(localStorage.getItem('co2Alarm')) || <?php echo $beginAlarm; ?>;
const namen = JSON.parse(localStorage.getItem('deviceNamen') || '{}');
devices.forEach(d => { if (namen[d.id]) d.naam = namen[d.id]; });
const esc = t => String(t).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
let meting = 'co2';
const verborgen = new Set();

const invoer = document.getElementById('alarmInput');
const melding = document.getElementById('saveMsg');
const weergave = document.getElementById('alarmWeergave');

function toonAlarm() {
    weergave.innerHTML = `${alarm} <em>ppm</em>`;
    invoer.value = alarm;
    tekenGrafiek(); vulTabel(); updateDevices();
}
function toonMelding(tekst, fout) {
    melding.textContent = tekst; melding.classList.toggle('fout', !!fout); melding.hidden = false;
}
document.getElementById('alarmForm').addEventListener('submit', e => {
    e.preventDefault();
    const w = Number(invoer.value);
    if (!(w > 0)) { toonMelding('Geef een geldige waarde in ppm in.', true); return; }
    alarm = Math.round(w);
    localStorage.setItem('co2Alarm', alarm);
    toonAlarm();
    toonMelding(`Alarmwaarde ingesteld op ${alarm} ppm (voorlopig enkel lokaal bewaard).`);
});

// ---------- Devices: badge boven/onder alarmwaarde ----------
function updateDevices() {
    document.querySelectorAll('.device-row').forEach(r => {
        const b = r.querySelector('.badge');
        const boven = Number(r.dataset.co2) >= alarm;
        b.textContent = boven ? 'Alarm' : 'OK';
        b.className = 'badge ' + (boven ? 'alarm' : 'ok');
    });
}

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
    let alle = zichtbaar.flatMap(d => d[meting]);
    if (meting === 'co2') alle.push(alarm);
    if (!alle.length) alle = [0, 1];
    const spreiding = Math.max(...alle) - Math.min(...alle) || 1;
    const stap = meting === 'co2' ? 50 : 0.5;
    let min = Math.floor((Math.min(...alle) - spreiding * .1) / stap) * stap;
    let max = min + Math.ceil((Math.max(...alle) + spreiding * .1 - min) / (4 * stap)) * 4 * stap;
    const n = tijden.length;
    const x = i => L + (B - L - R) * i / (n - 1);
    const y = v => T + (H - T - Bo) * (1 - (v - min) / (max - min));

    for (let k = 0; k < 5; k++) {
        const yy = T + (H - T - Bo) * k / 4;
        svg.append(el('line', {x1: L, x2: B - R, y1: yy, y2: yy, class: 'grid'}));
        const val = max - (max - min) * k / 4;
        svg.append(el('text', {x: L - 8, y: yy + 4, 'text-anchor': 'end', class: 'axis'}, meting === 'co2' ? Math.round(val) : val.toFixed(1)));
    }
    tijden.forEach((t, i) => {
        if (i % 2 === 1 && i !== n - 1) return;
        svg.append(el('text', {x: x(i), y: H - 8, 'text-anchor': 'middle', class: 'axis'}, i === n - 1 ? 'nu' : t));
    });

    if (meting === 'co2') {
        const ay = y(alarm);
        svg.append(el('line', {x1: L, x2: B - R, y1: ay, y2: ay, class: 'alarm-line'}));
        svg.append(el('text', {x: L + 6, y: ay - 7, 'text-anchor': 'start', class: 'alarm-label'}, `Alarm ${alarm} ppm`));
    }

    zichtbaar.forEach(d => {
        const p = d[meting].map((v, i) => [x(i), y(v)]);
        let pad = `M${p[0][0].toFixed(1)},${p[0][1].toFixed(1)}`;
        for (let i = 1; i < p.length; i++) {
            const cx = ((p[i - 1][0] + p[i][0]) / 2).toFixed(1);
            pad += ` C${cx},${p[i - 1][1].toFixed(1)} ${cx},${p[i][1].toFixed(1)} ${p[i][0].toFixed(1)},${p[i][1].toFixed(1)}`;
        }
        svg.append(el('path', {d: pad, fill: 'none', stroke: d.kleur, 'stroke-width': 3, 'stroke-linecap': 'round', class: 'line', pathLength: 1}));
        d[meting].forEach((v, i) => {
            const boven = meting === 'co2' && v >= alarm;
            const c = el('circle', {cx: p[i][0], cy: p[i][1], r: boven ? 5 : 3.5, fill: boven ? 'var(--red)' : '#fff', stroke: boven ? '#fff' : d.kleur, 'stroke-width': 2, class: 'punt'});
            c.append(el('title', {}, `${d.naam} • ${tijden[i]} • ${v} ${meting === 'co2' ? 'ppm' : '°C'}`));
            svg.append(c);
        });
    });

    document.getElementById('grafiekLabel').textContent = meting === 'co2' ? 'CO₂-gehalte (ppm)' : 'Temperatuur (°C)';
    document.getElementById('grafiekTitel').textContent = zichtbaar.length === devices.length ? 'Alle devices' : `${zichtbaar.length} van ${devices.length} devices`;
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

// ---------- Devicenamen aanpassen (voorlopig enkel lokaal bewaard) ----------
function vernieuwNamen() {
    devices.forEach(d => {
        d.legendeLabel.textContent = d.naam;
        filter.querySelector(`option[value="${d.id}"]`).textContent = d.naam;
        document.querySelector(`.device-row[data-id="${d.id}"] .device-naam`).textContent = d.naam;
    });
    tekenGrafiek(); vulTabel();
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

// Bovenbalk wordt compacter bij scrollen
const topbar = document.getElementById('topbar');
addEventListener('scroll', () => topbar.classList.toggle('scrolled', scrollY > 30), {passive:true});
    </script>
</body>
</html>
