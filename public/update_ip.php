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
    $ip_address = $_POST['ip_address'] ?? '';
    $vlan = $_POST['vlan'] ?? '';
    $customer_name = $_POST['customer_name'] ?? '';
    $city = $_POST['city'] ?? 'Douala';

    try {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Mise à jour directe dans la base de données
        $sql = "UPDATE ip_addresses SET 
                ip_address = :ip_address, 
                vlan = :vlan, 
                customer_name = :customer_name, 
                city = :city,
                status = IF(:customer_name = '', 'DOWN', 'UP'),
                updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':ip_address' => $ip_address,
            ':vlan' => $vlan,
            ':customer_name' => $customer_name,
            ':city' => $city,
            ':id' => $id
        ]);
        
        header('Location: gestion_ip.php?success=updated');
    } catch(Exception $e) {
        ErrorHandler::logError('Erreur dans update_ip.php', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        header('Location: gestion_ip.php?error=update_failed');
    }
    exit;
}