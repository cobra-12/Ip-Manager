<?php
/**
 * Script de migration pour convertir les données existantes vers les nouvelles relations
 * - Crée des utilisateurs à partir de customer_name
 * - Crée des VLANs à partir de vlan
 * - Établit les relations user_addresses et address_vlans
 */

// Désactiver l'affichage des erreurs pour la production
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/migration_errors.log');

// Démarrer la temporisation de sortie
ob_start();

echo "<pre>Début de la migration des relations...\n";

// Vérifier si le fichier de configuration existe
if (!file_exists(__DIR__ . '/../../config/db.php')) {
    die("ERREUR: Le fichier de configuration de la base de données est introuvable.\n");
}

require_once __DIR__ . '/../../config/db.php';

try {
    // Connexion à la base de données
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'utf8mb4'");
    
    // 1. Créer les utilisateurs à partir de customer_name
    echo "Étape 1: Migration des utilisateurs...\n";
    $stmt = $pdo->query("
        SELECT DISTINCT customer_name, city 
        FROM ip_addresses 
        WHERE customer_name IS NOT NULL AND customer_name != ''
        ORDER BY customer_name
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $userMap = []; // Pour mapper customer_name => user_id
    $stmtInsertUser = $pdo->prepare("INSERT IGNORE INTO users (name, city) VALUES (:name, :city)");
    $stmtGetUser = $pdo->prepare("SELECT id FROM users WHERE name = :name");
    
    foreach ($customers as $customer) {
        $name = trim($customer['customer_name']);
        $city = $customer['city'] ?? 'Douala';
        
        if (!empty($name)) {
            // Insérer l'utilisateur s'il n'existe pas
            $stmtInsertUser->execute([':name' => $name, ':city' => $city]);
            
            // Récupérer l'ID de l'utilisateur
            $stmtGetUser->execute([':name' => $name]);
            $user = $stmtGetUser->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $userMap[$name] = $user['id'];
                echo "  ✓ Utilisateur créé: {$name} (ID: {$user['id']})\n";
            }
        }
    }
    echo "  ✓ " . count($userMap) . " utilisateurs migrés\n\n";
    
    // 2. Créer les relations user_addresses
    echo "Étape 2: Création des relations user_addresses...\n";
    $stmt = $pdo->query("
        SELECT id, customer_name 
        FROM ip_addresses 
        WHERE customer_name IS NOT NULL AND customer_name != ''
    ");
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtInsertRelation = $pdo->prepare("INSERT IGNORE INTO user_addresses (user_id, address_id) VALUES (:user_id, :address_id)");
    $relationsCount = 0;
    
    foreach ($addresses as $address) {
        $customerName = trim($address['customer_name']);
        if (isset($userMap[$customerName])) {
            $stmtInsertRelation->execute([
                ':user_id' => $userMap[$customerName],
                ':address_id' => $address['id']
            ]);
            $relationsCount++;
        }
    }
    echo "  ✓ {$relationsCount} relations user_addresses créées\n\n";
    
    // 3. Créer les VLANs à partir de vlan
    echo "Étape 3: Migration des VLANs...\n";
    $stmt = $pdo->query("
        SELECT DISTINCT vlan 
        FROM ip_addresses 
        WHERE vlan IS NOT NULL AND vlan != ''
        ORDER BY vlan
    ");
    $vlans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $vlanMap = []; // Pour mapper vlan_number => vlan_id
    $stmtInsertVlan = $pdo->prepare("INSERT IGNORE INTO vlans (vlan_number) VALUES (:vlan_number)");
    $stmtGetVlan = $pdo->prepare("SELECT id FROM vlans WHERE vlan_number = :vlan_number");
    
    foreach ($vlans as $vlan) {
        $vlanNumber = trim($vlan['vlan']);
        
        if (!empty($vlanNumber)) {
            // Insérer le VLAN s'il n'existe pas
            $stmtInsertVlan->execute([':vlan_number' => $vlanNumber]);
            
            // Récupérer l'ID du VLAN
            $stmtGetVlan->execute([':vlan_number' => $vlanNumber]);
            $vlanData = $stmtGetVlan->fetch(PDO::FETCH_ASSOC);
            if ($vlanData) {
                $vlanMap[$vlanNumber] = $vlanData['id'];
                echo "  ✓ VLAN créé: {$vlanNumber} (ID: {$vlanData['id']})\n";
            }
        }
    }
    echo "  ✓ " . count($vlanMap) . " VLANs migrés\n\n";
    
    // 4. Créer les relations address_vlans
    echo "Étape 4: Création des relations address_vlans...\n";
    $stmt = $pdo->query("
        SELECT id, vlan 
        FROM ip_addresses 
        WHERE vlan IS NOT NULL AND vlan != ''
    ");
    $addressesWithVlan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtInsertVlanRelation = $pdo->prepare("INSERT IGNORE INTO address_vlans (address_id, vlan_id) VALUES (:address_id, :vlan_id)");
    $vlanRelationsCount = 0;
    
    foreach ($addressesWithVlan as $address) {
        $vlanNumber = trim($address['vlan']);
        if (isset($vlanMap[$vlanNumber])) {
            $stmtInsertVlanRelation->execute([
                ':address_id' => $address['id'],
                ':vlan_id' => $vlanMap[$vlanNumber]
            ]);
            $vlanRelationsCount++;
        }
    }
    echo "  ✓ {$vlanRelationsCount} relations address_vlans créées\n\n";
    
    echo "✅ Migration terminée avec succès !\n";
    echo "   - Utilisateurs: " . count($userMap) . "\n";
    echo "   - Relations user_addresses: {$relationsCount}\n";
    echo "   - VLANs: " . count($vlanMap) . "\n";
    echo "   - Relations address_vlans: {$vlanRelationsCount}\n";
    
    ob_end_flush();
    
} catch (PDOException $e) {
    ob_end_clean();
    echo "ERREUR lors de la migration: " . $e->getMessage() . "\n";
    echo "Fichier: " . $e->getFile() . " Ligne: " . $e->getLine() . "\n";
    exit(1);
} catch (Exception $e) {
    ob_end_clean();
    echo "ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
