<?php
require_once __DIR__ . '/auth.php';
$pdo = db();

if (huidigeGebruiker($pdo)) { header('Location: dashBoard.php'); exit; }

$fout = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfControle();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $ww    = (string)($_POST['wachtwoord'] ?? '');

    $st = $pdo->prepare("SELECT id, wachtwoord_hash FROM gebruikers WHERE email = ?");
    $st->execute([$email]);
    $g = $st->fetch();

    // Ook bij een onbekend e-mailadres even rekenen, zodat je niet aan de snelheid kunt zien of een account bestaat
    $hash = $g ? $g['wachtwoord_hash'] : password_hash('onbekend', PASSWORD_DEFAULT);
    $klopt = password_verify($ww, $hash);

    if ($g && $klopt) {
        if (password_needs_rehash($g['wachtwoord_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare("UPDATE gebruikers SET wachtwoord_hash = ? WHERE id = ?")
                ->execute([password_hash($ww, PASSWORD_DEFAULT), $g['id']]);
        }
        loginSessie((int)$g['id']);
        header('Location: dashBoard.php');
        exit;
    }
    usleep(400000);
    $fout = 'E-mailadres of wachtwoord klopt niet.';
}

paginaKop('Inloggen');
?>
<div class="auth-card">
    <h1>Inloggen</h1>
    <p>Log in om het dashboard van je melder te zien.</p>

    <?php if ($fout) { ?><div class="auth-fout"><?php echo h($fout); ?></div><?php } ?>

    <form method="post">
        <?php echo csrfVeld(); ?>
        <div class="form-veld">
            <label for="email">E-mailadres</label>
            <input type="email" id="email" name="email" value="<?php echo h($email); ?>" required autocomplete="email">
        </div>
        <div class="form-veld">
            <label for="wachtwoord">Wachtwoord</label>
            <input type="password" id="wachtwoord" name="wachtwoord" required autocomplete="current-password">
        </div>
        <button type="submit" class="cta-button">Inloggen</button>
    </form>
    <p class="auth-alt">Nog geen account? <a href="registreren.php">Registreren</a></p>
</div>
<?php paginaVoet();
