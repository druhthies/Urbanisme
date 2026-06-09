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

// Chemin local des dossiers physiques (serveur NAS partagé)
// Détection automatique: local vs Render
if (!defined('DOSSIER_BASE_PATH')) {
    $isRender = (strpos($_SERVER['HTTP_HOST'] ?? '', 'onrender.com') !== false);
    
    if ($isRender) {
        // Sur Render: UNIQUEMENT le NAS via variable d'environnement
        $nasPath = getenv('DOSSIER_BASE_PATH');
        
        if ($nasPath) {
            define('DOSSIER_BASE_PATH', $nasPath);
            define('ENVIRONMENT', 'RENDER-NAS');
            error_log("✅ RENDER NAS MODE - Chemin: " . $nasPath);
        } else {
            // Pas de NAS configuré = pas de fonctionnalité documents
            define('DOSSIER_BASE_PATH', null);
            define('ENVIRONMENT', 'RENDER-NO-NAS');
            error_log("❌ RENDER - DOSSIER_BASE_PATH non configuré. Configurez la variable d'environnement sur Render.");
        }
    } else {
        // En local: accès au NAS Windows
        define('DOSSIER_BASE_PATH', '\\\\Nas-urbanisme\\permis de construire\\PLATEFORME\\PERMIS DE CONSTRUIRE');
        define('ENVIRONMENT', 'LOCAL');
    }
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
