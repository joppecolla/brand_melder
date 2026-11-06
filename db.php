<?php
// Gedeelde databaseverbinding (Neon PostgreSQL via Environment Variables)
$host = getenv('NEON_HOST');
$db   = getenv('NEON_DB');
$user = getenv('NEON_USER');
$pass = getenv('NEON_PASS');
$port = getenv('NEON_PORT') ?: '5432';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db;sslmode=require", $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]);
} catch (PDOException $e) {
    error_log('db.php: ' . $e->getMessage());
    die('Databaseverbinding mislukt.');
}

// Tabel voor de accounts; melder_ids = lijst met ID's van de brandmelders van deze gebruiker
$pdo->exec("CREATE TABLE IF NOT EXISTS gebruikers (
    id              serial PRIMARY KEY,
    email           text NOT NULL UNIQUE,
    wachtwoord_hash text NOT NULL,
    melder_ids      text[] NOT NULL DEFAULT '{}',
    aangemaakt_op   timestamptz NOT NULL DEFAULT now()
)");
$pdo->exec("ALTER TABLE gebruikers ADD COLUMN IF NOT EXISTS is_admin boolean NOT NULL DEFAULT false");