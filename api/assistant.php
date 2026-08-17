<?php
// ===================================================================
// ASSISTANT IA - Agents/instructeurs MUCTAT - Division Régionale de Thiès
// Utilise l'API Groq (gratuite) avec function calling complet sur Supabase
// Aligné sur le schéma réel (schema.sql) et les conventions de admin.html / api/index.php :
//  - num_dossier auto-généré (YYYYMMDD-NNN) si non fourni, comme enregistrerDossier()
//  - num_parcelle obligatoire, ordre auto-incrémenté
//  - type_demande : CONSTRUCTION, EXTENSION, TRANSFORMATION, SURELEVATION,
//    TRANSFORMATION_SURELEVATION, RENOUVELLEMENT (+ champs spécifiques par type)
//  - EXTENSION : superficie_batie = superficie_existante + superficie_extension
//  - COMPLETE : refusé si les avis de service ne sont pas tous Favorable
//  - archivage automatique si situation_dossier=COMPLETE et retrait=OUI
//  - avis_services : service_name normalisé, upsert, enum avis
//  - journal dossier_history sur chaque écriture
// ===================================================================

require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Role, X-User-Id');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(405, 'Méthode non autorisée');
}

if (!defined('GROQ_API_KEY')) {
    define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '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');
}

if (GROQ_API_KEY === '') {
    sendJSON(500, "Clé GROQ_API_KEY non configurée. Ajoute-la dans les variables d'environnement du serveur (clé gratuite sur console.groq.com).");
}

// ===================================================================
// UTILITAIRES RÉSEAU
// ===================================================================

if (!function_exists('configureCurlDefaults')) {
    function configureCurlDefaults($ch) {
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 60);
    }
}

if (!function_exists('curlExecWithRetry')) {
    function curlExecWithRetry($ch, $maxRetries = 2) {
        $attempt = 0;
        $response = null;
        while ($attempt <= $maxRetries) {
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            if ($errno === 0) {
                return $response;
            }
            $transient = in_array($errno, [
                CURLE_COULDNT_RESOLVE_HOST,
                CURLE_COULDNT_CONNECT,
                CURLE_OPERATION_TIMEDOUT,
                CURLE_SEND_ERROR,
                CURLE_RECV_ERROR,
            ], true);
            $attempt++;
            if (!$transient || $attempt > $maxRetries) break;
            usleep(300000);
        }
        return $response;
    }
}

// ===================================================================
// ENTRÉE + VALIDATION
// ===================================================================

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if ($rawInput !== '' && json_last_error() !== JSON_ERROR_NONE) {
    sendJSON(400, 'JSON invalide dans la requête');
}

$userMessage = trim($input['message'] ?? '');
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if ($userMessage === '') {
    sendJSON(400, 'Message vide');
}
if (mb_strlen($userMessage) > 4000) {
    sendJSON(400, 'Message trop long (4000 caractères max)');
}

if (count($history) > 10) {
    $history = array_slice($history, -10);
}
$cleanHistory = [];
foreach ($history as $m) {
    if (isset($m['role'], $m['content']) && in_array($m['role'], ['user', 'assistant'], true)) {
        $cleanHistory[] = ['role' => $m['role'], 'content' => (string) $m['content']];
    }
}

$userRole = isset($_SERVER['HTTP_X_USER_ROLE']) ? substr(trim((string) $_SERVER['HTTP_X_USER_ROLE']), 0, 50) : '';
$userId = isset($_SERVER['HTTP_X_USER_ID']) ? substr(trim((string) $_SERVER['HTTP_X_USER_ID']), 0, 50) : null;
if ($userId === '') $userId = null;

// Ces listes reflètent l'état réel du formulaire (admin.html), pas seulement schema.sql,
// car le formulaire a évolué depuis (EN ATTENTE, EXTENSION, RENOUVELLEMENT, etc.)
$STATUTS_VALIDES = [
    'EN INSTRUCTION', 'EN ATTENTE', 'COMPLETE', 'ARRETE', 'REJETE', 'INCOMPLETE', 'QUITTANCE NON PAYEE', 'EN SIGNATURE',
];
$TYPES_DEMANDE_VALIDES = [
    'CONSTRUCTION', 'EXTENSION', 'TRANSFORMATION', 'SURELEVATION', 'TRANSFORMATION_SURELEVATION', 'RENOUVELLEMENT',
];
$AVIS_VALIDES = ['Favorable', 'Defavorable', 'En attente', 'N/A'];
$SERVICES_CATALOGUE = ['urbanisme', 'cadastre', 'domaines', 'hygiene', 'mairie', 'sapeurspompiers', 'ageroute', 'environnement', 'snhlm', 'tourisme'];

$systemPrompt = "Tu es l'assistant IA interne de la plateforme \"Urbanisme\" du MUCTAT - Division Régionale de Thiès (Sénégal), qui gère les dossiers de permis de construire.\n"
    . "Tu t'adresses à des AGENTS INSTRUCTEURS (usage interne, back-office), jamais directement à des citoyens.\n\n"
    . "Tu as un accès complet à la base de données via les outils fournis : lecture, création, modification, changement de statut, gestion des avis de service, archivage, restauration et suppression de dossiers, statistiques.\n\n"
    . "RÈGLES MÉTIER IMPORTANTES (respecte-les strictement, elles reflètent le vrai formulaire de saisie) :\n"
    . "- Le numéro de dossier (num_dossier) est généré AUTOMATIQUEMENT au format AAAAMMJJ-NNN si l'agent ne le fournit pas explicitement. Ne l'invente jamais toi-même, laisse l'outil le générer.\n"
    . "- num_parcelle, requerant, commune, type_demande et date_intro sont obligatoires pour créer un dossier.\n"
    . "- type_demande doit être l'un de : " . implode(', ', $TYPES_DEMANDE_VALIDES) . ". Selon le type, des champs supplémentaires sont pertinents :\n"
    . "  · TRANSFORMATION ou TRANSFORMATION_SURELEVATION → usage_actuel, usage_souhaite, niveau_transformation\n"
    . "  · SURELEVATION ou TRANSFORMATION_SURELEVATION → nb_niveaux_actuel, nb_niveaux_apres\n"
    . "  · EXTENSION → superficie_existante et superficie_extension (superficie_batie sera calculée automatiquement comme leur somme, ne la demande pas séparément)\n"
    . "  · RENOUVELLEMENT → num_arrete_origine, date_arrete_origine (l'échéance à 3 ans est calculée automatiquement)\n"
    . "- Un dossier ne peut passer au statut COMPLETE que si TOUS ses avis de service existants sont 'Favorable' (et qu'il y a au moins un avis). Si ce n'est pas le cas, explique-le à l'agent au lieu de forcer le changement.\n"
    . "- Un dossier passe automatiquement en archive si son statut est COMPLETE et son retrait est OUI.\n"
    . "- Avant une action destructive (suppression) ou d'archivage manuel, résume clairement ce que tu vas faire ; l'outil exige confirmation=true, donc si le contexte est ambigu, demande d'abord confirmation à l'agent.\n"
    . "- Services d'avis courants (catalogue) : " . implode(', ', $SERVICES_CATALOGUE) . ". Tu peux utiliser un autre nom de service si l'agent le précise, mais privilégie ce catalogue.\n"
    . "- Si un champ obligatoire manque et n'a pas été fourni par l'agent, demande-le au lieu de l'inventer. N'invente JAMAIS un numéro de dossier, un nom, une date ou un montant.\n"
    . "- Si une recherche ne renvoie aucun résultat, dis-le clairement, ne comble pas les trous.\n"
    . "- Sois concis et professionnel. Utilise des listes ou petits tableaux Markdown si utile, l'interface les affiche correctement.\n"
    . "- Pour un courrier ou un avis généré, laisse [entre crochets] toute information qui te manque.\n"
    . "- Statuts valides (situation_dossier) : " . implode(', ', $STATUTS_VALIDES) . ".\n"
    . "- Avis valides (avis_services.avis) : " . implode(', ', $AVIS_VALIDES) . ".";

// ===================================================================
// DÉFINITION DES OUTILS
// ===================================================================

$tools = [
    // --- LECTURE ---
    [
        'type' => 'function',
        'function' => [
            'name' => 'rechercher_dossiers',
            'description' => "Recherche des dossiers de permis de construire par texte libre (numéro de dossier ou nom du requérant), commune et/ou statut. Retourne jusqu'à 15 résultats résumés par défaut.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'recherche' => ['type' => 'string', 'description' => 'Texte libre : numéro de dossier ou nom du requérant'],
                    'commune' => ['type' => 'string', 'description' => 'Filtrer par commune (optionnel)'],
                    'situation' => ['type' => 'string', 'description' => "Filtrer par statut exact parmi: " . implode(', ', $STATUTS_VALIDES)],
                    'inclure_archives' => ['type' => 'boolean', 'description' => 'Inclure les dossiers archivés (par défaut false)'],
                    'limite' => ['type' => 'integer', 'description' => 'Nombre max de résultats (défaut 15, max 50)'],
                ],
                'required' => [],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'obtenir_dossier',
            'description' => "Récupère le détail complet d'un dossier (identification, projet, taxes, statut, avis de chaque service) à partir de son numéro EXACT.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'num_dossier' => ['type' => 'string', 'description' => 'Numéro exact du dossier'],
                ],
                'required' => ['num_dossier'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'lister_communes',
            'description' => 'Liste les communes enregistrées dans la plateforme.',
            'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'statistiques_dossiers',
            'description' => "Retourne des statistiques sur les dossiers actifs (non archivés) : total, répartition par statut, par commune, par usage, et total des taxes.",
            'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
    ],

    // --- ÉCRITURE ---
    [
        'type' => 'function',
        'function' => [
            'name' => 'creer_dossier',
            'description' => "Crée un nouveau dossier de permis de construire. Si num_dossier n'est pas fourni, il est généré automatiquement (AAAAMMJJ-NNN). num_parcelle, requerant, commune, type_demande et date_intro sont obligatoires. L'ordre est attribué automatiquement.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'num_dossier' => ['type' => 'string', 'description' => "Numéro du dossier. Laisser vide pour génération automatique AAAAMMJJ-NNN."],
                    'num_parcelle' => ['type' => 'string', 'description' => 'Numéro de parcelle (obligatoire)'],
                    'requerant' => ['type' => 'string', 'description' => 'Nom du requérant'],
                    'commune' => ['type' => 'string', 'description' => 'Commune concernée'],
                    'lotissement' => ['type' => 'string'],
                    'type_demande' => ['type' => 'string', 'description' => "Parmi: " . implode(', ', $TYPES_DEMANDE_VALIDES)],
                    'date_intro' => ['type' => 'string', 'description' => "Date d'introduction au format YYYY-MM-DD"],
                    'situation_dossier' => ['type' => 'string', 'description' => 'Statut initial (défaut EN INSTRUCTION)'],
                    'civilite' => ['type' => 'string', 'description' => "Monsieur, Madame, Monsieur & Madame, Collective, Entreprise ou Organisation"],
                    'tel_requerant' => ['type' => 'string'],
                    'depose_par' => ['type' => 'string', 'description' => 'PROPRIETAIRE ou AUTRE'],
                    'nom_deposant' => ['type' => 'string', 'description' => "Nom du représentant/déposant si depose_par=AUTRE ou civilite=Entreprise/Organisation"],
                    'superficie_parcelle' => ['type' => 'number'],
                    'superficie_batie' => ['type' => 'number', 'description' => "Ignoré si type_demande=EXTENSION (calculé automatiquement)"],
                    'nb_niveaux' => ['type' => 'integer', 'description' => '0=RDC, 1=R+1, ... 10=R+10'],
                    'usage' => ['type' => 'string'],
                    'usage_actuel' => ['type' => 'string', 'description' => 'Pour TRANSFORMATION / TRANSFORMATION_SURELEVATION'],
                    'usage_souhaite' => ['type' => 'string', 'description' => 'Pour TRANSFORMATION / TRANSFORMATION_SURELEVATION'],
                    'niveau_transformation' => ['type' => 'integer', 'description' => 'Pour TRANSFORMATION / TRANSFORMATION_SURELEVATION'],
                    'nb_niveaux_actuel' => ['type' => 'integer', 'description' => 'Pour SURELEVATION / TRANSFORMATION_SURELEVATION'],
                    'nb_niveaux_apres' => ['type' => 'integer', 'description' => 'Pour SURELEVATION / TRANSFORMATION_SURELEVATION'],
                    'superficie_existante' => ['type' => 'number', 'description' => 'Pour EXTENSION : superficie déjà construite'],
                    'superficie_extension' => ['type' => 'number', 'description' => 'Pour EXTENSION : superficie à construire'],
                    'num_arrete_origine' => ['type' => 'string', 'description' => 'Pour RENOUVELLEMENT'],
                    'date_arrete_origine' => ['type' => 'string', 'description' => 'Pour RENOUVELLEMENT, format YYYY-MM-DD'],
                    'taxe_urbanisme' => ['type' => 'number'],
                    'taxe_municipale' => ['type' => 'number'],
                    'autres_taxes' => ['type' => 'number'],
                    'champs_supplementaires' => [
                        'type' => 'object',
                        'description' => "Autres champs de la table dossiers non listés ci-dessus, en paires clé/valeur.",
                    ],
                ],
                'required' => ['num_parcelle', 'requerant', 'commune', 'type_demande', 'date_intro'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'modifier_dossier',
            'description' => "Modifie un ou plusieurs champs d'un dossier existant, identifié par son numéro exact. Le passage à COMPLETE est refusé si tous les avis ne sont pas Favorable. L'archivage automatique (COMPLETE + retrait OUI) et le recalcul de superficie_batie (EXTENSION) sont appliqués.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'num_dossier' => ['type' => 'string', 'description' => 'Numéro exact du dossier à modifier'],
                    'champs' => [
                        'type' => 'object',
                        'description' => 'Paires clé/valeur des champs à mettre à jour (ex: {"requerant": "Nouveau nom", "commune": "Thiès"})',
                    ],
                ],
                'required' => ['num_dossier', 'champs'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'changer_statut_dossier',
            'description' => "Change le statut (situation_dossier) d'un dossier. Le passage à COMPLETE est refusé si tous les avis ne sont pas Favorable. L'archivage automatique (COMPLETE + retrait OUI) est appliqué.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'num_dossier' => ['type' => 'string', 'description' => 'Numéro exact du dossier'],
                    'nouveau_statut' => ['type' => 'string', 'description' => "Parmi: " . implode(', ', $STATUTS_VALIDES)],
                    'retrait' => ['type' => 'string', 'description' => "Optionnel, parmi: OUI, NON"],
                ],
                'required' => ['num_dossier', 'nouveau_statut'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'definir_avis_service',
            'description' => "Crée ou met à jour (upsert) l'avis d'un service instructeur pour un dossier. Un seul avis par couple dossier/service : s'il existe déjà, il est mis à jour.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'num_dossier' => ['type' => 'string', 'description' => 'Numéro exact du dossier concerné'],
                    'service_name' => ['type' => 'string', 'description' => "Nom du service émetteur, idéalement parmi: " . implode(', ', $SERVICES_CATALOGUE)],
                    'avis' => ['type' => 'string', 'description' => "Parmi: " . implode(', ', $AVIS_VALIDES)],
                    'observation' => ['type' => 'string', 'description' => 'Commentaire ou motivation (optionnel)'],
                ],
                'required' => ['num_dossier', 'service_name', 'avis'],
            ],
        ],
    ],

    // --- ARCHIVAGE / SUPPRESSION ---
    [
        'type' => 'function',
        'function' => [
            'name' => 'archiver_dossier',
            'description' => "Archive manuellement un dossier (le retire des statistiques et recherches actives par défaut). Nécessite confirmation=true.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'num_dossier' => ['type' => 'string', 'description' => 'Numéro exact du dossier à archiver'],
                    'confirmation' => ['type' => 'boolean', 'description' => 'Doit être true pour exécuter réellement l\'archivage'],
                ],
                'required' => ['num_dossier', 'confirmation'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'restaurer_dossier',
            'description' => "Restaure (désarchive) un dossier précédemment archivé.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'num_dossier' => ['type' => 'string', 'description' => 'Numéro exact du dossier à restaurer'],
                ],
                'required' => ['num_dossier'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'supprimer_dossier',
            'description' => "Supprime DÉFINITIVEMENT un dossier et ses avis associés. Action irréversible. Nécessite confirmation=true.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'num_dossier' => ['type' => 'string', 'description' => 'Numéro exact du dossier à supprimer'],
                    'confirmation' => ['type' => 'boolean', 'description' => 'Doit être true pour exécuter réellement la suppression'],
                ],
                'required' => ['num_dossier', 'confirmation'],
            ],
        ],
    ],
];

$messages = array_merge(
    [['role' => 'system', 'content' => $systemPrompt]],
    $cleanHistory,
    [['role' => 'user', 'content' => $userMessage]]
);

$maxIterations = 8;
$finalContent = null;
$toolTrace = [];
$rateLimitRetries = 0;
$maxRateLimitRetries = 3;
$startTime = microtime(true);

try {
    for ($i = 0; $i < $maxIterations; $i++) {
        $payload = [
            'model' => 'openai/gpt-oss-20b',
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'temperature' => 0.3,
        ];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        configureCurlDefaults($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $response = curlExecWithRetry($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            logDebug('Erreur cURL assistant', ['erreur' => $curlError]);
            sendJSON(500, 'Erreur de connexion au moteur IA: ' . $curlError);
        }

        $data = json_decode($response, true);

        if ($httpCode === 429) {
            $rateLimitRetries++;
            if ($rateLimitRetries > $maxRateLimitRetries) {
                sendJSON(429, "Le quota gratuit de l'assistant est temporairement atteint. Réessaie dans une minute.");
            }
            $waitSeconds = 3;
            if (!empty($data['error']['message']) && preg_match('/try again in ([0-9]+(?:\.[0-9]+)?)s/', $data['error']['message'], $m)) {
                $waitSeconds = min(20, ceil((float) $m[1]) + 1);
            }
            logDebug('Rate limit Groq, attente avant retry', ['secondes' => $waitSeconds, 'tentative' => $rateLimitRetries]);
            sleep($waitSeconds);
            $i--;
            continue;
        }

        if ($httpCode !== 200 || !isset($data['choices'][0]['message'])) {
            logDebug('Erreur Groq', ['httpCode' => $httpCode, 'response' => $response]);
            $errMsg = $data['error']['message'] ?? ('Réponse inattendue du moteur IA (HTTP ' . $httpCode . ')');
            sendJSON($httpCode ?: 500, 'Erreur assistant: ' . $errMsg);
        }

        $message = $data['choices'][0]['message'];
        $messages[] = $message;

        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $toolCall) {
                $fnName = $toolCall['function']['name'] ?? '';
                $fnArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true);
                if (!is_array($fnArgs)) $fnArgs = [];

                $t0 = microtime(true);
                $result = executerOutilAssistant($fnName, $fnArgs, $userId, $STATUTS_VALIDES, $TYPES_DEMANDE_VALIDES, $AVIS_VALIDES);
                $dureeMs = (int) round((microtime(true) - $t0) * 1000);

                $succes = !isset($result['erreur']);
                $toolTrace[] = [
                    'nom' => $fnName,
                    'args' => $fnArgs,
                    'succes' => $succes,
                    'categorie' => categorieOutil($fnName),
                    'duree_ms' => $dureeMs,
                    'resume' => resumerResultatOutil($fnName, $result),
                ];

                logDebug('Outil assistant exécuté', ['outil' => $fnName, 'succes' => $succes, 'role' => $userRole]);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
            continue;
        }

        $finalContent = $message['content'] ?? '';
        break;
    }
} catch (Throwable $e) {
    logDebug('Exception non gérée assistant', ['erreur' => $e->getMessage()]);
    sendJSON(500, "Erreur interne de l'assistant. Réessaie dans un instant.");
}

if ($finalContent === null || $finalContent === '') {
    $finalContent = "Désolé, je n'ai pas pu obtenir de réponse complète après plusieurs étapes d'outils. Peux-tu reformuler ou préciser ta demande ?";
}

sendJSON(200, 'OK', [
    'reponse' => $finalContent,
    'outils_utilises' => $toolTrace,
    'nombre_appels_outils' => count($toolTrace),
    'duree_totale_ms' => (int) round((microtime(true) - $startTime) * 1000),
]);

// ===================================================================
// AIDES POUR LA TRACE / AFFICHAGE
// ===================================================================

function categorieOutil($nom) {
    $ecriture = ['creer_dossier', 'modifier_dossier', 'changer_statut_dossier', 'definir_avis_service', 'restaurer_dossier'];
    $suppression = ['archiver_dossier', 'supprimer_dossier'];
    if (in_array($nom, $suppression, true)) return 'suppression';
    if (in_array($nom, $ecriture, true)) return 'ecriture';
    return 'lecture';
}

function resumerResultatOutil($nom, $result) {
    if (isset($result['erreur'])) return $result['erreur'];
    switch ($nom) {
        case 'rechercher_dossiers': return ($result['nombre_resultats'] ?? 0) . ' dossier(s) trouvé(s)';
        case 'obtenir_dossier': return 'Dossier ' . ($result['num_dossier'] ?? '') . ' récupéré';
        case 'lister_communes': return count($result['communes'] ?? []) . ' commune(s)';
        case 'statistiques_dossiers': return ($result['total'] ?? 0) . ' dossier(s) actif(s)';
        case 'creer_dossier': return 'Dossier ' . ($result['num_dossier'] ?? '') . ' créé (ordre ' . ($result['ordre'] ?? '?') . ')';
        case 'modifier_dossier': return 'Dossier ' . ($result['num_dossier'] ?? '') . ' modifié';
        case 'changer_statut_dossier': return 'Statut mis à jour : ' . ($result['situation_dossier'] ?? '');
        case 'definir_avis_service': return 'Avis "' . ($result['service_name'] ?? '') . '" = ' . ($result['avis'] ?? '');
        case 'archiver_dossier': return 'Dossier archivé';
        case 'restaurer_dossier': return 'Dossier restauré';
        case 'supprimer_dossier': return 'Dossier supprimé définitivement';
        default: return 'OK';
    }
}

// ===================================================================
// DISPATCH DES OUTILS
// ===================================================================

function executerOutilAssistant($nom, $args, $userId, $statutsValides, $typesDemandeValides, $avisValides) {
    try {
        switch ($nom) {
            case 'rechercher_dossiers': return outilRechercherDossiers($args);
            case 'obtenir_dossier': return outilObtenirDossier($args);
            case 'lister_communes': return outilListerCommunes();
            case 'statistiques_dossiers': return outilStatistiques();
            case 'creer_dossier': return outilCreerDossier($args, $statutsValides, $typesDemandeValides, $userId);
            case 'modifier_dossier': return outilModifierDossier($args, $statutsValides, $userId);
            case 'changer_statut_dossier': return outilChangerStatut($args, $statutsValides, $userId);
            case 'definir_avis_service': return outilDefinirAvisService($args, $avisValides);
            case 'archiver_dossier': return outilArchiverDossier($args, $userId);
            case 'restaurer_dossier': return outilRestaurerDossier($args, $userId);
            case 'supprimer_dossier': return outilSupprimerDossier($args, $userId);
            default: return ['erreur' => "Outil inconnu: $nom"];
        }
    } catch (Throwable $e) {
        logDebug('Erreur outil assistant', ['outil' => $nom, 'erreur' => $e->getMessage()]);
        return ['erreur' => "Erreur interne lors de l'exécution de l'outil $nom"];
    }
}

// ===================================================================
// COUCHE D'ACCÈS SUPABASE
// ===================================================================

function supabaseRequestJSON($method, $path, $body = null) {
    global $SUPABASE_HEADERS;
    $url = SUPABASE_API_URL . $path;

    $ch = curl_init($url);
    configureCurlDefaults($ch);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS); // contient déjà Content-Type + Prefer: return=representation
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    $response = curlExecWithRetry($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        logDebug('Erreur Supabase (assistant)', ['url' => $url, 'methode' => $method, 'httpCode' => $httpCode, 'reponse' => $response]);
        $detail = '';
        $decodedErr = json_decode($response, true);
        if (is_array($decodedErr) && !empty($decodedErr['message'])) {
            $detail = ' - ' . $decodedErr['message'];
        }
        return ['erreur' => 'Erreur Supabase (HTTP ' . $httpCode . ')' . $detail];
    }

    if (trim((string) $response) === '') return [];
    $decoded = json_decode($response, true);
    return $decoded === null ? [] : $decoded;
}

function supabaseGetJSON($path) {
    return supabaseRequestJSON('GET', $path);
}

function fetchNextDossierOrdre() {
    $data = supabaseGetJSON('/dossiers?select=ordre&order=ordre.desc&limit=1');
    if (isset($data['erreur']) || !is_array($data) || empty($data) || !isset($data[0]['ordre'])) {
        return 1;
    }
    return intval($data[0]['ordre']) + 1;
}

// Reproduit exactement la logique JS de enregistrerDossier() : préfixe AAAAMMJJ,
// suffixe séquentiel sur 3 chiffres basé sur le nombre de dossiers du jour.
function fetchNextNumDossier() {
    $prefix = date('Ymd');
    for ($tentative = 0; $tentative < 5; $tentative++) {
        $data = supabaseGetJSON('/dossiers?select=id&num_dossier=like.' . $prefix . '-*');
        $count = (isset($data['erreur']) || !is_array($data)) ? 0 : count($data);
        $candidat = $prefix . '-' . str_pad((string) ($count + 1 + $tentative), 3, '0', STR_PAD_LEFT);

        $existant = supabaseGetJSON('/dossiers?select=id&num_dossier=eq.' . urlencode($candidat));
        if (!isset($existant['erreur']) && empty($existant)) {
            return $candidat;
        }
    }
    // Filet de sécurité peu probable : suffixe basé sur l'heure
    return $prefix . '-' . date('His');
}

function logDossierHistory($dossierId, $userId, $action, $fieldName = null, $oldValue = null, $newValue = null) {
    $entry = [
        'dossier_id' => (int) $dossierId,
        'user_id' => $userId,
        'action' => $action,
        'field_name' => $fieldName,
        'old_value' => $oldValue !== null ? (string) $oldValue : null,
        'new_value' => $newValue !== null ? (string) $newValue : null,
    ];
    supabaseRequestJSON('POST', '/dossier_history', $entry);
}

// Règle : COMPLETE + retrait=OUI => archivage auto, sinon désarchivé (si l'un des deux champs déclencheurs change)
function appliquerRegleArchivage(array &$data) {
    $isComplete = isset($data['situation_dossier']) && strtoupper((string) $data['situation_dossier']) === 'COMPLETE';
    $isRetraitOui = isset($data['retrait']) && strtoupper((string) $data['retrait']) === 'OUI';
    if ($isComplete && $isRetraitOui) {
        if (empty($data['archived_at'])) {
            $data['archived_at'] = date('Y-m-d');
        }
    } elseif (array_key_exists('situation_dossier', $data) || array_key_exists('retrait', $data)) {
        $data['archived_at'] = null;
    }
}

// Règle : un dossier ne peut passer à COMPLETE que si tous ses avis sont Favorable (et qu'il y en a au moins un)
function tousAvisFavorables($dossierId) {
    $avis = supabaseGetJSON('/avis_services?dossier_id=eq.' . (int) $dossierId . '&select=avis');
    if (isset($avis['erreur']) || !is_array($avis) || empty($avis)) return false;
    foreach ($avis as $a) {
        if (($a['avis'] ?? '') !== 'Favorable') return false;
    }
    return true;
}

// Calcule superficie_batie = superficie_existante + superficie_extension pour le type EXTENSION,
// comme calculerSuperficieExtension() dans admin.html.
function appliquerCalculExtension(array &$data, $typeDemandeEffectif) {
    if (strtoupper((string) $typeDemandeEffectif) !== 'EXTENSION') return;
    if (!array_key_exists('superficie_existante', $data) && !array_key_exists('superficie_extension', $data)) return;
    $existante = (float) ($data['superficie_existante'] ?? 0);
    $extension = (float) ($data['superficie_extension'] ?? 0);
    $data['superficie_batie'] = round($existante + $extension, 2);
}

// ===================================================================
// OUTILS - LECTURE
// ===================================================================

function outilRechercherDossiers($args) {
    $filters = [];

    if (!empty($args['recherche'])) {
        $q = urlencode((string) $args['recherche']);
        $filters[] = "or=(num_dossier.ilike.*$q*,requerant.ilike.*$q*)";
    }
    if (!empty($args['commune'])) {
        $filters[] = 'commune=eq.' . urlencode((string) $args['commune']);
    }
    if (!empty($args['situation'])) {
        $filters[] = 'situation_dossier=eq.' . urlencode(strtoupper(trim((string) $args['situation'])));
    }
    if (empty($args['inclure_archives'])) {
        $filters[] = 'archived_at=is.null';
    }

    $limite = isset($args['limite']) ? max(1, min(50, (int) $args['limite'])) : 15;

    $path = '/dossiers?select=num_dossier,requerant,commune,situation_dossier,date_intro,type_demande,retrait,archived_at'
        . '&order=ordre.asc&limit=' . $limite;
    if (!empty($filters)) {
        $path .= '&' . implode('&', $filters);
    }

    $data = supabaseGetJSON($path);
    if (isset($data['erreur'])) return $data;

    return ['nombre_resultats' => count($data), 'dossiers' => $data];
}

function outilObtenirDossier($args) {
    if (empty($args['num_dossier'])) return ['erreur' => 'num_dossier requis'];

    $num = urlencode((string) $args['num_dossier']);
    $data = supabaseGetJSON("/dossiers?num_dossier=eq.$num");
    if (isset($data['erreur'])) return $data;
    if (empty($data)) return ['erreur' => 'Dossier introuvable pour ce numéro'];

    $dossier = $data[0];
    $avis = supabaseGetJSON('/avis_services?dossier_id=eq.' . (int) $dossier['id'] . '&select=service_name,avis,observation,date_avis&order=service_name.asc');
    $dossier['avis_services'] = is_array($avis) && !isset($avis['erreur']) ? $avis : [];
    $dossier['total_taxes'] = round(
        (float) ($dossier['taxe_urbanisme'] ?? 0) + (float) ($dossier['taxe_municipale'] ?? 0) + (float) ($dossier['autres_taxes'] ?? 0),
        2
    );

    return $dossier;
}

function outilListerCommunes() {
    $data = supabaseGetJSON('/communes?select=nom&order=nom.asc');
    if (isset($data['erreur'])) return $data;
    return ['communes' => array_column($data, 'nom')];
}

function outilStatistiques() {
    $data = supabaseGetJSON('/dossiers?select=situation_dossier,commune,usage,taxe_urbanisme,taxe_municipale,autres_taxes&archived_at=is.null');
    if (isset($data['erreur'])) return $data;

    $parStatut = [];
    $parCommune = [];
    $parUsage = [];
    $totalTaxes = 0;
    foreach ($data as $row) {
        $s = $row['situation_dossier'] ?? 'INCONNU';
        $c = $row['commune'] ?? 'INCONNUE';
        $u = $row['usage'] ?? 'INCONNU';
        $parStatut[$s] = ($parStatut[$s] ?? 0) + 1;
        $parCommune[$c] = ($parCommune[$c] ?? 0) + 1;
        $parUsage[$u] = ($parUsage[$u] ?? 0) + 1;
        $totalTaxes += (float) ($row['taxe_urbanisme'] ?? 0) + (float) ($row['taxe_municipale'] ?? 0) + (float) ($row['autres_taxes'] ?? 0);
    }
    arsort($parStatut);
    arsort($parCommune);
    arsort($parUsage);

    return [
        'total' => count($data),
        'par_statut' => $parStatut,
        'par_commune' => $parCommune,
        'par_usage' => $parUsage,
        'total_taxes' => round($totalTaxes, 2),
    ];
}

// ===================================================================
// OUTILS - ÉCRITURE
// ===================================================================

function outilCreerDossier($args, $statutsValides, $typesDemandeValides, $userId) {
    foreach (['num_parcelle', 'requerant', 'commune', 'type_demande', 'date_intro'] as $champ) {
        if (empty($args[$champ])) return ['erreur' => "Champ obligatoire manquant: $champ"];
    }

    $typeDemande = strtoupper(trim((string) $args['type_demande']));
    if (!in_array($typeDemande, $typesDemandeValides, true)) {
        return ['erreur' => "type_demande invalide: $typeDemande. Valeurs valides: " . implode(', ', $typesDemandeValides)];
    }

    $numDossier = !empty($args['num_dossier']) ? (string) $args['num_dossier'] : null;
    if ($numDossier !== null) {
        $existant = supabaseGetJSON('/dossiers?select=id&num_dossier=eq.' . urlencode($numDossier));
        if (isset($existant['erreur'])) return $existant;
        if (!empty($existant)) return ['erreur' => 'Un dossier avec ce numéro existe déjà'];
    } else {
        $numDossier = fetchNextNumDossier();
    }

    $statut = 'EN INSTRUCTION';
    if (!empty($args['situation_dossier'])) {
        $statutDemande = strtoupper(trim((string) $args['situation_dossier']));
        if (!in_array($statutDemande, $statutsValides, true)) {
            return ['erreur' => "Statut invalide: $statutDemande. Statuts valides: " . implode(', ', $statutsValides)];
        }
        if ($statutDemande === 'COMPLETE') {
            return ['erreur' => "Impossible de créer un dossier directement au statut COMPLETE : aucun avis de service n'existe encore. Crée d'abord le dossier, ajoute les avis, puis change le statut."];
        }
        $statut = $statutDemande;
    }

    $champsAutorises = [
        'lotissement', 'civilite', 'tel_requerant', 'depose_par', 'nom_deposant',
        'superficie_parcelle', 'superficie_batie', 'nb_niveaux', 'usage',
        'usage_actuel', 'usage_souhaite', 'niveau_transformation',
        'nb_niveaux_actuel', 'nb_niveaux_apres',
        'superficie_existante', 'superficie_extension',
        'num_arrete_origine', 'date_arrete_origine',
        'taxe_urbanisme', 'taxe_municipale', 'autres_taxes',
    ];
    $body = [
        'num_dossier' => $numDossier,
        'num_parcelle' => (string) $args['num_parcelle'],
        'requerant' => (string) $args['requerant'],
        'commune' => (string) $args['commune'],
        'type_demande' => $typeDemande,
        'date_intro' => (string) $args['date_intro'],
        'situation_dossier' => $statut,
        'ordre' => fetchNextDossierOrdre(),
    ];
    foreach ($champsAutorises as $c) {
        if (isset($args[$c]) && $args[$c] !== '') $body[$c] = $args[$c];
    }
    if (!empty($args['champs_supplementaires']) && is_array($args['champs_supplementaires'])) {
        $body = array_merge($body, $args['champs_supplementaires']);
    }
    if ($userId) $body['created_by'] = $userId;

    appliquerCalculExtension($body, $typeDemande);
    appliquerRegleArchivage($body);

    $result = supabaseRequestJSON('POST', '/dossiers', $body);
    if (isset($result['erreur'])) return $result;
    $created = is_array($result) && isset($result[0]) ? $result[0] : $body;

    if (isset($created['id'])) {
        logDossierHistory($created['id'], $userId, 'creation_ia', null, null, $created['num_dossier'] ?? $body['num_dossier']);
    }

    return $created;
}

function outilModifierDossier($args, $statutsValides, $userId) {
    if (empty($args['num_dossier'])) return ['erreur' => 'num_dossier requis'];
    if (empty($args['champs']) || !is_array($args['champs'])) return ['erreur' => 'champs (objet clé/valeur) requis'];

    $champs = $args['champs'];
    unset($champs['id'], $champs['num_dossier'], $champs['ordre']);
    if (empty($champs)) return ['erreur' => 'Aucun champ modifiable fourni'];

    if (isset($champs['situation_dossier'])) {
        $statutDemande = strtoupper(trim((string) $champs['situation_dossier']));
        if (!in_array($statutDemande, $statutsValides, true)) {
            return ['erreur' => "Statut invalide: $statutDemande. Statuts valides: " . implode(', ', $statutsValides)];
        }
        $champs['situation_dossier'] = $statutDemande;
    }

    $num = urlencode((string) $args['num_dossier']);
    $avant = supabaseGetJSON("/dossiers?num_dossier=eq.$num");
    if (isset($avant['erreur'])) return $avant;
    if (empty($avant)) return ['erreur' => 'Dossier introuvable pour ce numéro'];
    $avant = $avant[0];

    if (($champs['situation_dossier'] ?? null) === 'COMPLETE' && !tousAvisFavorables($avant['id'])) {
        return ['erreur' => "Impossible de passer ce dossier à COMPLETE : tous les avis de service doivent être 'Favorable' (et il doit y en avoir au moins un)."];
    }

    $typeDemandeEffectif = $champs['type_demande'] ?? $avant['type_demande'] ?? null;
    if (strtoupper((string) $typeDemandeEffectif) === 'EXTENSION') {
        $champsExtension = [
            'superficie_existante' => $champs['superficie_existante'] ?? $avant['superficie_existante'] ?? 0,
            'superficie_extension' => $champs['superficie_extension'] ?? $avant['superficie_extension'] ?? 0,
        ];
        appliquerCalculExtension($champsExtension, 'EXTENSION');
        $champs['superficie_batie'] = $champsExtension['superficie_batie'];
    }

    appliquerRegleArchivage($champs);
    if ($userId) $champs['updated_by'] = $userId;

    $result = supabaseRequestJSON('PATCH', "/dossiers?num_dossier=eq.$num", $champs);
    if (isset($result['erreur'])) return $result;
    if (empty($result)) return ['erreur' => 'Dossier introuvable pour ce numéro'];
    $apres = is_array($result) && isset($result[0]) ? $result[0] : $result;

    foreach ($champs as $champ => $valeur) {
        if ($champ === 'updated_by') continue;
        $ancienneValeur = $avant[$champ] ?? null;
        if ($ancienneValeur != $valeur) {
            logDossierHistory($avant['id'], $userId, 'modification_ia', $champ, $ancienneValeur, $valeur);
        }
    }

    return $apres;
}

function outilChangerStatut($args, $statutsValides, $userId) {
    if (empty($args['num_dossier']) || empty($args['nouveau_statut'])) {
        return ['erreur' => 'num_dossier et nouveau_statut requis'];
    }

    $statut = strtoupper(trim((string) $args['nouveau_statut']));
    if (!in_array($statut, $statutsValides, true)) {
        return ['erreur' => "Statut invalide: $statut. Statuts valides: " . implode(', ', $statutsValides)];
    }

    $num = urlencode((string) $args['num_dossier']);
    $avant = supabaseGetJSON("/dossiers?num_dossier=eq.$num&select=id,situation_dossier");
    if (isset($avant['erreur'])) return $avant;
    if (empty($avant)) return ['erreur' => 'Dossier introuvable pour ce numéro'];
    $avant = $avant[0];

    if ($statut === 'COMPLETE' && !tousAvisFavorables($avant['id'])) {
        return ['erreur' => "Impossible de passer ce dossier à COMPLETE : tous les avis de service doivent être 'Favorable' (et il doit y en avoir au moins un). Utilise definir_avis_service pour les mettre à jour, ou obtenir_dossier pour voir l'état actuel des avis."];
    }

    $champs = ['situation_dossier' => $statut];
    if (isset($args['retrait']) && in_array(strtoupper((string) $args['retrait']), ['OUI', 'NON'], true)) {
        $champs['retrait'] = strtoupper((string) $args['retrait']);
    }

    appliquerRegleArchivage($champs);
    if ($userId) $champs['updated_by'] = $userId;

    $result = supabaseRequestJSON('PATCH', "/dossiers?num_dossier=eq.$num", $champs);
    if (isset($result['erreur'])) return $result;
    if (empty($result)) return ['erreur' => 'Dossier introuvable pour ce numéro'];
    $apres = is_array($result) && isset($result[0]) ? $result[0] : $result;

    logDossierHistory($avant['id'], $userId, 'changement_statut_ia', 'situation_dossier', $avant['situation_dossier'] ?? null, $statut);

    return $apres;
}

function outilDefinirAvisService($args, $avisValides) {
    if (empty($args['num_dossier']) || empty($args['service_name']) || empty($args['avis'])) {
        return ['erreur' => 'num_dossier, service_name et avis sont requis'];
    }

    $avisSaisi = trim((string) $args['avis']);
    $avisNormalise = null;
    foreach ($avisValides as $v) {
        if (strcasecmp($v, $avisSaisi) === 0) { $avisNormalise = $v; break; }
    }
    if ($avisNormalise === null) {
        return ['erreur' => "Avis invalide: $avisSaisi. Valeurs valides: " . implode(', ', $avisValides)];
    }

    $num = urlencode((string) $args['num_dossier']);
    $dossier = supabaseGetJSON("/dossiers?select=id&num_dossier=eq.$num");
    if (isset($dossier['erreur'])) return $dossier;
    if (empty($dossier)) return ['erreur' => 'Dossier introuvable pour ce numéro'];
    $dossierId = (int) $dossier[0]['id'];

    $serviceName = preg_replace('/\s+/', '', strtolower(trim((string) $args['service_name'])));
    $observation = isset($args['observation']) ? trim((string) $args['observation']) : null;

    $existant = supabaseGetJSON('/avis_services?dossier_id=eq.' . $dossierId . '&service_name=eq.' . rawurlencode($serviceName) . '&select=id');
    if (isset($existant['erreur'])) return $existant;

    if (!empty($existant)) {
        $avisId = $existant[0]['id'];
        $body = ['avis' => $avisNormalise, 'observation' => $observation, 'date_avis' => date('Y-m-d H:i:s')];
        $result = supabaseRequestJSON('PATCH', "/avis_services?id=eq.$avisId", $body);
        if (isset($result['erreur'])) return $result;
        return ['id' => $avisId, 'dossier_id' => $dossierId, 'service_name' => $serviceName, 'avis' => $avisNormalise, 'observation' => $observation, 'action' => 'mis_a_jour'];
    }

    $body = ['dossier_id' => $dossierId, 'service_name' => $serviceName, 'avis' => $avisNormalise, 'observation' => $observation, 'date_avis' => date('Y-m-d H:i:s')];
    $result = supabaseRequestJSON('POST', '/avis_services', $body);
    if (isset($result['erreur'])) return $result;
    $created = is_array($result) && isset($result[0]) ? $result[0] : $body;
    $created['action'] = 'cree';
    return $created;
}

// ===================================================================
// OUTILS - ARCHIVAGE / SUPPRESSION
// ===================================================================

function outilArchiverDossier($args, $userId) {
    if (empty($args['num_dossier'])) return ['erreur' => 'num_dossier requis'];
    if (empty($args['confirmation'])) {
        return ['erreur' => "Confirmation requise: rappelle à l'agent ce qui va être archivé et relance l'outil avec confirmation=true"];
    }

    $num = urlencode((string) $args['num_dossier']);
    $avant = supabaseGetJSON("/dossiers?num_dossier=eq.$num&select=id");
    if (isset($avant['erreur'])) return $avant;
    if (empty($avant)) return ['erreur' => 'Dossier introuvable pour ce numéro'];

    $result = supabaseRequestJSON('PATCH', "/dossiers?num_dossier=eq.$num", ['archived_at' => date('Y-m-d')]);
    if (isset($result['erreur'])) return $result;
    if (empty($result)) return ['erreur' => 'Dossier introuvable pour ce numéro'];

    logDossierHistory($avant[0]['id'], $userId, 'archivage_manuel_ia', 'archived_at', null, date('Y-m-d'));

    return is_array($result) && isset($result[0]) ? $result[0] : $result;
}

function outilRestaurerDossier($args, $userId) {
    if (empty($args['num_dossier'])) return ['erreur' => 'num_dossier requis'];

    $num = urlencode((string) $args['num_dossier']);
    $avant = supabaseGetJSON("/dossiers?num_dossier=eq.$num&select=id");
    if (isset($avant['erreur'])) return $avant;
    if (empty($avant)) return ['erreur' => 'Dossier introuvable pour ce numéro'];

    $result = supabaseRequestJSON('PATCH', "/dossiers?num_dossier=eq.$num", ['archived_at' => null]);
    if (isset($result['erreur'])) return $result;
    if (empty($result)) return ['erreur' => 'Dossier introuvable pour ce numéro'];

    logDossierHistory($avant[0]['id'], $userId, 'restauration_ia', 'archived_at', 'archivé', null);

    return is_array($result) && isset($result[0]) ? $result[0] : $result;
}

function outilSupprimerDossier($args, $userId) {
    if (empty($args['num_dossier'])) return ['erreur' => 'num_dossier requis'];
    if (empty($args['confirmation'])) {
        return ['erreur' => "Confirmation requise: rappelle à l'agent que cette suppression est définitive et relance l'outil avec confirmation=true"];
    }

    $num = urlencode((string) $args['num_dossier']);
    $dossier = supabaseGetJSON("/dossiers?select=id&num_dossier=eq.$num");
    if (isset($dossier['erreur'])) return $dossier;
    if (empty($dossier)) return ['erreur' => 'Dossier introuvable pour ce numéro'];
    $dossierId = (int) $dossier[0]['id'];

    logDebug('Suppression dossier via assistant IA', ['num_dossier' => (string) $args['num_dossier'], 'dossier_id' => $dossierId, 'user_id' => $userId]);

    $suppressionAvis = supabaseRequestJSON('DELETE', "/avis_services?dossier_id=eq.$dossierId");
    if (isset($suppressionAvis['erreur'])) return $suppressionAvis;

    $result = supabaseRequestJSON('DELETE', "/dossiers?num_dossier=eq.$num");
    if (isset($result['erreur'])) return $result;

    return ['supprime' => true, 'num_dossier' => (string) $args['num_dossier']];
}