<?php
require_once '../config.php';

header('Access-Control-Allow-Origin: *');

// Vérifier que DOSSIER_BASE_PATH est configuré
$dossiersBasePath = defined('DOSSIER_BASE_PATH') ? DOSSIER_BASE_PATH : null;
if (!$dossiersBasePath) {
    http_response_code(503);
    echo '<html><head><meta charset="utf-8"><style>body { font-family: Arial, sans-serif; padding: 40px; }</style></head><body>';
    echo '<p style="text-align:center; color:#c0392b; font-size:16px;">❌ Service indisponible</p>';
    echo '<p style="font-size:12px; text-align:center; color:#ccc; margin-top:30px;">La base de données des documents n\'est pas configurée.</p>';
    echo '<p style="font-size:11px; text-align:center; color:#999;">Veuillez contacter l\'administrateur.</p>';
    echo '</body></html>';
    exit;
}

$dossier = isset($_GET['dossier']) ? trim($_GET['dossier']) : '';
$service = isset($_GET['service']) ? strtolower(trim($_GET['service'])) : '';
$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'avis';
$file = isset($_GET['file']) ? $_GET['file'] : '';

$basePath = rtrim(str_replace('/', '\\', $dossiersBasePath), '\\');

// Si fichier spécifié directement, le servir
if (!empty($file)) {
    // Pour les chemins UNC, ne pas utiliser realpath
    if (strpos($file, '\\\\') === 0) {
        // Chemin UNC - vérifier directement
        if (!file_exists($file)) {
            http_response_code(404);
            die('Fichier non trouvé');
        }
    } else {
        // Chemin local - utiliser realpath
        $file = realpath($file);
        if ($file === false) {
            http_response_code(404);
            die('Fichier non trouvé');
        }
    }
    
    // Valider que le fichier est dans le bon répertoire
    if (strpos($file, $basePath) !== 0) {
        http_response_code(403);
        die('Accès refusé');
    }
    
    // Servir le fichier
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif'
    ];
    
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: inline; filename="' . basename($file) . '"');
    
    readfile($file);
    exit;
}

// Sinon, chercher le fichier par dossier
if (empty($dossier)) {
    http_response_code(400);
    echo '<html><body><p style="text-align:center; color:#999; padding:40px;">Numéro de dossier requis</p></body></html>';
    exit;
}

$nomDossier = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $dossier);

// Déterminer le répertoire
if ($type === 'arrete') {
    $targetDir = $basePath . '\\' . $nomDossier . '\\ARRETES';
} else {
    $targetDir = $basePath . '\\' . $nomDossier . '\\AVIS';
}

// Vérifier que le chemin est valide (sécurité)
// Pour les chemins UNC, ne pas utiliser realpath qui ne fonctionne pas bien
if (strpos($targetDir, '\\\\') === 0) {
    // Chemin UNC - vérifier directement
    $realTarget = $targetDir;
    $dirExists = @is_dir($realTarget);
} else {
    // Chemin local - utiliser realpath
    $realTarget = @realpath($targetDir);
    $dirExists = $realTarget !== false && is_dir($realTarget);
}

if (!$dirExists) {
    // Le répertoire n'existe pas - afficher info utile
    echo '<html><head><meta charset="utf-8"><style>body { font-family: Arial, sans-serif; padding: 40px; }</style></head><body>';
    echo '<p style="text-align:center; color:#999; font-size:16px;">📭 Dossier non trouvé</p>';
    echo '<p style="font-size:12px; text-align:center; color:#ccc; margin-top:30px;">Chemin recherché:</p>';
    echo '<p style="font-size:11px; text-align:center; color:#999; font-family:monospace; word-break:break-all; padding:10px; background:#f5f5f5; border-radius:4px;">' . htmlspecialchars($targetDir) . '</p>';
    echo '<p style="font-size:12px; text-align:center; color:#ccc; margin-top:20px;">Suggestions:</p>';
    echo '<p style="font-size:11px; text-align:center; color:#999;">📋 Vérifiez que le dossier <strong>' . htmlspecialchars($nomDossier) . '</strong> existe</p>';
    echo '<p style="font-size:11px; text-align:center; color:#999;">🖥️ Vérifiez l\'accès au serveur NAS</p>';
    echo '<p style="font-size:11px; text-align:center; color:#999;">💾 Assurez-vous que les fichiers sont dans le dossier ' . htmlspecialchars($type === 'arrete' ? 'ARRETES' : 'AVIS') . '</p>';
    echo '</body></html>';
    exit;
}

// Vérifier que le chemin est vraiment dans basePath (sécurité)
if (strpos($realTarget, $basePath) !== 0) {
    http_response_code(403);
    error_log("SECURITY: Path traversal attempt: {$realTarget} not in {$basePath}");
    echo '<html><body><p style="text-align:center; color:#c0392b; padding:40px;">❌ Accès refusé</p></body></html>';
    exit;
}

$files = [];

// Chercher les fichiers
if (is_dir($realTarget)) {
    if (!empty($service) && $type !== 'arrete') {
        // Mapper les services à leurs noms français
        $serviceLabels = [
            'urbanisme' => 'URBANISME',
            'cadastre' => 'CADASTRE',
            'domaine' => 'DOMAINE',
            'hygiene' => "SERVICE D'HYGIENE",
            'mairie' => 'MAIRIE',
            'dreec' => 'DREEC',
            'sapeur' => 'SAPEURS POMPIERS',
            'ageroute' => 'AGEROUTE',
            'snhlm' => 'SNHLM',
            'tourisme' => 'TOURISME'
        ];
        
        $serviceLabel = $serviceLabels[strtolower($service)] ?? strtoupper($service);
        
        // Pour les avis, chercher par service - format 1: service_*.ext et format 2: NOM_SERVICE.ext
        $patterns = [
            // Format avec préfixe: hygiene_avis.pdf
            $realTarget . '\\' . $service . '_*.pdf',
            $realTarget . '\\' . $service . '_*.jpg',
            $realTarget . '\\' . $service . '_*.png',
            $realTarget . '\\' . $service . '_*.jpeg',
            $realTarget . '\\' . strtoupper($service) . '_*.pdf',
            $realTarget . '\\' . strtoupper($service) . '_*.jpg',
            $realTarget . '\\' . strtoupper($service) . '_*.png',
            $realTarget . '\\' . strtoupper($service) . '_*.jpeg',
            // Format sans préfixe: URBANISME.pdf, SERVICE D'HYGIENE.pdf
            $realTarget . '\\' . $serviceLabel . '.pdf',
            $realTarget . '\\' . $serviceLabel . '.jpg',
            $realTarget . '\\' . $serviceLabel . '.png',
            $realTarget . '\\' . $serviceLabel . '.jpeg',
            $realTarget . '\\' . $serviceLabel . '_*.pdf',
            $realTarget . '\\' . $serviceLabel . '_*.jpg',
            $realTarget . '\\' . $serviceLabel . '_*.png',
            $realTarget . '\\' . $serviceLabel . '_*.jpeg'
        ];
    } else {
        // Pour les arrêtés, tous les fichiers
        $patterns = [
            $realTarget . '\\*.pdf',
            $realTarget . '\\*.jpg',
            $realTarget . '\\*.png',
            $realTarget . '\\*.jpeg'
        ];
    }
    
    $allFiles = [];
    foreach ($patterns as $pattern) {
        $scanResult = @glob($pattern);
        if (is_array($scanResult)) {
            $allFiles = array_merge($allFiles, $scanResult);
        }
    }
    
    // Dédupliquer et trier
    $allFiles = array_unique($allFiles);
    sort($allFiles);
    
    foreach ($allFiles as $file) {
        if (is_file($file)) {
            $files[] = [
                'name' => basename($file),
                'path' => $file
            ];
        }
    }
}

// Afficher les fichiers trouvés
if (count($files) === 0) {
    echo '<html><body><p style="text-align:center; color:#999; padding:40px;">📭 Aucun document trouvé</p></body></html>';
} else if (count($files) === 1) {
    // Servir le seul fichier trouvé
    $file = $files[0]['path'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif'
    ];
    
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: inline; filename="' . basename($file) . '"');
    
    readfile($file);
} else {
    // Afficher les fichiers trouvés sous forme de liste
    echo '<html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;padding:20px;}a{display:block;margin:10px 0;padding:10px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#2196f3;}a:hover{background:#e3f2fd;}</style></head><body>';
    echo '<p style="color:#666;">Fichiers trouvés:</p>';
    foreach ($files as $file) {
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $icon = ($fileExt === 'pdf') ? '📄' : '🖼️';
        echo '<a href="view-document.php?file=' . urlencode($file['path']) . '">' . $icon . ' ' . htmlspecialchars($file['name']) . '</a>';
    }
    echo '</body></html>';
}
