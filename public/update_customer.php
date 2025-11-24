<?php
session_start();
require_once '../config/db.php';
require_once '../src/ErrorHandler.php';
require_once '../src/CSRFProtection.php';
require_once '../src/IPManager.php';

// Initialiser le gestionnaire d'erreurs
ErrorHandler::init();

// Vérifier la protection CSRF
CSRFProtection::checkRequest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $customer_name = $_POST['customer_name'] ?? '';
    $city = $_POST['city'] ?? 'Douala';

    try {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $ipManager = new IPManager($pdo);
        
        // Gérer les clients multiples (séparés par virgule)
        $customers = array_map('trim', explode(',', $customer_name));
        $customers = array_filter($customers); // Supprimer les valeurs vides
        
        // Utiliser la méthode updateCustomer qui gère les relations
        $result = $ipManager->updateCustomer($id, $customers, $city);
        
        if ($result['success']) {
            header('Location: gestion_ip.php?success=customer_updated');
        } else {
            ErrorHandler::logError('Erreur dans update_customer.php', [
                'errors' => $result['errors'] ?? ['Erreur inconnue']
            ]);
            header('Location: gestion_ip.php?error=update_failed');
        }
    } catch(Exception $e) {
        ErrorHandler::logError('Erreur dans update_customer.php', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        header('Location: gestion_ip.php?error=update_failed');
    }
    exit;
}