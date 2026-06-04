<?php

// Traiter les erreurs PHP en JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 500,
        'message' => 'Erreur PHP: ' . $errstr,
        'file' => basename($errfile),
        'line' => $errline
    ]);
    exit;
}, E_ALL ^ E_WARNING);

// Gestionnaire d'exceptions non capturées
set_exception_handler(function($exception) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 500,
        'message' => 'Exception non capturée: ' . $exception->getMessage(),
        'file' => basename($exception->getFile()),
        'line' => $exception->getLine()
    ]);
    exit;
});

require_once __DIR__ . '/../config.php';

// Vérifier que les constantes Supabase sont définies
if (!defined('SUPABASE_API_URL')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 500,
        'message' => 'SUPABASE_API_URL non défini'
    ]);
    exit;
}

// ===================================================================
// API MUCTAT - GESTION DES DOSSIERS D'URBANISME
// ===================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, apikey');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Récupérer la méthode et la route
$method = $_SERVER['REQUEST_METHOD'];
$route_param = isset($_GET['route']) ? $_GET['route'] : '';
$method_override = isset($_GET['method']) ? $_GET['method'] : '';

// Si on a un paramètre 'method', utiliser celui-ci pour le routage
if (!empty($method_override)) {
    $method = $method_override;
}

// Traiter la route
$route = !empty($route_param) ? explode('/', trim($route_param, '/')) : [];
$route_name = isset($route[0]) ? $route[0] : '';

logDebug("API call", ['method' => $method, 'route_param' => $route_param, 'route_name' => $route_name]);

// ===================================================================
// ROUTES
// ===================================================================

if ($method === 'GET') {
    
    // GET /api/dossiers - Récupérer tous les dossiers
    if ($route_name === 'dossiers' && !isset($route[1])) {
        getDossiers();
    }
    
    // GET /api/dossiers/{id} - Récupérer un dossier
    elseif ($route_name === 'dossiers' && isset($route[1])) {
        getDossier($route[1]);
    }
    
    // GET /api/users - Récupérer tous les utilisateurs (admin seulement)
    elseif ($route_name === 'users' && !isset($route[1])) {
        getUsers();
    }
    
    // GET /api/users/{id} - Récupérer un utilisateur
    elseif ($route_name === 'users' && isset($route[1])) {
        getUser($route[1]);
    }
    
    // GET /api/history/{dossier_id} - Récupérer l'historique d'un dossier
    elseif ($route_name === 'history' && isset($route[1])) {
        getDossierHistory($route[1]);
    }
    
    // GET /api/avis - Récupérer les avis
    elseif ($route_name === 'avis') {
        if (isset($_GET['dossier_id'])) {
            getAvisServices($_GET['dossier_id']);
        } else {
            getAllAvisServices();
        }
    }
    
    // GET /api/check - Vérifier la connexion
    elseif ($route_name === 'check') {
        checkConnection();
    }

    // GET /api/diagnose - Diagnostiquer les problèmes
    elseif ($route_name === 'diagnose') {
        diagnoseConnection();
    }

    // GET /api/db?table=... - Récupérer le contenu d'une table
    elseif ($route_name === 'db') {
        getDbTable();
    }
    
    // GET /api/communes - Récupérer toutes les communes
    elseif ($route_name === 'communes') {
        getCommunes();
    }
    
    // GET /api/lotissements - Récupérer tous les lotissements
    elseif ($route_name === 'lotissements') {
        getLotissements();
    }
    
    // GET /api/usages - Récupérer tous les usages
    elseif ($route_name === 'usages') {
        getUsages();
    }
    
    else {
        sendJSON(404, 'Route GET non trouvée: ' . $route_name);
    }
}

elseif ($method === 'POST') {
    
    // POST /api/dossiers - Créer un dossier
    if ($route_name === 'dossiers') {
        createDossier();
    }
    
    // POST /api/auth/login - Connexion
    elseif ($route_name === 'auth' && isset($route[1]) && $route[1] === 'login') {
        loginUser();
    }
    
    // POST /api/auth/register - Inscription
    elseif ($route_name === 'auth' && isset($route[1]) && $route[1] === 'register') {
        registerUser();
    }
    
    // POST /api/auth/verify - Vérifier les identifiants dans Supabase
    elseif ($route_name === 'auth' && isset($route[1]) && $route[1] === 'verify') {
        verifyUser();
    }
    
    // POST /api/history - Ajouter une entrée d'historique
    elseif ($route_name === 'history') {
        createHistoryEntry();
    }
    
    // POST /api/avis - Sauvegarder un avis
    elseif ($route_name === 'avis') {
        saveAvisService();
    }
    
    // POST /api/communes - Créer une commune
    elseif ($route_name === 'communes') {
        createCommune();
    }
    
    // POST /api/lotissements - Créer un lotissement
    elseif ($route_name === 'lotissements') {
        createLotissement();
    }
    
    // POST /api/usages - Créer un usage (client-side ajout)
    elseif ($route_name === 'usages') {
        createUsage();
    }
    
    else {
        sendJSON(404, 'Route POST non trouvée: ' . $route_name);
    }
}

elseif ($method === 'PUT') {
    
    // PUT /api/dossiers/{id} - Mettre à jour un dossier
    if ($route_name === 'dossiers' && isset($route[1])) {
        updateDossier($route[1]);
    }
    
    else {
        sendJSON(404, 'Route PUT non trouvée: ' . $route_name);
    }
}

elseif ($method === 'DELETE') {
    
    // DELETE /api/dossiers/{id} - Supprimer un dossier
    if ($route_name === 'dossiers' && isset($route[1])) {
        deleteDossier($route[1]);
    }
    
    // DELETE /api/users/{id} - Supprimer un utilisateur (admin seulement)
    elseif ($route_name === 'users' && isset($route[1])) {
        deleteUser($route[1]);
    }
    
    // DELETE /api/db?table=...&id=... - Supprimer une ligne dans une table autorisée
    elseif ($route_name === 'db') {
        deleteDbRow();
    }
    
    else {
        sendJSON(404, 'Route DELETE non trouvée: ' . $route_name);
    }
}

else {
    sendJSON(405, 'Méthode non autorisée');
}

// ===================================================================
// FONCTIONS DOSSIERS
// ===================================================================

function getDossiers() {
    global $SUPABASE_HEADERS;
    
    // Récupérer les paramètres de filtrage
    $query = [];
    if (isset($_GET['commune'])) {
        $query[] = "commune=eq." . urlencode($_GET['commune']);
    }
    if (isset($_GET['situation'])) {
        $query[] = "situation_dossier=eq." . urlencode($_GET['situation']);
    }
    if (isset($_GET['num_dossier'])) {
        $query[] = "num_dossier=eq." . urlencode($_GET['num_dossier']);
    }
    elseif (isset($_GET['search'])) {
        $search = $_GET['search'];
        $query[] = "or=(requerant.ilike.*" . urlencode($search) . "*,num_dossier.ilike.*" . urlencode($search) . "*)";
    }
    
    $url = SUPABASE_API_URL . '/dossiers?order=ordre.asc';
    if (!empty($query)) {
        $url .= '&' . implode('&', $query);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    logDebug("GET dossiers", ['url' => $url, 'httpCode' => $httpCode]);
    
    if ($error) {
        sendJSON(500, 'Erreur de connexion: ' . $error);
    }
    
    if ($httpCode === 200) {
        sendJSON(200, 'Dossiers récupérés', json_decode($response, true));
    } else {
        sendJSON($httpCode, 'Erreur Supabase: ' . $response);
    }
}

function getDossier($id) {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/dossiers?id=eq.' . (int)$id;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (!empty($data)) {
            sendJSON(200, 'Dossier récupéré', $data[0]);
        } else {
            sendJSON(404, 'Dossier non trouvé');
        }
    } else {
        sendJSON($httpCode, 'Erreur: ' . $response);
    }
}

function sendSupabaseRequest($url, $method, $payload, &$httpCode = null, &$curlError = null) {
    global $SUPABASE_HEADERS;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // debug only

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return $response;
}

function fetchNextDossierOrdre() {
    $url = SUPABASE_API_URL . '/dossiers?select=ordre&order=ordre.desc&limit=1';
    $response = sendSupabaseRequest($url, 'GET', null, $httpCode, $curlError);
    if ($curlError || $httpCode !== 200) {
        return 1;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data) || !isset($data[0]['ordre'])) {
        return 1;
    }

    return intval($data[0]['ordre']) + 1;
}

function createDossier() {
    global $SUPABASE_HEADERS;
    
    // Lire les données JSON
    $input = file_get_contents('php://input');
    logDebug("createDossier received", ['input' => $input]);
    
    $data = json_decode($input, true);
    
    if (!$data) {
        sendJSON(400, 'Données JSON invalides ou vides', ['received' => $input]);
        return;
    }
    
    // Valider les champs obligatoires
    $required = ['num_dossier'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            sendJSON(400, 'Champ obligatoire manquant: ' . $field);
            return;
        }
    }
    
    // Retirer les champs qui ne sont pas dans Supabase (gérés côté client)
    $fieldsToRemove = ['created_at', 'updated_at', 'modified_by', 'modification_history'];
    foreach ($fieldsToRemove as $field) {
        unset($data[$field]);
    }

    // Assurer un ordre unique pour le dossier
    if (!isset($data['ordre']) || $data['ordre'] === '' || $data['ordre'] === null) {
        $data['ordre'] = fetchNextDossierOrdre();
    } else {
        if (!is_numeric($data['ordre'])) {
            sendJSON(400, 'Champ ordre invalide : doit être un nombre entier');
            return;
        }

        $data['ordre'] = intval($data['ordre']);
        $checkUrl = SUPABASE_API_URL . '/dossiers?ordre=eq.' . urlencode($data['ordre']) . '&select=id';
        $checkResponse = sendSupabaseRequest($checkUrl, 'GET', null, $checkCode, $checkError);
        if (!$checkError && $checkCode === 200) {
            $existing = json_decode($checkResponse, true);
            if (is_array($existing) && count($existing) > 0) {
                $data['ordre'] = fetchNextDossierOrdre();
            }
        }
    }

    if (isset($data['situation_dossier']) && strtoupper($data['situation_dossier']) === 'COMPLETE') {
        if (empty($data['archived_at'])) {
            $data['archived_at'] = date('Y-m-d');
        }
    } else {
        if (array_key_exists('archived_at', $data)) {
            $data['archived_at'] = null;
        }
    }
    
    $url = SUPABASE_API_URL . '/dossiers';
    $payload = json_encode($data);
    
    $response = sendSupabaseRequest($url, 'POST', $payload, $httpCode, $curlError);
    logDebug("POST dossier response", ['httpCode' => $httpCode, 'error' => $curlError, 'response' => $response]);

    if ($curlError) {
        sendJSON(500, 'Erreur connexion Supabase: ' . $curlError);
        return;
    }

    if ($httpCode === 400 && strpos($response, "Could not find the 'archived_at' column") !== false) {
        unset($data['archived_at']);
        $payload = json_encode($data);
        $response = sendSupabaseRequest($url, 'POST', $payload, $httpCode, $curlError);
        logDebug("POST dossier retry without archived_at", ['httpCode' => $httpCode, 'error' => $curlError, 'response' => $response]);

        if ($curlError) {
            sendJSON(500, 'Erreur connexion Supabase: ' . $curlError);
            return;
        }
    }
    
    if ($httpCode === 201) {
        $responseData = json_decode($response, true);
        sendJSON(201, 'Dossier créé', $responseData);
    } else {
        // Essayer de décoder la réponse d'erreur Supabase
        $errorData = json_decode($response, true);
        sendJSON($httpCode, 'Erreur Supabase (' . $httpCode . ')', [
            'message' => $errorData['message'] ?? $response,
            'details' => $errorData['details'] ?? null,
            'hint' => $errorData['hint'] ?? null
        ]);
    }
}

function updateDossier($id) {
    global $SUPABASE_HEADERS;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        sendJSON(400, 'Données invalides');
        return;
    }
    
    // Retirer les champs qui ne sont pas dans Supabase (gérés côté client)
    $fieldsToRemove = ['created_at', 'updated_at', 'modified_by', 'modification_history'];
    foreach ($fieldsToRemove as $field) {
        unset($data[$field]);
    }

    if (isset($data['situation_dossier']) && strtoupper($data['situation_dossier']) === 'COMPLETE') {
        if (empty($data['archived_at'])) {
            $data['archived_at'] = date('Y-m-d');
        }
    } else {
        if (array_key_exists('archived_at', $data)) {
            $data['archived_at'] = null;
        }
    }
    
    $url = SUPABASE_API_URL . '/dossiers?id=eq.' . (int)$id;
    $payload = json_encode($data);
    
    $response = sendSupabaseRequest($url, 'PATCH', $payload, $httpCode, $curlError);
    logDebug("PATCH dossier", ['id' => $id, 'httpCode' => $httpCode, 'error' => $curlError, 'response' => $response]);

    if ($curlError) {
        sendJSON(500, 'Erreur connexion Supabase: ' . $curlError);
        return;
    }

    if ($httpCode === 400 && strpos($response, "Could not find the 'archived_at' column") !== false) {
        unset($data['archived_at']);
        $payload = json_encode($data);
        $response = sendSupabaseRequest($url, 'PATCH', $payload, $httpCode, $curlError);
        logDebug("PATCH dossier retry without archived_at", ['id' => $id, 'httpCode' => $httpCode, 'error' => $curlError, 'response' => $response]);

        if ($curlError) {
            sendJSON(500, 'Erreur connexion Supabase: ' . $curlError);
            return;
        }
    }

    if ($httpCode === 200) {
        sendJSON(200, 'Dossier mis à jour', json_decode($response, true));
    } else {
        sendJSON($httpCode, 'Erreur: ' . $response);
    }
}

function deleteDossier($id) {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/dossiers?id=eq.' . (int)$id;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    logDebug("DELETE dossier", ['id' => $id, 'httpCode' => $httpCode]);
    
    if ($httpCode === 204 || $httpCode === 200) {
        sendJSON(200, 'Dossier supprimé');
    } else {
        sendJSON($httpCode, 'Erreur: ' . $response);
    }
}

// ===================================================================
// FONCTIONS STATS
// ===================================================================

function getStats() {
    global $SUPABASE_HEADERS;
    
    // Pour récupérer les stats, on peut utiliser les vues créées dans la BD
    // Ou calculer localement à partir des dossiers
    
    $url = SUPABASE_API_URL . '/dossiers?select=*';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $dossiers = json_decode($response, true);
        
        $stats = [
            'total' => count($dossiers),
            'en_instruction' => 0,
            'complete' => 0,
            'arrete' => 0,
            'rejete' => 0,
            'en_signature' => 0,
            'incomplete' => 0,
            'quittance' => 0,
            'total_taxes' => 0,
            'communes' => [],
            'usage' => []
        ];
        
        foreach ($dossiers as $d) {
            // Compter par situation
            $situation = strtolower($d['situation_dossier'] ?? '');
            switch ($situation) {
                case 'en instruction': $stats['en_instruction']++; break;
                case 'complete': $stats['complete']++; break;
                case 'arrete': $stats['arrete']++; break;
                case 'rejete': $stats['rejete']++; break;
                case 'en signature': $stats['en_signature']++; break;
                case 'incomplete': $stats['incomplete']++; break;
                case 'quittance non payee': $stats['quittance']++; break;
            }
            
            // Taxes
            $stats['total_taxes'] += (float)($d['taxe_urbanisme'] ?? 0) + 
                                    (float)($d['taxe_municipale'] ?? 0) + 
                                    (float)($d['autres_taxes'] ?? 0);
            
            // Communes
            $commune = $d['commune'] ?? 'N/A';
            $stats['communes'][$commune] = ($stats['communes'][$commune] ?? 0) + 1;
            
            // Usage
            $usage = $d['usage'] ?? 'N/A';
            $stats['usage'][$usage] = ($stats['usage'][$usage] ?? 0) + 1;
        }
        
        sendJSON(200, 'Statistiques récupérées', $stats);
    } else {
        sendJSON($httpCode, 'Erreur: ' . $response);
    }
}

// ===================================================================
// FONCTIONS AUTHENTIFICATION
// ===================================================================

function loginUser() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['username']) || empty($data['password'])) {
        sendJSON(400, 'Username et password requis');
    }
    
    // Pour simplifier, on utilise localStorage côté client
    sendJSON(200, 'Login valide - utilisez localStorage côté client');
}

function registerUser() {
    global $SUPABASE_HEADERS;
    
    // Vérifier que seul un admin peut créer des comptes
    $userRole = isset($_SERVER['HTTP_X_USER_ROLE']) ? $_SERVER['HTTP_X_USER_ROLE'] : null;
    if ($userRole !== 'ADMIN') {
        sendJSON(403, 'Accès refusé : seuls les administrateurs peuvent créer des comptes');
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['username']) || empty($data['password']) || empty($data['email'])) {
        sendJSON(400, 'Champs requis: username, password, email, role');
        return;
    }
    
    $userPayload = [
        'id' => 'user_' . time() . '_' . rand(1000, 9999),
        'username' => $data['username'],
        'email' => $data['email'],
        'fullname' => $data['fullname'] ?? $data['username'],
        'role' => $data['role'] ?? 'CONSULTANT',
        'password_hash' => $data['password'] // Stock en base64 depuis le client
    ];
    
    $url = SUPABASE_API_URL . '/users';
    $payload = json_encode($userPayload);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 201) {
        sendJSON(201, 'Compte créé avec succès', json_decode($response, true));
    } else {
        $errorData = json_decode($response, true);
        sendJSON($httpCode, 'Erreur Supabase', [
            'message' => $errorData['message'] ?? $response
        ]);
    }
}

function verifyUser() {
    global $SUPABASE_HEADERS;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['username']) || empty($data['password'])) {
        sendJSON(400, 'Username et password requis');
        return;
    }
    
    // Chercher l'utilisateur dans Supabase
    $url = SUPABASE_API_URL . '/users?username=eq.' . urlencode($data['username']);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        sendJSON(401, 'Identifiants incorrects');
        return;
    }
    
    $users = json_decode($response, true);
    if (empty($users) || !is_array($users)) {
        sendJSON(401, 'Identifiants incorrects');
        return;
    }
    
    $user = $users[0];
    
    // Vérifier le mot de passe (stocké en base64)
    if ($user['password_hash'] !== $data['password']) {
        sendJSON(401, 'Identifiants incorrects');
        return;
    }
    
    // Mot de passe correct - retourner les infos utilisateur
    sendJSON(200, 'Authentification réussie', $user);
}

// ===================================================================
// CONNEXION TEST
// ===================================================================

function checkConnection() {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/dossiers?limit=1';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        sendJSON(500, 'Erreur de connexion: ' . $error);
    }
    
    if ($httpCode === 200) {
        sendJSON(200, 'Connexion Supabase OK', [
            'url' => SUPABASE_URL,
            'project' => SUPABASE_PROJECT_REF
        ]);
    } else {
        sendJSON($httpCode, 'Erreur Supabase', ['response' => $response]);
    }
}

// ===================================================================
// GESTION DES UTILISATEURS
// ===================================================================

function getUsers() {
    global $SUPABASE_HEADERS;
    
    // Vérifier que seul un admin peut lister les utilisateurs
    $userRole = isset($_SERVER['HTTP_X_USER_ROLE']) ? $_SERVER['HTTP_X_USER_ROLE'] : null;
    if ($userRole !== 'ADMIN') {
        sendJSON(403, 'Accès refusé : seuls les administrateurs peuvent voir la liste des utilisateurs');
        return;
    }
    
    $url = SUPABASE_API_URL . '/users?select=id,username,email,fullname,role';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $users = json_decode($response, true);
        sendJSON(200, 'Utilisateurs récupérés', $users);
    } else {
        sendJSON($httpCode, 'Erreur Supabase', ['response' => $response]);
    }
}

function getUser($id) {
    global $SUPABASE_HEADERS;
    
    // Vérifier que seul un admin peut voir les détails d'un utilisateur
    $userRole = isset($_SERVER['HTTP_X_USER_ROLE']) ? $_SERVER['HTTP_X_USER_ROLE'] : null;
    if ($userRole !== 'ADMIN') {
        sendJSON(403, 'Accès refusé : seuls les administrateurs peuvent voir les détails des utilisateurs');
        return;
    }
    
    $url = SUPABASE_API_URL . '/users?id=eq.' . urlencode($id) . '&select=id,username,email,fullname,role';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $users = json_decode($response, true);
        if (!empty($users)) {
            sendJSON(200, 'Utilisateur trouvé', $users[0]);
        } else {
            sendJSON(404, 'Utilisateur non trouvé');
        }
    } else {
        sendJSON($httpCode, 'Erreur Supabase', ['response' => $response]);
    }
}

function deleteUser($id) {
    global $SUPABASE_HEADERS;
    
    // Vérifier que seul un admin peut supprimer un utilisateur
    $userRole = isset($_SERVER['HTTP_X_USER_ROLE']) ? $_SERVER['HTTP_X_USER_ROLE'] : null;
    if ($userRole !== 'ADMIN') {
        sendJSON(403, 'Accès refusé : seuls les administrateurs peuvent supprimer des utilisateurs');
        return;
    }
    
    $url = SUPABASE_API_URL . '/users?id=eq.' . urlencode($id);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        sendJSON(200, 'Utilisateur supprimé avec succès');
    } else {
        sendJSON($httpCode, 'Erreur Supabase', ['response' => $response]);
    }
}

// ===================================================================
// FONCTIONS HISTORIQUE
// ===================================================================

function getDossierHistory($dossierId) {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/dossier_history?dossier_id=eq.' . (int)$dossierId . '&order=changed_at.asc';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $history = json_decode($response, true);
        sendJSON(200, 'Historique récupéré', $history);
    } else {
        sendJSON($httpCode, 'Erreur: ' . $response);
    }
}

function getDbTable() {
    global $SUPABASE_HEADERS;

    $allowedTables = ['dossiers', 'users', 'dossier_history', 'avis_services', 'communes', 'lotissements'];
    // Permettre la lecture de la table usages via l'endpoint db
    if (!in_array('usages', $allowedTables, true)) {
        $allowedTables[] = 'usages';
    }
    $table = isset($_GET['table']) ? trim($_GET['table']) : '';

    if (!$table || !in_array($table, $allowedTables, true)) {
        sendJSON(400, 'Table invalide ou non autorisée');
        return;
    }

    if ($table === 'dossiers') {
        // Récupérer les dossiers avec leurs avis
        $url = SUPABASE_API_URL . '/dossiers?select=*,avis_services(*)';
        $ch = curl_init($url);
        
        if (!$ch) {
            sendJSON(500, 'Erreur d\'initialisation cURL');
            return;
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        
        if ($response === false) {
            $curlError = curl_error($ch);
            curl_close($ch);
            sendJSON(500, 'Erreur cURL: ' . $curlError);
            return;
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                sendJSON(500, 'Erreur de décodage JSON: ' . json_last_error_msg());
                return;
            }
            // Transformer les avis en format compatible avec l'ancien système
            $transformedData = array_map(function($dossier) {
                $avisMap = [];
                if (isset($dossier['avis_services']) && is_array($dossier['avis_services'])) {
                    foreach ($dossier['avis_services'] as $avis) {
                        $avisMap['avis_' . $avis['service_name']] = $avis['avis'];
                    }
                }
                unset($dossier['avis_services']);
                return array_merge($dossier, $avisMap);
            }, $data);
            sendJSON(200, 'Table récupérée', $transformedData);
        } else {
            sendJSON($httpCode, 'Erreur Supabase: ' . $response);
        }
        return;
    }
}

function deleteDbRow() {
    global $SUPABASE_HEADERS;

    $allowedTables = ['dossiers', 'users', 'dossier_history', 'avis_services'];
    $table = isset($_GET['table']) ? trim($_GET['table']) : '';
    $id = isset($_GET['id']) ? trim($_GET['id']) : '';

    if (!$table || !in_array($table, $allowedTables, true) || $id === '') {
        sendJSON(400, 'Table ou ID invalide');
        return;
    }

    $url = SUPABASE_API_URL . '/' . $table . '?id=eq.' . rawurlencode($id);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 204 || $httpCode === 200) {
        sendJSON(200, 'Ligne supprimée');
    } else {
        sendJSON($httpCode, 'Erreur Supabase: ' . $response);
    }
}

// ===================================================================
// COMMUNES
// ===================================================================

function getCommunes() {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/communes?select=*&order=nom.asc';
    $ch = curl_init($url);
    
    if (!$ch) {
        sendJSON(500, 'Erreur d\'initialisation cURL');
        return;
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout de 30 secondes
    
    $response = curl_exec($ch);
    
    if ($response === false) {
        $curlError = curl_error($ch);
        curl_close($ch);
        sendJSON(500, 'Erreur cURL: ' . $curlError);
        return;
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            sendJSON(500, 'Erreur de décodage JSON: ' . json_last_error_msg());
            return;
        }
        sendJSON(200, 'Communes récupérées', $data);
    } else {
        sendJSON($httpCode, 'Erreur Supabase: ' . $response);
    }
}

function createCommune() {
    global $SUPABASE_HEADERS, $SUPABASE_SERVICE_HEADERS;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['nom'])) {
        sendJSON(400, 'Nom de la commune requis');
        return;
    }
    
    $nom = trim($data['nom']);
    
    // Vérifier les doublons (lecture seule avec la clé publique si possible)
    $checkUrl = SUPABASE_API_URL . '/communes?nom=eq.' . rawurlencode($nom) . '&select=id';
    $ch = curl_init($checkUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    $response = curl_exec($ch);
    $checkHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($checkHttpCode !== 200) {
        sendJSON($checkHttpCode, 'Erreur vérification doublon commune: ' . $response);
        return;
    }

    $checkData = json_decode($response, true);
    if (!is_array($checkData)) {
        sendJSON(500, 'Réponse inattendue lors de la vérification de la commune');
        return;
    }

    if (!empty($checkData)) {
        sendJSON(400, 'Cette commune existe déjà', $checkData[0]);
        return;
    }

    // Créer la nouvelle commune
    $url = SUPABASE_API_URL . '/communes';
    $postData = json_encode(['nom' => $nom]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    $headers = $SUPABASE_SERVICE_HEADERS;
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Prefer: return=representation';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201) {
        $result = json_decode($response, true);
        sendJSON(201, 'Commune créée', $result[0] ?? $result);
    } else {
        sendJSON($httpCode, 'Erreur lors de la création: ' . $response);
    }
}

// ===================================================================
// LOTISSEMENTS
// ===================================================================

function getLotissements() {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/lotissements?select=*&order=nom.asc';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        sendJSON(200, 'Lotissements récupérés', $data);
    } else {
        sendJSON($httpCode, 'Erreur Supabase: ' . $response);
    }
}

function createLotissement() {
    global $SUPABASE_HEADERS, $SUPABASE_SERVICE_HEADERS;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['nom'])) {
        sendJSON(400, 'Nom du lotissement requis');
        return;
    }
    
    $nom = trim($data['nom']);
    $commune_id = isset($data['commune_id']) ? $data['commune_id'] : null;
    
    // Vérifier les doublons (lecture seule avec la clé publique si possible)
    $checkUrl = SUPABASE_API_URL . '/lotissements?nom=eq.' . rawurlencode($nom) . '&select=id';
    $ch = curl_init($checkUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    $response = curl_exec($ch);
    $checkHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($checkHttpCode !== 200) {
        sendJSON($checkHttpCode, 'Erreur vérification doublon lotissement: ' . $response);
        return;
    }

    $checkData = json_decode($response, true);
    if (!is_array($checkData)) {
        sendJSON(500, 'Réponse inattendue lors de la vérification du lotissement');
        return;
    }

    if (!empty($checkData)) {
        sendJSON(400, 'Ce lotissement existe déjà', $checkData[0]);
        return;
    }

    // Créer le nouveau lotissement
    $url = SUPABASE_API_URL . '/lotissements';
    $postData = ['nom' => $nom];
    if ($commune_id) {
        $postData['commune_id'] = (int)$commune_id;
    }
    $postData = json_encode($postData);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    $headers = $SUPABASE_SERVICE_HEADERS;
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Prefer: return=representation';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201) {
        $result = json_decode($response, true);
        sendJSON(201, 'Lotissement créé', $result[0] ?? $result);
    } else {
        sendJSON($httpCode, 'Erreur lors de la création: ' . $response);
    }
}

// ===================================================================
// USAGES
// ===================================================================

function getUsages() {
    global $SUPABASE_HEADERS;
    $url = SUPABASE_API_URL . '/usages?select=*&order=nom.asc';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        sendJSON(200, 'Usages récupérés', $data);
    } else {
        sendJSON($httpCode, 'Erreur Supabase: ' . $response);
    }
}

function createUsage() {
    global $SUPABASE_HEADERS, $SUPABASE_SERVICE_HEADERS;
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['nom'])) {
        sendJSON(400, 'Nom de l\'usage requis');
        return;
    }
    $nom = trim($data['nom']);
    // Vérifier les doublons
    $checkUrl = SUPABASE_API_URL . '/usages?nom=eq.' . rawurlencode($nom) . '&select=id';
    $ch = curl_init($checkUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    $response = curl_exec($ch);
    $checkHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($checkHttpCode !== 200) {
        sendJSON($checkHttpCode, 'Erreur vérification doublon usage: ' . $response);
        return;
    }

    $checkData = json_decode($response, true);
    if (!is_array($checkData)) {
        sendJSON(500, 'Réponse inattendue lors de la vérification de l\'usage');
        return;
    }

    if (!empty($checkData)) {
        sendJSON(400, 'Cet usage existe déjà', $checkData[0]);
        return;
    }

    // Créer le nouvel usage
    $url = SUPABASE_API_URL . '/usages';
    $postData = json_encode(['nom' => $nom]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    $headers = $SUPABASE_SERVICE_HEADERS;
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Prefer: return=representation';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201) {
        $result = json_decode($response, true);
        sendJSON(201, 'Usage créé', $result[0] ?? $result);
    } else {
        sendJSON($httpCode, 'Erreur lors de la création: ' . $response);
    }
}

function createHistoryEntry() {
    global $SUPABASE_HEADERS;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['dossier_id']) || !isset($data['action'])) {
        sendJSON(400, 'Données invalides: dossier_id et action requis');
        return;
    }
    
    // Récupérer l'utilisateur depuis les headers
    $userId = isset($_SERVER['HTTP_X_USER_ID']) ? $_SERVER['HTTP_X_USER_ID'] : null;
    
    $historyEntry = [
        'dossier_id' => (int)$data['dossier_id'],
        'user_id' => $userId,
        'action' => $data['action'],
        'field_name' => $data['field_name'] ?? null,
        'old_value' => $data['old_value'] ?? null,
        'new_value' => $data['new_value'] ?? null,
        'changed_at' => date('Y-m-d H:i:s')
    ];
    
    $url = SUPABASE_API_URL . '/dossier_history';
    $payload = json_encode($historyEntry);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 201) {
        sendJSON(201, 'Entrée d\'historique créée', json_decode($response, true));
    } else {
        sendJSON($httpCode, 'Erreur: ' . $response);
    }
}

// ===================================================================
// GESTION DES AVIS DES SERVICES
// ===================================================================

function getAvisServices($dossierId) {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/avis_services?dossier_id=eq.' . (int)$dossierId;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $avis = json_decode($response, true);
        sendJSON(200, 'Avis récupérés', $avis);
    } else {
        sendJSON($httpCode, 'Erreur récupération avis: ' . $response);
    }
}

function getAllAvisServices() {
    global $SUPABASE_HEADERS;
    
    $url = SUPABASE_API_URL . '/avis_services?select=*&order=created_at.desc';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $avis = json_decode($response, true);
        sendJSON(200, 'Tous les avis récupérés', $avis);
    } else {
        sendJSON($httpCode, 'Erreur récupération avis: ' . $response);
    }
}

function saveAvisService() {
    global $SUPABASE_HEADERS;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['dossier_id']) || !isset($data['service_name']) || !isset($data['avis'])) {
        sendJSON(400, 'Données invalides: dossier_id, service_name et avis requis');
        return;
    }
    
    $observation = isset($data['observation']) ? trim($data['observation']) : null;
    
    // Vérifier si l'avis existe déjà pour ce dossier et service
    $checkUrl = SUPABASE_API_URL . '/avis_services?dossier_id=eq.' . (int)$data['dossier_id'] . '&service_name=eq.' . rawurlencode($data['service_name']) . "&select=*";
    
    $ch = curl_init($checkUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
    
    $checkResponse = curl_exec($ch);
    $checkHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($checkHttpCode === 200) {
        $existingAvis = json_decode($checkResponse, true);
        
        if (!empty($existingAvis)) {
            // Mettre à jour l'avis existant
            $avisId = $existingAvis[0]['id'];
            $url = SUPABASE_API_URL . '/avis_services?id=eq.' . $avisId;
            
            $updateData = [
                'avis' => $data['avis'],
                'date_avis' => date('Y-m-d H:i:s'),
                'observation' => $observation
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 204) {
                sendJSON(200, 'Avis mis à jour avec succès');
            } else {
                sendJSON($httpCode, 'Erreur mise à jour avis: ' . $response);
            }
        } else {
            // Créer un nouvel avis
            $newAvis = [
                'dossier_id' => (int)$data['dossier_id'],
                'service_name' => $data['service_name'],
                'avis' => $data['avis'],
                'observation' => $observation,
                'date_avis' => date('Y-m-d H:i:s')
            ];
            
            $url = SUPABASE_API_URL . '/avis_services';
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($newAvis));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 201) {
                sendJSON(201, 'Avis créé avec succès');
            } else {
                sendJSON($httpCode, 'Erreur création avis: ' . $response);
            }
        }
    } else {
        sendJSON($checkHttpCode, 'Erreur vérification avis existant: ' . $checkResponse);
    }
}

?>