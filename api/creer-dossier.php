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

// Vérifier que DOSSIER_BASE_PATH est configuré et accessible
$basePath = defined('DOSSIER_BASE_PATH') ? DOSSIER_BASE_PATH : false;

if (!$basePath) {
    http_response_code(503);
    echo json_encode([
        'status' => 503, 
        'message' => 'Fonctionnalité de création de dossier désactivée',
        'environment' => defined('ENVIRONMENT') ? ENVIRONMENT : 'UNKNOWN'
    ]);
    exit;
}

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

// Normaliser les séparateurs et valider le chemin
$chemin = str_replace('/', '\\', trim($chemin));
$basePath = str_replace('/', '\\', $basePath);

// Valider le chemin (doit être dans le dossier NAS autorisé)
if (strpos($chemin, $basePath) !== 0) {
    http_response_code(403);
    echo json_encode(['status' => 403, 'message' => 'Chemin non autorisé']);
    exit;
}

// Créer le dossier de manière récursive
if (!file_exists($chemin)) {
    logDebug('Tentative de création de dossier', $chemin);
    if (mkdir($chemin, 0777, true)) {
        logDebug('Dossier créé avec succès', $chemin);
        echo json_encode([
            'status' => 200,
            'message' => 'Dossier créé avec succès',
            'chemin' => $chemin
        ]);
    } else {
        $lastError = error_get_last();
        logDebug('Erreur mkdir', $lastError);
        http_response_code(500);
        echo json_encode([
            'status' => 500,
            'message' => 'Erreur lors de la création du dossier',
            'chemin' => $chemin,
            'error' => $lastError
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