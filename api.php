<?php
// api.php - Ontvangt data van de ESP32 en slaat het op in Neon (PostgreSQL)

// Zet headers zodat we JSON kunnen ontvangen en terugsturen
header('Content-Type: application/json');

// Gegevens van je Neon database
$host = 'ep-odd-cloud-ba9zj1ve-pooler.c-8.eu-central-1.aws.neon.tech'; // Vervang met jouw Neon host
$db   = 'neondb';
$user = 'neondb_owner';
$pass = 'npg_fC6zBKTtVY8s';
$port = '5432';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Databaseverbinding mislukt"]);
    exit;
}

// Controleer of het een POST-request is
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lees de ruwe JSON data uit de body van het request
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    if ($data) {
        // Haal de waardes op uit de JSON (met standaardwaarden ter beveiliging)
        $temperatuur = isset($data['temperatuur']) ? (float)$data['temperatuur'] : 0.0;
        $co2         = isset($data['co2']) ? (int)$data['co2'] : 0;
        $batterij    = isset($data['batterij']) ? (int)$data['batterij'] : 100;

        // Voer de SQL insert uit in Neon
        $stmt = $pdo->prepare("INSERT INTO sensor_data (temperatuur, co2, batterij) VALUES (:temp, :co2, :bat)");
        $success = $stmt->execute([
            'temp' => $temperatuur,
            'co2'  => $co2,
            'bat'  => $batterij
        ]);

        if ($success) {
            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Data succesvol opgeslagen"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Opslaan mislukt"]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Geen geldige JSON ontvangen"]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Alleen POST requests toegestaan"]);
}
?>