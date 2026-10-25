<?php
require_once __DIR__ . '/auth.php';
$pdo = db();
$gebruiker = vereisLogin($pdo);
$melder = eersteMelder($pdo, (int)$gebruiker['id']);

$nieuweSleutel = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $melder && ($_POST['actie'] ?? '') === 'nieuwe_sleutel') {
    csrfControle();
    $nieuweSleutel = nieuweSleutel();
    $pdo->prepare("UPDATE melders SET sleutel_hash = ? WHERE melder_id = ? AND gebruiker_id = ?")
        ->execute([sleutelHash($nieuweSleutel), $melder['melder_id'], $gebruiker['id']]);
}

paginaKop('Account');
?>
<div class="auth-card">
    <h1>Mijn account</h1>
    <p>Ingelogd als <strong><?php echo h($gebruiker['email']); ?></strong></p>

    <?php if ($melder) { ?>
        <div class="form-veld">
            <label>Melder-ID</label>
            <div class="sleutel-box" style="margin:0"><?php echo h($melder['melder_id']); ?></div>
        </div>

        <?php if ($nieuweSleutel !== null) { ?>
            <div class="auth-ok">Nieuwe sleutel aangemaakt. Zet deze in je ESP32-code; de oude sleutel werkt niet meer. Je ziet hem maar één keer.</div>
            <div class="sleutel-box">const char* MELDER_ID      = "<?php echo h($melder['melder_id']); ?>";
const char* MELDER_SLEUTEL = "<?php echo h($nieuweSleutel); ?>";</div>
        <?php } else { ?>
            <p>De geheime sleutel van je melder wordt alleen versleuteld bewaard en kan dus niet opnieuw getoond worden. Ben je hem kwijt, maak dan een nieuwe aan.</p>
            <form method="post" onsubmit="return confirm('Je ESP32 werkt pas weer nadat je de nieuwe sleutel in de code hebt gezet. Doorgaan?');">
                <?php echo csrfVeld(); ?>
                <input type="hidden" name="actie" value="nieuwe_sleutel">
                <button type="submit" class="cta-button">Nieuwe sleutel maken</button>
            </form>
        <?php } ?>
    <?php } else { ?>
        <div class="auth-fout">Er is nog geen melder aan dit account gekoppeld.</div>
    <?php } ?>

    <p class="auth-alt"><a href="dashBoard.php">Naar het dashboard</a> · <a href="uitloggen.php">Uitloggen</a></p>
</div>
<?php paginaVoet();
