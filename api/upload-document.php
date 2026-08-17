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

$documents = $_FILES['document'] ?? null;
if (!$documents || !is_array($documents['name']) || count($documents['name']) === 0) {
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

// ✅ CORRECTION: utiliser les slashes Linux (/)
$basePath = DOSSIER_BASE_PATH; // ex: /volume1/PERMIS DE CONSTRUIRE
$targetDir = $basePath . '/' . $nomDossier;

// Vérifier que le chemin de base est accessible
if (!is_dir($basePath)) {
    http_response_code(503);
    echo json_encode([
        'status' => 503,
        'message' => 'NAS non accessible',
        'debug' => [
            'basePath' => $basePath,
            'isOnNAS' => IS_ON_NAS,
            'pathExists' => file_exists($basePath)
        ]
    ]);
    exit;
}

// ✅ CORRECTION: slashes Linux pour les sous-dossiers
if ($type === 'avis') {
    $targetDir .= '/AVIS';
} elseif ($type === 'arrete') {
    $targetDir .= '/ARRETES';
} elseif ($type === 'piece_ecrit') {
    $targetDir .= '/PIECE_ECRIT';
} elseif ($type === 'bis') {
    $targetDir .= '/BIS';
}

// Sécurité: vérifier que le chemin reste dans le dossier de base
if (strpos($targetDir, $basePath) !== 0) {
    http_response_code(403);
    echo json_encode(['status' => 403, 'message' => 'Chemin non autorisé']);
    exit;
}

if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    http_response_code(500);
    echo json_encode([
        'status' => 500,
        'message' => 'Impossible de créer le dossier de destination',
        'debug' => [
            'targetDir' => $targetDir,
            'isOnNAS' => IS_ON_NAS
        ]
    ]);
    exit;
}

$uploadedFiles = [];
$errors = [];
$fileCount = count($documents['name']);

for ($i = 0; $i < $fileCount; $i++) {
    if (!isset($documents['error'][$i]) || $documents['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = ['index' => $i, 'name' => $documents['name'][$i] ?? '', 'error' => $documents['error'][$i] ?? 'unknown'];
        continue;
    }

    $originalName = basename($documents['name'][$i]);
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $uniqueName = $baseName . '_' . time() . '_' . $i . (!empty($extension) ? '.' . $extension : '');
    $targetFile = $targetDir . '/' . $uniqueName;

    if (!move_uploaded_file($documents['tmp_name'][$i], $targetFile)) {
        $errors[] = ['index' => $i, 'name' => $originalName, 'error' => 'unable_to_move'];
        continue;
    }

    $uploadedFiles[] = [
        'original_name' => $originalName,
        'stored_name' => $uniqueName,
        'path' => $targetFile,
        'type' => $type
    ];
}

if (empty($uploadedFiles)) {
    http_response_code(500);
    echo json_encode(['status' => 500, 'message' => 'Aucun fichier n\'a pu être déposé', 'errors' => $errors]);
    exit;
}

$response = [
    'status' => 200,
    'message' => count($uploadedFiles) === 1 ? 'Fichier déposé avec succès' : count($uploadedFiles) . ' fichiers déposés avec succès',
    'uploaded_files' => $uploadedFiles,
    'errors' => $errors,
    'type' => $type,
    'nom_dossier' => $nomDossier
];

echo json_encode($response);
exit;

if ($numDossier === '') {
    http_response_code(400);
    echo json_encode(['status' => 400, 'message' => 'Le numéro de dossier est requis']);
    exit;
}

$nomDossier = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $numDossier);

// ✅ CORRECTION: utiliser les slashes Linux (/)
$basePath = DOSSIER_BASE_PATH; // ex: /volume1/PERMIS DE CONSTRUIRE
$targetDir = $basePath . '/' . $nomDossier;

// Vérifier que le chemin de base est accessible
if (!is_dir($basePath)) {
    http_response_code(503);
    echo json_encode([
        'status' => 503,
        'message' => 'NAS non accessible',
        'debug' => [
            'basePath' => $basePath,
            'isOnNAS' => IS_ON_NAS,
            'pathExists' => file_exists($basePath)
        ]
    ]);
    exit;
}

// ✅ CORRECTION: slashes Linux pour les sous-dossiers
if ($type === 'avis') {
    $targetDir .= '/AVIS';
} elseif ($type === 'arrete') {
    $targetDir .= '/ARRETES';
} elseif ($type === 'piece_ecrit') {
    $targetDir .= '/PIECE_ECRIT';
} elseif ($type === 'bis') {
    $targetDir .= '/BIS';
}

// Sécurité: vérifier que le chemin reste dans le dossier de base
if (strpos($targetDir, $basePath) !== 0) {
    http_response_code(403);
    echo json_encode(['status' => 403, 'message' => 'Chemin non autorisé']);
    exit;
}

if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    http_response_code(500);
    echo json_encode([
        'status' => 500,
        'message' => 'Impossible de créer le dossier de destination',
        'debug' => [
            'targetDir' => $targetDir,
            'isOnNAS' => IS_ON_NAS
        ]
    ]);
    exit;
}

$originalName = basename($_FILES['document']['name']);
$extension = pathinfo($originalName, PATHINFO_EXTENSION);
$baseName = pathinfo($originalName, PATHINFO_FILENAME);
$uniqueName = $baseName . '_' . time() . (!empty($extension) ? '.' . $extension : '');

// ✅ CORRECTION: slash Linux pour le fichier final
$targetFile = $targetDir . '/' . $uniqueName;

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