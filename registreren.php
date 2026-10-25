<?php
require_once __DIR__ . '/auth.php';
$pdo = db();

if (huidigeGebruiker($pdo)) { header('Location: dashBoard.php'); exit; }

$fouten = [];
$email = '';
$melderId = '';
$sleutel = null;   // wordt na succes één keer getoond

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfControle();

    $email    = strtolower(trim((string)($_POST['email'] ?? '')));
    $ww       = (string)($_POST['wachtwoord'] ?? '');
    $ww2      = (string)($_POST['wachtwoord2'] ?? '');
    $melderId = strtoupper(trim((string)($_POST['melder_id'] ?? '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $fouten[] = 'Vul een geldig e-mailadres in.';
    }
    if (strlen($ww) < 8 || strlen($ww) > 72) {
        $fouten[] = 'Je wachtwoord moet tussen 8 en 72 tekens lang zijn.';
    } elseif ($ww !== $ww2) {
        $fouten[] = 'De twee wachtwoorden komen niet overeen.';
    }
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/', $melderId)) {
        $fouten[] = 'Het melder-ID mag 3 tot 32 tekens zijn: letters, cijfers, - of _.';
    }

    if (!$fouten) {
        $st = $pdo->prepare("SELECT 1 FROM gebruikers WHERE email = ?");
        $st->execute([$email]);
        if ($st->fetch()) { $fouten[] = 'Dit e-mailadres heeft al een account.'; }

        $st = $pdo->prepare("SELECT 1 FROM melders WHERE melder_id = ?");
        $st->execute([$melderId]);
        if ($st->fetch()) { $fouten[] = 'Dit melder-ID is al in gebruik. Kies een ander ID.'; }
    }

    if (!$fouten) {
        $nieuw = nieuweSleutel();
        try {
            // Eén statement: gebruiker, melder en status worden samen aangemaakt of helemaal niet
            $pdo->prepare(
                "WITH g AS (INSERT INTO gebruikers (email, wachtwoord_hash) VALUES (:e, :h) RETURNING id),
                      m AS (INSERT INTO melders (melder_id, gebruiker_id, sleutel_hash)
                            SELECT :m, id, :s FROM g RETURNING melder_id)
                 INSERT INTO melder_status (melder_id) SELECT melder_id FROM m"
            )->execute([
                'e' => $email,
                'h' => password_hash($ww, PASSWORD_DEFAULT),
                'm' => $melderId,
                's' => sleutelHash($nieuw),
            ]);

            $st = $pdo->prepare("SELECT id FROM gebruikers WHERE email = ?");
            $st->execute([$email]);
            loginSessie((int)$st->fetchColumn());
            $sleutel = $nieuw;
        } catch (PDOException $e) {
            error_log('registreren: ' . $e->getMessage());
            $fouten[] = ($e->getCode() === '23505')
                ? 'Dit e-mailadres of melder-ID is net door iemand anders gebruikt.'
                : 'Registreren is mislukt. Probeer het later opnieuw.';
        }
    }
}

paginaKop('Registreren');
?>
<div class="auth-card">
<?php if ($sleutel !== null) { ?>
    <h1>Account aangemaakt 🎉</h1>
    <p>Zet deze twee regels bovenaan in de code van je ESP32. <strong>De sleutel zie je maar één keer.</strong> Raak je hem kwijt, dan maak je op de accountpagina een nieuwe.</p>
    <div class="sleutel-box">const char* MELDER_ID      = "<?php echo h($melderId); ?>";
const char* MELDER_SLEUTEL = "<?php echo h($sleutel); ?>";</div>
    <a class="cta-button" href="dashBoard.php">Naar mijn dashboard</a>
<?php } else { ?>
    <h1>Account maken</h1>
    <p>Koppel je brandmelder aan je eigen account, zodat alleen jouw metingen op jouw dashboard komen.</p>

    <?php foreach ($fouten as $f) { ?><div class="auth-fout"><?php echo h($f); ?></div><?php } ?>

    <form method="post" autocomplete="on">
        <?php echo csrfVeld(); ?>
        <div class="form-veld">
            <label for="email">E-mailadres</label>
            <input type="email" id="email" name="email" value="<?php echo h($email); ?>" required autocomplete="email">
        </div>
        <div class="form-veld">
            <label for="wachtwoord">Wachtwoord</label>
            <input type="password" id="wachtwoord" name="wachtwoord" required minlength="8" maxlength="72" autocomplete="new-password">
            <small>Minstens 8 tekens. Je wachtwoord wordt versleuteld opgeslagen en is voor niemand leesbaar.</small>
        </div>
        <div class="form-veld">
            <label for="wachtwoord2">Herhaal wachtwoord</label>
            <input type="password" id="wachtwoord2" name="wachtwoord2" required minlength="8" maxlength="72" autocomplete="new-password">
        </div>
        <div class="form-veld">
            <label for="melder_id">Melder-ID</label>
            <input type="text" id="melder_id" name="melder_id" value="<?php echo h($melderId); ?>" required maxlength="32" placeholder="bv. JARNE-01" autocapitalize="characters">
            <small>Kies een unieke naam voor jouw melder. Dit ID komt ook in de code van je ESP32.</small>
        </div>
        <button type="submit" class="cta-button">Account maken</button>
    </form>
    <p class="auth-alt">Al een account? <a href="inloggen.php">Inloggen</a></p>
<?php } ?>
</div>
<?php paginaVoet();
