<?php
session_start();
require_once '../config/db.php';
require_once '../src/ErrorHandler.php';
require_once '../src/CSRFProtection.php';
require_once '../src/IPManager.php';

// Initialiser le gestionnaire d'erreurs
ErrorHandler::init();

// Fonction pour envoyer une réponse JSON
function sendJsonResponse($success, $message = '', $data = []) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Vérifier la protection CSRF
try {
    CSRFProtection::checkRequest();
} catch (Exception $e) {
    error_log('Erreur CSRF: ' . $e->getMessage());
    sendJsonResponse(false, 'Erreur de sécurité. Veuillez rafraîchir la page et réessayer.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, 'Méthode non autorisée');
}

// Récupérer et valider les données
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$ip_address = trim(filter_input(INPUT_POST, 'ip_address', FILTER_VALIDATE_IP));
$vlan_input = trim($_POST['vlan'] ?? '');

// Validation des entrées
$errors = [];

if (empty($id) || $id === false) {
    $errors['id'] = 'ID invalide';
}

if (empty($ip_address)) {
    $errors['ip_address'] = 'L\'adresse IP est requise';
} elseif (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
    $errors['ip_address'] = 'Format d\'adresse IP invalide';
}

if (empty($vlan_input)) {
    $errors['vlan'] = 'Au moins un VLAN est requis';
} elseif (!preg_match('/^\d{1,4}(,\d{1,4})*$/', $vlan_input)) {
    $errors['vlan'] = 'Format de VLAN invalide. Utilisez des nombres séparés par des virgules';
}

// Si des erreurs de validation, on les renvoie
if (!empty($errors)) {
    sendJsonResponse(false, 'Validation échouée', ['errors' => $errors]);
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $ipManager = new IPManager($pdo);
    
    // Vérifier si l'adresse IP existe déjà pour un autre enregistrement
    $stmt = $pdo->prepare("SELECT id FROM ip_addresses WHERE ip_address = ? AND id != ?");
    $stmt->execute([$ip_address, $id]);
    if ($stmt->fetch()) {
        sendJsonResponse(false, 'Cette adresse IP est déjà utilisée', [
            'errors' => ['ip_address' => 'Cette adresse IP est déjà utilisée']
        ]);
    }
    
    // Convertir le string de VLANs en array
    $vlans = array_filter(array_map('trim', explode(',', $vlan_input)));
    
    // Démarrer une transaction
    $pdo->beginTransaction();
    
    try {
        // Mise à jour de l'IP
        $stmt = $pdo->prepare("UPDATE ip_addresses SET ip_address = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$ip_address, $id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Aucune adresse IP trouvée avec cet ID');
        }
        
        // Mise à jour des VLANs via les relations
        $result = $ipManager->updateAddressVlans($id, $vlans);
        
        if (!$result['success']) {
            throw new Exception($result['errors'][0] ?? 'Erreur lors de la mise à jour des VLANs');
        }
        
        // Valider la transaction
        $pdo->commit();
        
        // Réponse de succès
        sendJsonResponse(true, 'Adresse IP et VLAN mis à jour avec succès');
        
    } catch (Exception $e) {
        // Annuler la transaction en cas d'erreur
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    error_log('Erreur PDO dans update_ip_vlan.php: ' . $e->getMessage());
    sendJsonResponse(false, 'Erreur de base de données: ' . $e->getMessage());
    
} catch (Exception $e) {
    error_log('Erreur dans update_ip_vlan.php: ' . $e->getMessage());
    ErrorHandler::logError('Erreur dans update_ip_vlan.php', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    $errorMessage = 'Une erreur est survenue lors de la mise à jour';
    if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
        $errorMessage = 'Erreur de référence. Vérifiez que toutes les références sont valides.';
    } elseif (strpos($e->getMessage(), 'duplicate entry') !== false) {
        $errorMessage = 'Cette adresse IP est déjà utilisée.';
    }
    
    sendJsonResponse(false, $errorMessage);
}
