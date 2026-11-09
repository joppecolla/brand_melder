<?php
session_start();
require 'db.php';

$fout = '';
$email = '';
$melderTekst = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email       = strtolower(trim($_POST['email'] ?? ''));
    $wachtwoord  = $_POST['wachtwoord'] ?? '';
    $herhaal     = $_POST['herhaal'] ?? '';
    $melderTekst = trim($_POST['melder_ids'] ?? '');

    // ID's gescheiden door komma's, bv. "BM-01, BM-02"
    $melderIds = array_values(array_unique(array_filter(array_map('trim', explode(',', $melderTekst)), 'strlen')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fout = 'Geef een geldig e-mailadres in.';
    } elseif (strlen($wachtwoord) < 8) {
        $fout = 'Het wachtwoord moet minstens 8 tekens lang zijn.';
    } elseif ($wachtwoord !== $herhaal) {
        $fout = 'De wachtwoorden zijn niet hetzelfde.';
    } elseif (!$melderIds) {
        $fout = 'Geef minstens één ID van een brandmelder in.';
    } else {
        foreach ($melderIds as $id) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,30}$/', $id)) {
                $fout = "Ongeldig melder-ID: \"$id\". Gebruik enkel letters, cijfers, - en _.";
                break;
            }
        }
    }

    if ($fout === '') {
        $st = $pdo->prepare("SELECT 1 FROM gebruikers WHERE email = ?");
        $st->execute([$email]);
        if ($st->fetch()) {
            $fout = 'Er bestaat al een account met dit e-mailadres.';
        } else {
            $pdo->prepare("INSERT INTO gebruikers (email, wachtwoord_hash, melder_ids) VALUES (?, ?, CAST(? AS text[]))")
                ->execute([$email, password_hash($wachtwoord, PASSWORD_DEFAULT), '{' . implode(',', $melderIds) . '}']);

            session_regenerate_id(true);
            $_SESSION['gebruiker_id'] = (int)$pdo->lastInsertId();
            $_SESSION['email']        = $email;
            $_SESSION['melder_ids']   = $melderIds;
            $_SESSION['is_admin']     = false;
            header('Location: dashBoard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registreren | Brand-Melder</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <header class="sticky-header" id="topbar">
        <a href="index.php" class="logo"><span class="logo-icon">🚨</span>Brand-Melder</a>
        <nav>
            <a href="index.php">Home</a>
            <a href="dashBoard.php">Dashboard</a>
            <?php if (!empty($_SESSION['is_admin'])) { ?><a href="admin.php">Admin</a><?php } ?>
            <a href="login.php" class="active">Login</a>
        </nav>
    </header>

    <main class="dash-page">
        <section class="dash-banner">
            <div class="dash-banner-inner">
                <h1>Registreren</h1>
                <p>Maak een account aan en koppel je Brand-Melders</p>
            </div>
        </section>

        <div class="dash-wrap">
            <section class="card auth-card">
                <h3 class="card-title">Nieuw account</h3>

                <?php if ($fout) { ?><p class="auth-msg fout"><?php echo htmlspecialchars($fout); ?></p><?php } ?>

                <form method="post" class="auth-form">
                    <label>
                        <span>E-mailadres</span>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required autocomplete="email">
                    </label>
                    <label>
                        <span>Wachtwoord</span>
                        <input type="password" name="wachtwoord" minlength="8" required autocomplete="new-password">
                    </label>
                    <label>
                        <span>Herhaal wachtwoord</span>
                        <input type="password" name="herhaal" minlength="8" required autocomplete="new-password">
                    </label>
                    <label>
                        <span>ID's van je brandmelders</span>
                        <input type="text" name="melder_ids" value="<?php echo htmlspecialchars($melderTekst); ?>" placeholder="bv. 1, 2" required>                        <small>Meerdere ID's scheid je met een komma.</small>
                    </label>
                    <button type="submit" class="ctrl-btn primary wide">Registreren</button>
                </form>

                <p class="auth-switch">Al een account? <a href="login.php">Log hier in</a></p>
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

</body>
</html>