<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brand-Melder | Slimme brandveiligheid</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- BOVENBALK: enkel links naar aparte pagina's (komen later) -->
    <header class="sticky-header" id="topbar">
        <a href="index.php" class="logo"><span class="logo-icon">🚨</span>Brand-Melder</a>
        <nav>
            <a href="index.php" class="active">Home</a>
            <!-- TODO: vervang deze placeholders door de echte pagina's -->
            <a href="dashBoard.php">Dashboard</a>
            <a href="#">Pagina 3</a>
        </nav>
    </header>

    <!-- ZIJNAVIGATIE (links): secties op deze pagina -->
    <aside class="side-nav" aria-label="Secties op deze pagina">
        <a href="#home" class="active"><span>Home</span></a>
        <a href="#over"><span>Over het project</span></a>
        <a href="#functionaliteiten"><span>Functies</span></a>
        <a href="#werking"><span>Werking</span></a>
        <a href="#doelgroep"><span>Voor wie?</span></a>
    </aside>


    <!-- =========================
         HERO
    ========================== -->
    <main>

        <section id="home" class="hero-section">
            <div class="hero-overlay"></div>

            <div class="hero-content">
                <div class="eyebrow">
                    <span></span> Slimme brandveiligheid
                </div>

                <h1>
                    Maximale veiligheid,<br>
                    <strong>met een glimlach.</strong>
                </h1>

                <p>
                    De Brand-Melder combineert sensoren, IoT en een eigen
                    webinterface in één slim systeem. Geen gewone piep,
                    maar duidelijke meldingen, live gegevens en een
                    ludieke alarmmelding.
                </p>

                <div class="hero-buttons">
                    <a href="dashBoard.php" class="cta-button">
                        Bekijk Live Gegevens
                    </a>

                    <a href="#functionaliteiten" class="secondary-button">
                        Ontdek de functies
                    </a>
                </div>
            </div>
        </section>


        <!-- =========================
             VERSCHUIVENDE BALK
        ========================== -->
        <section class="ticker-section" aria-label="Belangrijkste functies">
            <div class="ticker-track">
                <div class="ticker-item">🔥 ROOKDETECTIE</div>
                <div class="ticker-item">🌡️ TEMPERATUUR</div>
                <div class="ticker-item">💨 CO₂-METING</div>
                <div class="ticker-item">🔊 STEMMELDINGEN</div>
                <div class="ticker-item">📧 E-MAILNOTIFICATIES</div>
                <div class="ticker-item">📱 WEBINTERFACE</div>
                <div class="ticker-item">📊 LIVE DASHBOARD</div>

                <!-- Herhaling voor een continue animatie -->
                <div class="ticker-item">🔥 ROOKDETECTIE</div>
                <div class="ticker-item">🌡️ TEMPERATUUR</div>
                <div class="ticker-item">💨 CO₂-METING</div>
                <div class="ticker-item">🔊 STEMMELDINGEN</div>
                <div class="ticker-item">📧 E-MAILNOTIFICATIES</div>
                <div class="ticker-item">📱 WEBINTERFACE</div>
                <div class="ticker-item">📊 LIVE DASHBOARD</div>
            </div>
        </section>


        <!-- =========================
             PROJECT INTRO
        ========================== -->
        <section id="over" class="section intro-section">
            <div class="section-heading">
                <span class="section-label">HET PROJECT</span>
                <h2>Wat is de <span>Brand-Melder?</span></h2>
            </div>

            <div class="intro-grid">
                <div class="intro-text">
                    <p class="lead">
                        De Brand-Melder is een zelfgemaakt IoT-systeem dat
                        veiligheid en technologie combineert met een ludieke
                        twist.
                    </p>

                    <p>
                        Het systeem meet onder andere temperatuur en CO₂ en
                        kan rook/brand detecteren. Wanneer een grenswaarde
                        wordt overschreden, wordt de gebruiker gewaarschuwd.
                        In plaats van alleen een klassieke pieptoon kan de
                        melder een muziekfragment of duidelijke stemmelding
                        afspelen.
                    </p>

                    <p>
                        Via de eigen website krijgt de gebruiker bovendien
                        inzicht in de actuele meetgegevens en kan het
                        alarmsignaal op afstand worden beheerd.
                    </p>
                </div>

                <div class="problem-card">
                    <div class="problem-icon">!</div>
                    <div>
                        <span class="small-title">HET PROBLEEM</span>
                        <h3>Een klassieke rookmelder vertelt weinig.</h3>
                        <p>
                            Traditionele melders geven vooral een irritante
                            pieptoon. Ze geven weinig inzicht in temperatuur
                            of luchtkwaliteit en bieden geen handige remote
                            controle.
                        </p>
                    </div>
                </div>
            </div>
        </section>


        <!-- =========================
             FUNCTIES
        ========================== -->
        <section id="functionaliteiten" class="section features-section alt-bg">

            <div class="section-heading centered">
                <span class="section-label">SLIMME TECHNOLOGIE</span>
                <h2>Meer dan alleen een <span>rookmelder.</span></h2>
                <p>
                    Hardware, sensoren en webtechnologie werken samen in één
                    systeem.
                </p>
            </div>

            <div class="features-grid">

                <article class="feature-card">
                    <div class="feature-number">01</div>
                    <div class="feature-icon">🔥</div>
                    <h3>Rookdetectie</h3>
                    <p>
                        Detecteert rook en vormt de basis voor een snelle
                        alarmmelding.
                    </p>
                </article>

                <article class="feature-card">
                    <div class="feature-number">02</div>
                    <div class="feature-icon">🌡️</div>
                    <h3>Temperatuurmeting</h3>
                    <p>
                        Meet de temperatuur in de ruimte en maakt deze
                        informatie zichtbaar via de website.
                    </p>
                </article>

                <article class="feature-card">
                    <div class="feature-number">03</div>
                    <div class="feature-icon">💨</div>
                    <h3>CO₂-meting</h3>
                    <p>
                        Geeft extra informatie over de luchtkwaliteit en de
                        omstandigheden in de ruimte.
                    </p>
                </article>

                <article class="feature-card">
                    <div class="feature-number">04</div>
                    <div class="feature-icon">🔊</div>
                    <h3>Ludiek alarm</h3>
                    <p>
                        Bij een noodsituatie kan een muziekfragment worden
                        afgespeeld in plaats van alleen een klassieke piep.
                    </p>
                </article>

                <article class="feature-card">
                    <div class="feature-number">05</div>
                    <div class="feature-icon">🗣️</div>
                    <h3>Stemmeldingen</h3>
                    <p>
                        Een ingesproken melding kan bijvoorbeeld waarschuwen
                        wanneer de batterij bijna leeg is.
                    </p>
                </article>

                <article class="feature-card">
                    <div class="feature-number">06</div>
                    <div class="feature-icon">📧</div>
                    <h3>E-mailnotificaties</h3>
                    <p>
                        Waarschuwingen kunnen automatisch naar de gebruiker
                        worden verstuurd bij belangrijke gebeurtenissen.
                    </p>
                </article>

                <article class="feature-card">
                    <div class="feature-number">07</div>
                    <div class="feature-icon">📱</div>
                    <h3>Webinterface</h3>
                    <p>
                        Bekijk live meetgegevens en de status van de melder
                        via een eigen website.
                    </p>
                </article>

                <article class="feature-card">
                    <div class="feature-number">08</div>
                    <div class="feature-icon">🎛️</div>
                    <h3>Controle op afstand</h3>
                    <p>
                        Het alarmsignaal kan via de webinterface worden
                        beheerd, bijvoorbeeld bij een vals alarm.
                    </p>
                </article>

            </div>
        </section>


        <!-- =========================
             HOE WERKT HET?
        ========================== -->
        <section id="werking" class="section working-section">
            <div class="scroll-steps">
                <div class="scroll-steps-left">
                    <span class="section-label">VAN SENSOR TOT WEBSITE</span>
                    <h2>Hoe werkt het <span>systeem?</span></h2>
                    <ol class="step-tracker"><li class="active" data-step="1">Meten</li><li data-step="2">Verwerken</li><li data-step="3">Waarschuwen</li><li data-step="4">Bekijken</li></ol>
                </div>
                <div class="scroll-steps-right">
                <article class="scroll-step active" data-step="1">
                    <div class="big-number">01</div>
                    <h3>Meten</h3>
                    <p>Sensoren verzamelen gegevens over rook, temperatuur en CO₂.</p>
                </article>
                <article class="scroll-step" data-step="2">
                    <div class="big-number">02</div>
                    <h3>Verwerken</h3>
                    <p>De elektronica verwerkt de meetwaarden en controleert of een alarm nodig is.</p>
                </article>
                <article class="scroll-step" data-step="3">
                    <div class="big-number">03</div>
                    <h3>Waarschuwen</h3>
                    <p>De melder geeft een geluid-, muziek- of stemmelding wanneer dat nodig is.</p>
                </article>
                <article class="scroll-step" data-step="4">
                    <div class="big-number">04</div>
                    <h3>Bekijken</h3>
                    <p>De website toont de actuele toestand en meetgegevens van het systeem.</p>
                </article>
                </div>
            </div>
        </section>

<!-- =========================
             DOELGROEP
        ========================== -->
        <section id="doelgroep" class="audience-section">
            <div class="audience-content">
                <span class="section-label light">VOOR WIE?</span>
                <h2>Veiligheid voor <span>slimme ruimtes.</span></h2>
                <p>
                    De Brand-Melder is vooral gericht op studentenhuizen,
                    hobbyruimtes en technische labo's waar veiligheid,
                    technologie en speelsheid samenkomen.
                </p>

                <div class="audience-tags">
                    <span>Studentenhuizen</span>
                    <span>Hobbyruimtes</span>
                    <span>Technische labo's</span>
                    <span>IoT-projecten</span>
                </div>
            </div>
        </section>
        


        <!-- =========================
             CTA
        ========================== -->
        <section class="cta-section">
            <div>
                <span class="section-label light">BRAND-MELDER</span>
                <h2>Veiligheid kan ook <span>leuker.</span></h2>
                <p>
                    Een technisch IoT-project dat brandveiligheid,
                    meetgegevens en een eigen webinterface samenbrengt.
                </p>
            </div>

            <a href="dashBoard.php" class="cta-button white-button">
                Naar het dashboard →
            </a>
        </section>

    </main>


    <!-- =========================
         FOOTER
    ========================== -->
        <footer class="footer">
        <div>
            <strong>🚨 Brand-Melder</strong>
            <p>Een slim IoT-project rond brandveiligheid.</p>
        </div>
        <div class="footer-links">
            <a href="#home">Home</a>
            <a href="#over">Over het project</a>
            <a href="#functionaliteiten">Functies</a>
            <a href="dashBoard.php">Dashboard</a>
        </div>
        <p class="copyright">© <?php echo date("Y"); ?> Brand-Melder</p>
    </footer>

    <script>
// Bovenbalk wordt compacter bij scrollen
const topbar = document.getElementById('topbar');
addEventListener('scroll', () => topbar.classList.toggle('scrolled', scrollY > 30), {passive:true});

// Zijnavigatie: actieve sectie markeren
const sideLinks = document.querySelectorAll('.side-nav a');
if (sideLinks.length) {
    const io = new IntersectionObserver(es => es.forEach(e => {
        if (e.isIntersecting) sideLinks.forEach(a =>
            a.classList.toggle('active', a.getAttribute('href') === '#' + e.target.id));
    }), {rootMargin: '-45% 0px -50% 0px'});
    sideLinks.forEach(a => { const s = document.querySelector(a.getAttribute('href')); if (s) io.observe(s); });
}

// Stappen: actieve stap oplichten tijdens het scrollen
const stappen = document.querySelectorAll('.scroll-step');
if (stappen.length) {
    const io2 = new IntersectionObserver(es => es.forEach(e => {
        if (!e.isIntersecting) return;
        const n = e.target.dataset.step;
        stappen.forEach(s => s.classList.toggle('active', s.dataset.step === n));
        document.querySelectorAll('.step-tracker li').forEach(li =>
            li.classList.toggle('active', li.dataset.step === n));
    }), {rootMargin: '-40% 0px -40% 0px'});
    stappen.forEach(s => io2.observe(s));
}
</script>
</body>
</html>