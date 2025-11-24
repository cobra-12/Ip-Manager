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
        
        require_once '../src/IPManager.php';
        $ipManager = new IPManager($pdo);
        
        // Utiliser la méthode updateCustomer qui gère les relations
        $result = $ipManager->updateCustomer($id, $customer_name, $city);
        
        if ($result['success']) {
            header('Location: gestion_ip.php?success=ip_assigned');
        } else {
            ErrorHandler::logError('Erreur dans use_ip.php', [
                'errors' => $result['errors'] ?? ['Erreur inconnue']
            ]);
            header('Location: gestion_ip.php?error=assignment_failed');
        }
    } catch(Exception $e) {
        ErrorHandler::logError('Erreur dans use_ip.php', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        header('Location: gestion_ip.php?error=assignment_failed');
    }
    exit;
}

// Si on arrive ici, c'est qu'il y a eu une erreur
header('Location: gestion_ip.php?error=invalid_request');