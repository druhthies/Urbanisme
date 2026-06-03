<?php
/**
 * Endpoint API pour créer des dossiers physiques
 * URL: http://localhost/api/creer-dossier.php
 * Méthode: POST
 * Body: { "chemin": "C:\\Users\\dell\\Documents\\permis_de_construire\\dossier123" }
 */

require_once '../config.php';

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 405, 'message' => 'Méthode non autorisée']);
    exit;
}

// Récupérer les données JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['chemin'])) {
    http_response_code(400);
    echo json_encode(['status' => 400, 'message' => 'Chemin requis']);
    exit;
}

$chemin = $input['chemin'];

// Valider le chemin (doit être dans le dossier Documents de dell)
$basePath = 'C:\\Users\\dell\\Documents\\permis_de_construire';
if (strpos($chemin, $basePath) !== 0) {
    http_response_code(403);
    echo json_encode(['status' => 403, 'message' => 'Chemin non autorisé']);
    exit;
}

// Nettoyer et valider le chemin
$chemin = str_replace('/', '\\', $chemin); // Normaliser les séparateurs

// Créer le dossier de manière récursive
if (!file_exists($chemin)) {
    if (mkdir($chemin, 0777, true)) {
        echo json_encode([
            'status' => 200,
            'message' => 'Dossier créé avec succès',
            'chemin' => $chemin
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => 500,
            'message' => 'Erreur lors de la création du dossier',
            'chemin' => $chemin
        ]);
    }
} else {
    echo json_encode([
        'status' => 200,
        'message' => 'Le dossier existe déjà',
        'chemin' => $chemin
    ]);
}
?>