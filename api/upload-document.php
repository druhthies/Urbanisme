<?php
require_once '../config.php';

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

if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 400, 'message' => 'Aucun fichier valide envoyé']);
    exit;
}

$numDossier = isset($_POST['num_dossier']) ? trim($_POST['num_dossier']) : '';
$type = isset($_POST['type']) ? strtolower(trim($_POST['type'])) : 'autre';

if ($numDossier === '') {
    http_response_code(400);
    echo json_encode(['status' => 400, 'message' => 'Le numéro de dossier est requis']);
    exit;
}

$nomDossier = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $numDossier);
$basePath = str_replace('/', '\\', DOSSIER_BASE_PATH);
$targetDir = $basePath . '\\' . $nomDossier;

if ($type === 'avis') {
    $targetDir .= '\\AVIS';
} elseif ($type === 'arrete') {
    $targetDir .= '\\ARRETES';
}

if (strpos($targetDir, $basePath) !== 0) {
    http_response_code(403);
    echo json_encode(['status' => 403, 'message' => 'Chemin non autorisé']);
    exit;
}

if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
    http_response_code(500);
    echo json_encode(['status' => 500, 'message' => 'Impossible de créer le dossier de destination']);
    exit;
}

$originalName = basename($_FILES['document']['name']);
$extension = pathinfo($originalName, PATHINFO_EXTENSION);
$baseName = pathinfo($originalName, PATHINFO_FILENAME);
$uniqueName = $baseName . '_' . time() . (!empty($extension) ? '.' . $extension : '');
$targetFile = $targetDir . '\\' . $uniqueName;

if (!move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
    http_response_code(500);
    echo json_encode(['status' => 500, 'message' => 'Erreur lors du dépôt du fichier']);
    exit;
}

echo json_encode([
    'status' => 200,
    'message' => 'Fichier déposé avec succès',
    'chemin' => $targetFile,
    'type' => $type,
    'nom_dossier' => $nomDossier
]);
