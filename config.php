<?php
// ===================================================================
// CONFIGURATION SUPABASE
// ===================================================================

// Données extraites du JWT
if (!defined('SUPABASE_PROJECT_REF')) {
    define('SUPABASE_PROJECT_REF', getenv('SUPABASE_PROJECT_REF') ?: 'ebwnjglojcsloxqirjwo');
}
if (!defined('SUPABASE_ANON_KEY')) {
    define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImVid25qZ2xvamNzbG94cWlyandvIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzYxOTY3NjUsImV4cCI6MjA5MTc3Mjc2NX0.wTUmHrZo8J7-SCGNs2CJqgxWidOLR4-t1OIClHUEB1s');
}
if (!defined('SUPABASE_SERVICE_ROLE_KEY')) {
    define('SUPABASE_SERVICE_ROLE_KEY', getenv('SUPABASE_SERVICE_ROLE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc3NjE5Njc2NSwiZXhwIjoyMDkxNzcyNzY1fQ.K7h3tVasjRBZ6pBZqy7BQY0evwSj-ly9h6uNTamWFug');
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

// Chemin local des dossiers physiques
if (!defined('DOSSIER_BASE_PATH')) {
    define('DOSSIER_BASE_PATH', 'C:\\Users\\dell\\Documents\\permis_de_construire');
}

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

?>
