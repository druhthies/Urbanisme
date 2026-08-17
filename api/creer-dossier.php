<?php
// ===================================================================
// CONFIGURATION SUPABASE
// ===================================================================

// Données extraites du JWT
if (!defined('SUPABASE_PROJECT_REF')) {
    define('SUPABASE_PROJECT_REF', getenv('SUPABASE_PROJECT_REF') ?: 'rlyoijuwkwnzrhbglbzj');
}
if (!defined('SUPABASE_ANON_KEY')) {
    define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InJseW9panV3a3duenJoYmdsYnpqIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODA1MDgyMDIsImV4cCI6MjA5NjA4NDIwMn0.TusQxKrCP_w_4pcSPu9lrOIp35RDre8S2NLzWU6Rk3E');
}
if (!defined('SUPABASE_SERVICE_ROLE_KEY')) {
    define('SUPABASE_SERVICE_ROLE_KEY', getenv('SUPABASE_SERVICE_ROLE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InJseW9panV3a3duenJoYmdsYnpqIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4MDUwODIwMiwiZXhwIjoyMDk2MDg0MjAyfQ.E4dPJsltByWPudLAhQxpmNZnU-fGoHkjBNYrJVJrpGg');
}

// URL de base Supabase
if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', 'https://' . SUPABASE_PROJECT_REF . '.supabase.co');
}
if (!defined('SUPABASE_API_URL')) {
    define('SUPABASE_API_URL', SUPABASE_URL . '/rest/v1');
}

// Headers pour les requêtes (backend utilisera la clé service role par défaut)
$SUPABASE_HEADERS = [
    'apikey: ' . SUPABASE_SERVICE_ROLE_KEY,
    'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
    'Content-Type: application/json',
    'Prefer: return=representation'
];

// Clé service role en alias si besoin explicite
$SUPABASE_SERVICE_HEADERS = $SUPABASE_HEADERS;

// Debug mode
define('DEBUG', true);

// ===================================================================
// DÉTECTION ENVIRONNEMENT & CHEMINS DOSSIERS
// ===================================================================

// Déterminer si on est sur le NAS en vérifiant la présence du chemin réel
// ✅ Chemin Synology réel: /volume1/PERMIS DE CONSTRUIRE/PLATEFORME/PERMIS DE CONSTRUIRE
$nasPath = '/volume1/PERMIS DE CONSTRUIRE/PLATEFORME/PERMIS DE CONSTRUIRE';
$isOnNAS = file_exists($nasPath);

// Chemin local des dossiers physiques
if (!defined('DOSSIER_BASE_PATH')) {
    if ($isOnNAS) {
        // Sur le NAS: utiliser le chemin Synology réel
        define('DOSSIER_BASE_PATH', $nasPath);
    } else {
        // Autre contexte (serveur déploié): utiliser variable d'env
        define('DOSSIER_BASE_PATH', getenv('DOSSIER_BASE_PATH') ?: '');
    }
}

// Flag pour indiquer si le NAS est accessible
define('IS_ON_NAS', $isOnNAS);

// ===================================================================
// FONCTIONS UTILITAIRES
// ===================================================================

function logDebug($message, $data = null) {
    if (DEBUG) {
        $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
        if ($data) {
            $log .= " - " . json_encode($data);
        }
        error_log($log); // Utiliser le système de logs PHP
    }
}

function sendJSON($status, $message, $data = null) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// ===================================================================
// ENDPOINT API - TRAITEMENT DES REQUÊTES
// ===================================================================

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Traiter les requêtes OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Seules les requêtes POST sont acceptées
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendJSON(405, 'Méthode non autorisée');
}

// Récupérer et décoder le corps JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['chemin'])) {
    http_response_code(400);
    sendJSON(400, 'Chemin requis dans le corps JSON');
}

$chemin = $input['chemin'];

// Normaliser les chemins (utiliser des slashes pour Linux/Synology)
$chemin = str_replace('\\', '/', trim($chemin));
$basePath = str_replace('\\', '/', DOSSIER_BASE_PATH);

// Valider que le chemin est dans le répertoire autorisé
if (strpos($chemin, $basePath) !== 0) {
    http_response_code(403);
    sendJSON(403, 'Chemin non autorisé - dépassement du répertoire de base', [
        'chemin' => $chemin,
        'basePath' => $basePath
    ]);
}

// Vérifier si le répertoire existe déjà
if (file_exists($chemin)) {
    logDebug('Dossier déjà existant', ['chemin' => $chemin]);
    http_response_code(200);
    sendJSON(200, 'Le dossier existe déjà', ['chemin' => $chemin]);
}

// Vérifier que le chemin de base est accessible avant de créer
if (!is_dir($basePath)) {
    http_response_code(503);
    sendJSON(503, 'NAS non accessible - chemin de base impossible', [
        'basePath' => $basePath,
        'isOnNAS' => IS_ON_NAS,
        'pathExists' => file_exists($basePath)
    ]);
}

// 🔥 DIAGNOSTIC: Vérifier les permissions sur le chemin de base
$isWritable = is_writable($basePath);
logDebug('Diagnostic permissions', [
    'basePath' => $basePath,
    'is_dir' => is_dir($basePath),
    'is_readable' => is_readable($basePath),
    'is_writable' => $isWritable
]);

if (!$isWritable) {
    http_response_code(403);
    sendJSON(403, 'Permissions insuffisantes - NAS non writable', [
        'basePath' => $basePath,
        'isWritable' => $isWritable,
        'isOnNAS' => IS_ON_NAS
    ]);
}

// Créer le répertoire de manière récursive
logDebug('Tentative de création de dossier', [
    'chemin' => $chemin,
    'basePath' => $basePath,
    'isOnNAS' => IS_ON_NAS
]);

if (mkdir($chemin, 0777, true)) {
    logDebug('Dossier créé avec succès', ['chemin' => $chemin]);
    http_response_code(201);
    sendJSON(201, 'Dossier créé avec succès', ['chemin' => $chemin]);
} else {
    $lastError = error_get_last();
    
    // Diagnostic: vérifier le parent directory
    $parentPath = dirname($chemin);
    $parentWritable = is_writable($parentPath);
    $parentExists = is_dir($parentPath);
    
    logDebug('mkdir échoué - diagnostic', [
        'chemin' => $chemin,
        'parentPath' => $parentPath,
        'parentExists' => $parentExists,
        'parentWritable' => $parentWritable,
        'error' => $lastError['message'] ?? 'Unknown'
    ]);
    
    http_response_code(500);
    sendJSON(500, 'Erreur lors de la création du dossier', [
        'chemin' => $chemin,
        'error' => $lastError['message'] ?? 'Erreur mkdir inconnue',
        'parentPath' => $parentPath,
        'parentExists' => $parentExists,
        'parentWritable' => $parentWritable,
        'basePathWritable' => is_writable($basePath),
        'isOnNAS' => IS_ON_NAS
    ]);
}

?>