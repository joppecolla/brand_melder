<?php
session_start();
require 'db.php';

$fout = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $wachtwoord = $_POST['wachtwoord'] ?? '';

    $st = $pdo->prepare("SELECT id, email, wachtwoord_hash, is_admin, array_to_json(melder_ids) AS melder_ids FROM gebruikers WHERE email = ?");
    $st->execute([$email]);
    $g = $st->fetch(PDO::FETCH_ASSOC);

    if ($g && password_verify($wachtwoord, $g['wachtwoord_hash'])) {
        session_regenerate_id(true);
        $_SESSION['gebruiker_id'] = (int)$g['id'];
        $_SESSION['email']        = $g['email'];
        $_SESSION['melder_ids']   = json_decode($g['melder_ids'], true) ?: [];
        $_SESSION['is_admin']     = ($g['is_admin'] === true || $g['is_admin'] === 't');
        header('Location: dashBoard.php');
        exit;
    }
    $fout = 'E-mailadres of wachtwoord is fout.';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Brand-Melder</title>
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
                <h1>Login</h1>
                <p>Log in om je Brand-Melders te bekijken</p>
            </div>
        </section>

        <div class="dash-wrap">
            <section class="card auth-card">
                <h3 class="card-title">Inloggen</h3>

                <?php if (isset($_GET['uit'])) { ?><p class="auth-msg">Je bent uitgelogd.</p><?php } ?>
                <?php if ($fout) { ?><p class="auth-msg fout"><?php echo htmlspecialchars($fout); ?></p><?php } ?>

                <form method="post" class="auth-form">
                    <label>
                        <span>E-mailadres</span>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required autocomplete="email">
                    </label>
                    <label>
                        <span>Wachtwoord</span>
                        <input type="password" name="wachtwoord" required autocomplete="current-password">
                    </label>
                    <button type="submit" class="ctrl-btn primary wide">Inloggen</button>
                </form>

                <p class="auth-switch">Nog geen account? <a href="register.php">Registreer hier</a></p>
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

</body>
</html>