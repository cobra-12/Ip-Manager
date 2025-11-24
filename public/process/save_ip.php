<?php
/**
 * Gestion de l'ajout d'adresses IP
 * Ce script traite le formulaire d'ajout d'adresse IP via AJAX
 */

// Démarrer la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Définir l'en-tête de réponse en JSON
header('Content-Type: application/json');

// Fonction pour envoyer une réponse JSON et terminer le script
function sendJsonResponse($success, $message, $data = []) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    
    echo json_encode($response);
    exit;
}

// Inclure les dépendances nécessaires
require_once '../../config/db.php';
require_once '../../src/ErrorHandler.php';
require_once '../../src/CSRFProtection.php';
require_once '../../src/IPManager.php';

// Initialiser le gestionnaire d'erreurs
ErrorHandler::init();

// Journaliser l'accès au fichier
error_log('Accès à save_ip.php - ' . date('Y-m-d H:i:s'));

// Vérifier que la requête est en POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log('Tentative d\'accès avec une méthode non autorisée: ' . $_SERVER['REQUEST_METHOD']);
    sendJsonResponse(false, 'Méthode non autorisée', ['http_code' => 405]);
}

// Vérifier la protection CSRF
try {
    CSRFProtection::checkRequest();
} catch (Exception $e) {
    error_log('Erreur CSRF: ' . $e->getMessage());
    sendJsonResponse(false, 'Erreur de sécurité. Veuillez rafraîchir la page et réessayer.', ['http_code' => 403]);
}

// Nettoyer et valider les entrées
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Récupérer et nettoyer les données du formulaire
$ip = cleanInput($_POST['ip_address'] ?? '');
$vlan = trim(cleanInput($_POST['vlan'] ?? '')); // Supprimer les espaces en début et fin
$customer_name = cleanInput($_POST['customer_name'] ?? '');
$city = cleanInput($_POST['city'] ?? 'Douala');

// Journalisation des données reçues pour le débogage
error_log('Données reçues - IP: ' . $ip . ', VLAN: ' . $vlan . ' (longueur: ' . strlen($vlan) . ')');

// Journaliser les données reçues (sans les informations sensibles)
error_log('Données reçues - IP: ' . $ip . ', VLAN: ' . $vlan . ', Ville: ' . $city);

// Tableau pour stocker les erreurs
$errors = [];

// Validation de l'adresse (monitoring) - n'importe quel format accepté, juste non vide
if (empty($ip)) {
    $errors[] = "L'adresse est obligatoire";
}

// Validation du VLAN
if (empty($vlan)) {
    $errors[] = "Au moins un numéro de VLAN est obligatoire";
} else {
    // Nettoyer les espaces et diviser par virgule pour gérer plusieurs VLANs
    $vlan = trim($vlan);
    $vlanArray = array_map('trim', explode(',', $vlan));
    $vlanArray = array_filter($vlanArray, function($v) { return !empty($v); }); // Supprimer les valeurs vides
    
    if (empty($vlanArray)) {
        $errors[] = "Au moins un numéro de VLAN est obligatoire";
    } else {
        // Valider chaque VLAN individuellement
        foreach ($vlanArray as $vlanNum) {
            // Vérifier que c'est un nombre
            if (!is_numeric($vlanNum)) {
                $errors[] = "Le VLAN '$vlanNum' n'est pas un nombre valide";
                continue;
            }
            
            // Vérifier la longueur (1 à 4 chiffres)
            if (strlen($vlanNum) < 1 || strlen($vlanNum) > 4) {
                $errors[] = "Le VLAN '$vlanNum' doit contenir entre 1 et 4 chiffres";
                continue;
            }
            
            // Vérifier la plage de valeurs (1 à 4094)
            $vlanInt = (int)$vlanNum;
            if ($vlanInt < 1 || $vlanInt > 4094) {
                $errors[] = "Le VLAN '$vlanNum' doit être compris entre 1 et 4094";
                continue;
            }
        }
        
        // Réassembler les VLANs validés (sans espaces, avec virgules)
        $vlan = implode(',', array_map('trim', $vlanArray));
    }
    
    // Note: Dans l'architecture relationnelle, plusieurs IPs peuvent partager le même VLAN
    // Il n'est donc pas nécessaire de vérifier si le VLAN existe déjà
}

// Validation du nom du client (optionnel mais limité à 100 caractères)
if (!empty($customer_name) && mb_strlen($customer_name) > 100) {
    $errors[] = "Le nom du client ne doit pas dépasser 100 caractères";
}

// Si des erreurs sont détectées
if (!empty($errors)) {
    error_log('Erreurs de validation: ' . implode(', ', $errors));
    sendJsonResponse(false, 'Erreur de validation', ['errors' => $errors]);
}

try {
    // Connexion à la base de données
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    // Initialiser IPManager
    $ipManager = new IPManager($pdo);
    
    // Vérifier si l'adresse existe déjà (utiliser ipExists et récupérer des détails simples)
    if ($ipManager->ipExists($ip)) {
        error_log("Tentative d'ajout d'une adresse existante: $ip");
        $stmt = $pdo->prepare("SELECT id, ip_address, city, status, created_at, updated_at FROM ip_addresses WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $existingIP = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
        sendJsonResponse(false, "Cette adresse existe déjà", [
            'error_type' => 'ip_exists',
            'existing_ip' => $existingIP,
            'form_data' => [
                'ip' => $ip,
                'vlan' => $vlan,
                'customer_name' => $customer_name,
                'city' => $city
            ]
        ]);
    }
    
    // Convertir la string de VLANs en array si nécessaire
    // Le vlan peut être "10" ou "10,20,30" - on doit le convertir en array
    $vlanArray = [];
    if (!empty($vlan)) {
        // Diviser par virgule et nettoyer chaque VLAN
        $vlanParts = array_map('trim', explode(',', $vlan));
        $vlanArray = array_filter($vlanParts, function($v) { 
            return !empty($v) && is_numeric($v); 
        });
    }
    
    // Si aucun VLAN valide, retourner une erreur
    if (empty($vlanArray)) {
        sendJsonResponse(false, 'Au moins un numéro de VLAN valide est requis', [
            'errors' => ['VLAN invalide']
        ]);
    }
    
    // Ajouter l'adresse IP avec les VLANs comme array
    $result = $ipManager->addIP($ip, $vlanArray, $customer_name, $city);
    
    if ($result['success']) {
        error_log("Adresse IP ajoutée avec succès: $ip");
        
        // Préparer les données de la nouvelle IP pour la réponse
        $newIP = [
            'ip_address' => $ip,
            'vlan' => $vlan,
            'customer_name' => $customer_name,
            'city' => $city,
            'status' => 'DOWN', // Statut par défaut pour une nouvelle IP
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        sendJsonResponse(true, "L'adresse IP $ip a été ajoutée avec succès", [
            'ip' => $newIP
        ]);
    } else {
        // Récupérer les messages d'erreur ou utiliser un message par défaut
        $errorMsgs = $result['errors'] ?? ['Une erreur est survenue lors de l\'ajout'];
        
        // Si on a des détails sur l'IP existante, on les ajoute au message
        if (isset($result['existing_ip'])) {
            $existing = $result['existing_ip'];
            $details = [];
            if (!empty($existing['vlan'])) $details[] = "VLAN: " . $existing['vlan'];
            if (!empty($existing['customer_name'])) $details[] = "Client: " . $existing['customer_name'];
            if (!empty($existing['status'])) $details[] = "Statut: " . $existing['status'];
            
            if (!empty($details)) {
                $errorMsgs[] = "Détails de l'IP existante : " . implode(', ', $details);
            }
        }
        
        error_log("Erreur lors de l'ajout de l'IP $ip: " . implode(' | ', $errorMsgs));
        sendJsonResponse(false, implode(' ', $errorMsgs), [
            'errors' => $errorMsgs,
            'form_data' => [
                'ip' => $ip,
                'vlan' => $vlan,
                'customer_name' => $customer_name,
                'city' => $city
            ]
        ]);
    }
} catch (PDOException $e) {
    error_log('Erreur PDO: ' . $e->getMessage());
    sendJsonResponse(false, 'Une erreur est survenue lors de la connexion à la base de données', [
        'error_type' => 'database_error',
        'error_details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('Erreur inattendue: ' . $e->getMessage());
    sendJsonResponse(false, 'Une erreur inattendue est survenue', [
        'error_type' => 'unexpected_error',
        'error_details' => $e->getMessage()
    ]);
}
?>
