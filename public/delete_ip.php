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

// Vérifier que la requête est en POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit('Méthode non autorisée');
}

// Vérifier que l'ID est fourni
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    $_SESSION['error'] = "ID d'adresse IP invalide";
    header('Location: list.php');
    exit;
}

$id = (int)$_POST['id'];

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $ipManager = new IPManager($pdo);
    $result = $ipManager->deleteIP($id);
    
    if ($result['success']) {
        $_SESSION['success'] = "L'adresse IP a été supprimée avec succès";
    } else {
        $_SESSION['error'] = $result['message'] ?? "Erreur lors de la suppression de l'adresse IP";
    }
    
} catch (Exception $e) {
    ErrorHandler::logError('Erreur lors de la suppression', [
        'id' => $id,
        'error' => $e->getMessage()
    ]);
    $_SESSION['error'] = "Une erreur est survenue lors de la suppression";
}

// Rediriger vers la liste
header('Location: list.php');
exit;
