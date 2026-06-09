<?php
/**
 * Endpoint API MUCTAT - Supabase
 * URL: http://localhost/api/dossiers
 * 
 * Endpoints:
 * - GET  /api/dossiers.php?action=list
 * - GET  /api/dossiers.php?action=get&id=1
 * - POST /api/dossiers.php?action=create + data
 * - PUT  /api/dossiers.php?action=update&id=1 + data
 * - DELETE /api/dossiers.php?action=delete&id=1
 */

require_once '../config.php';

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, apikey');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Récupérer l'action
$action = $_GET['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'create' : 'list');
$id = $_GET['id'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

logDebug("Request", ['action' => $action, 'method' => $method, 'id' => $id]);

// ===================================================================
// TRAITEMENT DES REQUÊTES
// ===================================================================

switch ($action) {
    case 'list':
        getDossiers();
        break;
    
    case 'get':
        if (!$id) sendJSON(400, 'ID requis');
        getDossier($id);
        break;
    
    case 'create':
        createDossier();
        break;
    
    case 'update':
        if (!$id) sendJSON(400, 'ID requis');
        updateDossier($id);
        break;
    
    case 'delete':
        if (!$id) sendJSON(400, 'ID requis');
        deleteDossier($id);
        break;
    
    case 'delete_user':
        deleteUserAccount();
        break;
    
    case 'stats':
        getStats();
        break;
    
    case 'check':
        checkConnection();
        break;
    
    default:
        sendJSON(400, 'Action inconnue: ' . $action);
}

// ===================================================================
// FONCTIONS CRUD
// ===================================================================

function getDossiers() {
    global $SUPABASE_HEADERS;
    
    $filters = [];
    if (isset($_GET['commune'])) {
        $filters[] = "commune=eq." . urlencode($_GET['commune']);
    }
    if (isset($_GET['situation'])) {
        $filters[] = "situation_dossier=eq." . urlencode($_GET['situation']);
    }
    
    $url = SUPABASE_API_URL . '/dossiers?order=ordre.asc';
    if (!empty($filters)) {
        $url .= '&' . implode('&', $filters);
    }
    
    $response = makeRequest('GET', $url);
    
    if (isset($response['error'])) {
        sendJSON($response['code'], 'Erreur: ' . $response['error']);
    }
    
    sendJSON(200, 'Dossiers récupérés', $response['data'] ?? []);
}

function getDossier($id) {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/dossiers?id=eq.' . (int)$id . '&limit=1';
    $response = makeRequest('GET', $url);
    
    if (isset($response['error'])) {
        sendJSON($response['code'], 'Erreur: ' . $response['error']);
    }
    
    $data = $response['data'] ?? [];
    if (empty($data)) {
        sendJSON(404, 'Dossier non trouvé');
    }
    
    sendJSON(200, 'Dossier récupéré', $data[0]);
}

function createDossier() {
    global $SUPABASE_HEADERS;
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = $_POST;
    }
    
    if (!$data) {
        sendJSON(400, 'Données invalides');
    }
    
    // Valider les champs obligatoires
    if (empty($data['num_dossier']) || empty($data['commune'])) {
        sendJSON(400, 'Champs obligatoires: num_dossier, commune');
    }
    
    // Ajouter les timestamps
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');
    
    $url = SUPABASE_API_URL . '/dossiers';
    $response = makeRequest('POST', $url, $data);
    
    if (isset($response['error'])) {
        sendJSON(400, 'Erreur: ' . $response['error']);
    }
    
    sendJSON(201, 'Dossier créé', $response['data'] ?? $data);
}

function updateDossier($id) {
    global $SUPABASE_HEADERS;
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = $_POST;
    }
    
    if (!$data) {
        sendJSON(400, 'Données invalides');
    }
    
    $data['updated_at'] = date('Y-m-d H:i:s');
    
    $url = SUPABASE_API_URL . '/dossiers?id=eq.' . (int)$id;
    $response = makeRequest('PATCH', $url, $data);
    
    if (isset($response['error'])) {
        sendJSON(400, 'Erreur: ' . $response['error']);
    }
    
    sendJSON(200, 'Dossier mis à jour', $response['data'] ?? $data);
}

function deleteDossier($id) {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/dossiers?id=eq.' . (int)$id;
    $response = makeRequest('DELETE', $url);
    
    if (isset($response['error'])) {
        sendJSON(400, 'Erreur: ' . $response['error']);
    }
    
    sendJSON(200, 'Dossier supprimé');
}

function deleteUserAccount() {
    global $SUPABASE_HEADERS;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['user_id'])) {
        sendJSON(400, 'ID utilisateur requis');
    }
    
    $userId = $input['user_id'];
    
    // Supprimer de la table auth.users (via delete de profiles qui a une contrainte de clé étrangère)
    $url = SUPABASE_API_URL . '/profiles?id=eq.' . urlencode($userId);
    
    $response = makeRequest('DELETE', $url);
    
    if (isset($response['error']) && strpos($response['error'], '404') === false) {
        logDebug("Delete user error", $response);
        sendJSON($response['code'] ?? 500, 'Erreur lors de la suppression: ' . $response['error']);
    }
    
    sendJSON(200, 'Compte utilisateur supprimé avec succès');
}

function getStats() {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/dossiers';
    $response = makeRequest('GET', $url);
    
    if (isset($response['error'])) {
        sendJSON(500, 'Erreur: ' . $response['error']);
    }
    
    $dossiers = $response['data'] ?? [];
    
    $stats = [
        'total' => count($dossiers),
        'en_instruction' => 0,
        'complete' => 0,
        'arrete' => 0,
        'rejete' => 0,
        'incomplete' => 0,
        'total_taxes' => 0,
        'communes' => [],
        'usage' => []
    ];
    
    foreach ($dossiers as $d) {
        $situation = strtoupper($d['situation_dossier'] ?? '');
        
        if (strpos($situation, 'INSTRUCTION') !== false) {
            $stats['en_instruction']++;
        } elseif (strpos($situation, 'COMPLETE') !== false) {
            $stats['complete']++;
        } elseif (strpos($situation, 'ARRETE') !== false) {
            $stats['arrete']++;
        } elseif (strpos($situation, 'REJETE') !== false) {
            $stats['rejete']++;
        } elseif (strpos($situation, 'INCOMPLETE') !== false) {
            $stats['incomplete']++;
        }
        
        $stats['total_taxes'] += (float)($d['taxe_urbanisme'] ?? 0) + 
                                (float)($d['taxe_municipale'] ?? 0) + 
                                (float)($d['autres_taxes'] ?? 0);
        
        $commune = $d['commune'] ?? 'N/A';
        $stats['communes'][$commune] = ($stats['communes'][$commune] ?? 0) + 1;
        
        $usage = $d['usage'] ?? 'N/A';
        $stats['usage'][$usage] = ($stats['usage'][$usage] ?? 0) + 1;
    }
    
    sendJSON(200, 'Statistiques récupérées', $stats);
}

function checkConnection() {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/dossiers?limit=1';
    $response = makeRequest('GET', $url);
    
    if (isset($response['error'])) {
        sendJSON(500, 'Erreur de connexion: ' . $response['error']);
    }
    
    sendJSON(200, 'Connexion OK', [
        'url' => SUPABASE_URL,
        'project' => SUPABASE_PROJECT_REF,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

// ===================================================================
// UTILITAIRES
// ===================================================================

function makeRequest($method, $url, $data = null) {
    global $SUPABASE_HEADERS;
    
    // Log la tentative
    error_log("[".__LINE__."] " . $method . " " . $url);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    
    if ($data && !in_array($method, ['GET', 'DELETE'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    
    // Log tous les détails
    error_log("[".__LINE__."] HTTP $httpCode | Error: $error | Errno: $errno");
    if ($response) {
        error_log("[".__LINE__."] Response: " . substr($response, 0, 200));
    }
    
    if ($error) {
        error_log("[".__LINE__."] CURL ERROR: " . $error);
        return ['error' => $error, 'code' => 0];
    }
    
    if (in_array($httpCode, [200, 201])) {
        $decoded = json_decode($response, true);
        error_log("[".__LINE__."] Success: " . json_encode($decoded));
        return ['data' => $decoded];
    }
    
    error_log("[".__LINE__."] HTTP Error $httpCode: " . substr($response, 0, 500));
    return ['error' => 'HTTP ' . $httpCode . ': ' . $response, 'code' => $httpCode];
}

?>
