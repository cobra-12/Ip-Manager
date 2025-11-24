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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export') {
    try {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $ipManager = new IPManager($pdo);
        
        $result = $ipManager->exportToCSV();
        
        if ($result['success']) {
            // Forcer le téléchargement du fichier
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
            header('Content-Length: ' . filesize($result['filepath']));
            
            readfile($result['filepath']);
            
            // Supprimer le fichier temporaire
            unlink($result['filepath']);
            exit;
        } else {
            header('Location: list.php?error=export');
        }
    } catch(Exception $e) {
        ErrorHandler::logError('Erreur lors de l\'export', ['error' => $e->getMessage()]);
        header('Location: list.php?error=export');
    }
}

// Redirection si accès direct
header('Location: list.php');
