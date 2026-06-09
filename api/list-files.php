<?php
require_once '../config.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$numDossier = isset($_GET['dossier']) ? trim($_GET['dossier']) : '';
$service = isset($_GET['service']) ? strtolower(trim($_GET['service'])) : '';
$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'avis';

if ($numDossier === '') {
    http_response_code(400);
    echo json_encode(['status' => 400, 'message' => 'Numéro de dossier requis']);
    exit;
}

$nomDossier = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $numDossier);
$basePath = str_replace('/', '\\', DOSSIER_BASE_PATH);

// Déterminer le répertoire selon le type
if ($type === 'arrete') {
    $targetDir = $basePath . '\\' . $nomDossier . '\\ARRETES';
} else {
    $targetDir = $basePath . '\\' . $nomDossier . '\\AVIS';
}

// Vérifier que le chemin est valide
if (strpos($targetDir, $basePath) !== 0) {
    http_response_code(403);
    echo json_encode(['status' => 403, 'message' => 'Accès refusé']);
    exit;
}

$files = [];

// Chercher les fichiers
if (is_dir($targetDir)) {
    if (!empty($service) && $type !== 'arrete') {
        // Mapper les services à leurs noms français
        $serviceLabels = [
            'urbanisme' => 'URBANISME',
            'cadastre' => 'CADASTRE',
            'domaine' => 'DOMAINE',
            'hygiene' => "SERVICE D'HYGIENE",
            'mairie' => 'MAIRIE',
            'dreec' => 'DREEC',
            'sapeur' => 'SAPEUR',
            'ageroute' => 'AGEROUTE',
            'snhlm' => 'SNHLM',
            'tourisme' => 'TOURISME'
        ];
        
        $serviceLabel = $serviceLabels[strtolower($service)] ?? strtoupper($service);
        
        // Pour les avis, chercher par service - format 1: service_*.ext et format 2: NOM_SERVICE.ext
        $patterns = [
            // Format avec préfixe: hygiene_avis.pdf
            $targetDir . '\\' . $service . '_*.pdf',
            $targetDir . '\\' . $service . '_*.jpg',
            $targetDir . '\\' . $service . '_*.png',
            $targetDir . '\\' . $service . '_*.jpeg',
            $targetDir . '\\' . strtoupper($service) . '_*.pdf',
            $targetDir . '\\' . strtoupper($service) . '_*.jpg',
            $targetDir . '\\' . strtoupper($service) . '_*.png',
            $targetDir . '\\' . strtoupper($service) . '_*.jpeg',
            // Format sans préfixe: URBANISME.pdf, SERVICE D'HYGIENE.pdf
            $targetDir . '\\' . $serviceLabel . '.pdf',
            $targetDir . '\\' . $serviceLabel . '.jpg',
            $targetDir . '\\' . $serviceLabel . '.png',
            $targetDir . '\\' . $serviceLabel . '.jpeg',
            $targetDir . '\\' . $serviceLabel . '_*.pdf',
            $targetDir . '\\' . $serviceLabel . '_*.jpg',
            $targetDir . '\\' . $serviceLabel . '_*.png',
            $targetDir . '\\' . $serviceLabel . '_*.jpeg'
        ];
    } else if ($type === 'arrete') {
        // Pour les arrêtés, tous les fichiers
        $patterns = [
            $targetDir . '\\*.pdf',
            $targetDir . '\\*.jpg',
            $targetDir . '\\*.png',
            $targetDir . '\\*.jpeg'
        ];
    } else {
        $patterns = [
            $targetDir . '\\*.pdf',
            $targetDir . '\\*.jpg',
            $targetDir . '\\*.png',
            $targetDir . '\\*.jpeg'
        ];
    }
    
    $allFiles = [];
    foreach ($patterns as $pattern) {
        $scanResult = glob($pattern);
        if (is_array($scanResult)) {
            $allFiles = array_merge($allFiles, $scanResult);
        }
    }
    
    // Dédupliquer
    $allFiles = array_unique($allFiles);
    
    foreach ($allFiles as $file) {
        if (is_file($file)) {
            $files[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file)
            ];
        }
    }
}

echo json_encode([
    'status' => 200,
    'dossier' => $numDossier,
    'service' => $service,
    'type' => $type,
    'basedir' => $targetDir,
    'files' => $files
]);
