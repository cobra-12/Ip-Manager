<?php
/**
 * Script de migration pour ajouter le support des villes dynamiques
 * Ce script est non destructif et peut être exécuté plusieurs fois en toute sécurité
 */

// Désactiver l'affichage des erreurs pour la production
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/migration_errors.log');

// Démarrer la temporisation de sortie
ob_start();

echo "<pre>Début de la migration des villes...\n";

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
    
    // 1. Vérifier et modifier la colonne city si nécessaire
    echo "Vérification de la colonne 'city'...\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM ip_addresses WHERE Field = 'city'");
    $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($columnInfo) {
        // Si la colonne est un ENUM, on la convertit en VARCHAR
        if (stripos($columnInfo['Type'], 'enum') !== false) {
            echo "Conversion de la colonne 'city' de ENUM à VARCHAR(100)...\n";
            $pdo->exec("ALTER TABLE ip_addresses MODIFY city VARCHAR(100) NOT NULL DEFAULT 'Douala'");
            echo "✓ Colonne 'city' convertie avec succès.\n";
        } else {
            echo "ℹ La colonne 'city' est déjà au bon format.\n";
        }
    } else {
        // Si la colonne n'existe pas (normalement impossible si l'app fonctionne déjà)
        $pdo->exec("ALTER TABLE ip_addresses ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT 'Douala' AFTER customer_name");
        echo "✓ Colonne 'city' ajoutée.\n";
    }
    
    // 2. Création de la table cities si elle n'existe pas
    echo "\nVérification de la table 'cities'...\n";
    $tableExists = $pdo->query("SHOW TABLES LIKE 'cities'")->rowCount() > 0;
    
    if (!$tableExists) {
        echo "Création de la table 'cities'...\n";
        $pdo->exec("CREATE TABLE cities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            INDEX idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✓ Table 'cities' créée avec succès.\n";
    } else {
        echo "ℹ La table 'cities' existe déjà.\n";
    }
    
    // 3. Insertion des villes du Cameroun (uniquement si la table est vide)
    echo "\nVérification des villes existantes...\n";
    $cityCount = $pdo->query("SELECT COUNT(*) FROM cities")->fetchColumn();
    
    if ($cityCount == 0) {
        $cities = [
            'Abong-Mbang', 'Akom II', 'Ambam', 'Bafang', 'Bafia', 'Bafoussam', 'Bali', 'Bamenda',
            'Bangangté', 'Banyo', 'Bertoua', 'Buea', 'Buéa', 'Bogo', 'Bongor',
            'Campo', 'Dchang', 'Dschang', 'Douala', 'Ebolowa', 'Edéa', 'Edea', 'Eseka', 'Foumban',
            'Foumbot', 'Garoua', 'Garoua-Boulaï', 'Guider', 'Kousseri', 'Koutaba', 'Kribi', 'Kumba',
            'Limbe', 'Limbé', 'Lolodorf', 'Maroua', 'Mbalmayo', 'Mbanga', 'Mbandjock', 'Mbouda',
            'Mora', 'Mutengene', 'Ngaoundere', 'Ngaoundéré', 'Nguti', 'Nkongsamba', 'Nkoteng',
            'Sangmelima', 'Sangmélima', 'Souza', 'Tibati', 'Tiko', 'Touboro', 'Wum', 'Yabassi',
            'Yagoua', 'Yaounde', 'Yaoundé'
        ];
        
        // Nettoyage des doublons (comme Yaounde/Yaoundé)
        $cities = array_unique($cities);
        
        $stmt = $pdo->prepare("INSERT IGNORE INTO cities (name) VALUES (:name)");
        $inserted = 0;
        
        foreach ($cities as $city) {
            $stmt->execute([':name' => $city]);
            $inserted += $stmt->rowCount();
        }
        
        echo "✓ $inserted villes insérées dans la table 'cities'.\n";
    } else {
        $cityCount = $pdo->query("SELECT COUNT(*) FROM cities")->fetchColumn();
        echo "ℹ $cityCount villes déjà présentes dans la table 'cities'.\n";
    }
    
    // 4. Vérification finale
    $ipCount = $pdo->query("SELECT COUNT(*) FROM ip_addresses")->fetchColumn();
    $citiesInUse = $pdo->query("SELECT COUNT(DISTINCT city) FROM ip_addresses WHERE city IS NOT NULL AND city != ''")->fetchColumn();
    
    echo "\n=== RÉSUMÉ DE LA MIGRATION ===\n";
    echo "- Adresses IP dans la base : $ipCount\n";
    echo "- Villes distinctes utilisées : $citiesInUse\n";
    echo "- Villes disponibles : " . $pdo->query("SELECT COUNT(*) FROM cities")->fetchColumn() . "\n";
    
    // Vérifier s'il y a des villes non standard dans ip_addresses
    $nonStandardCities = $pdo->query("
        SELECT DISTINCT city 
        FROM ip_addresses 
        WHERE city IS NOT NULL 
        AND city != ''
        AND city NOT IN (SELECT name FROM cities)
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($nonStandardCities)) {
        echo "\n⚠ Attention, des villes non standard ont été détectées :\n";
        foreach ($nonStandardCities as $city) {
            echo "  - $city\n";
        }
        echo "\nCes villes ne sont pas dans la table 'cities' mais resteront dans 'ip_addresses'.\n";
        echo "Pour les ajouter à la table 'cities', utilisez l'interface d'administration.\n";
    }
    
    echo "\n✅ Migration terminée avec succès !\n";
    
} catch (PDOException $e) {
    echo "\n❌ ERREUR lors de la migration : " . $e->getMessage() . "\n";
    echo "Fichier : " . $e->getFile() . " (ligne " . $e->getLine() . ")\n";
    http_response_code(500);
}

// Envoyer le contenu du buffer
$output = ob_get_clean();
echo $output;

// Si c'est une requête AJAX, on s'arrête là
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration des villes - IP Manager</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; background-color: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        pre { 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 4px; 
            border-left: 4px solid #007bff;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
        h1 { color: #2c3e50; margin-top: 0; }
        .btn {
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
        }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Migration des villes IP Manager</h1>
        <p>Résultat de la migration :</p>
        <pre><?php echo htmlspecialchars($output); ?></pre>
        <a href="../index.php" class="btn">Retour à l'accueil</a>
    </div>
</body>
</html>
