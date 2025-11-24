<?php
/**
 * Script d'installation pour IP Manager
 * Crée la base de données, les tables et la structure nécessaire
 */

// Configuration de la base de données
$host = 'localhost';
$username = 'root';
$password = ''; // Mot de passe par défaut pour WAMP
$database = 'ipmanager';

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation IP Manager</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background-color: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #17a2b8; }
        h1 { color: #2c3e50; text-align: center; }
        .step { margin: 1rem 0; padding: 1rem; background: #f8f9fa; border-radius: 4px; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #007bff; color: white; text-decoration: none; border-radius: 4px; margin-top: 1rem; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Installation IP Manager</h1>
        
        <?php
        try {
            echo '<div class="step">';
            echo '<h3>Étape 1: Connexion au serveur MySQL</h3>';
            
            // Connexion au serveur MySQL
            $pdo = new PDO("mysql:host=$host", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo '<p class="success">✓ Connexion au serveur MySQL réussie</p>';
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 2: Création de la base de données</h3>';
            
            // Création de la base de données
            $sql = "CREATE DATABASE IF NOT EXISTS $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            $pdo->exec($sql);
            echo '<p class="success">✓ Base de données "' . $database . '" créée avec succès</p>';
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 3: Sélection de la base de données</h3>';
            
            // Sélection de la base de données
            $pdo->exec("USE $database");
            echo '<p class="success">✓ Base de données sélectionnée</p>';
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 4: Création de la table ip_addresses</h3>';
            
            // Suppression de la table si elle existe déjà
            try {
                $pdo->exec("DROP TABLE IF EXISTS ip_addresses");
                echo '<p class="info">✓ Ancienne table "ip_addresses" supprimée</p>';
            } catch (PDOException $e) {
                echo '<p class="info">ℹ Aucune ancienne table à supprimer</p>';
            }
            
            // Création de la table ip_addresses avec ville générique (VARCHAR) au lieu d'ENUM
            $sql = "CREATE TABLE ip_addresses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL UNIQUE,
                vlan VARCHAR(50) NOT NULL,
                customer_name VARCHAR(100) DEFAULT '',
                city VARCHAR(100) NOT NULL DEFAULT 'Douala',
                status ENUM('UP', 'DOWN') NOT NULL DEFAULT 'DOWN',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ip_address (ip_address),
                INDEX idx_vlan (vlan),
                INDEX idx_status (status),
                INDEX idx_customer_name (customer_name),
                INDEX idx_city (city)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            try {
                $pdo->exec($sql);
                echo '<p class="success">✓ Table "ip_addresses" créée avec succès</p>';
                
                // Vérification que la colonne city existe
                $stmt = $pdo->query("SHOW COLUMNS FROM ip_addresses LIKE 'city'");
                if ($stmt->rowCount() > 0) {
                    echo '<p class="success">✓ Colonne "city" vérifiée avec succès</p>';
                    // S'assurer que le type est VARCHAR et non ENUM (migration légère)
                    $colInfo = $pdo->query("SHOW COLUMNS FROM ip_addresses WHERE Field='city'")->fetch(PDO::FETCH_ASSOC);
                    if (isset($colInfo['Type']) && stripos($colInfo['Type'], 'enum') !== false) {
                        $pdo->exec("ALTER TABLE ip_addresses MODIFY city VARCHAR(100) NOT NULL DEFAULT 'Douala'");
                        echo '<p class="info">ℹ Colonne "city" convertie en VARCHAR(100)</p>';
                    }
                } else {
                    // Si la colonne n'existe pas, on l'ajoute en VARCHAR
                    $pdo->exec("ALTER TABLE ip_addresses ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT 'Douala' AFTER customer_name");
                    echo '<p class="success">✓ Colonne "city" ajoutée avec succès</p>';
                }
            } catch (PDOException $e) {
                echo '<p class="error">✗ Erreur lors de la création de la table : ' . $e->getMessage() . '</p>';
                throw $e; // Propage l'erreur pour arrêter le script
            }
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 5: Création et remplissage de la table des villes</h3>';
            try {
                // Créer la table des villes si elle n'existe pas
                $pdo->exec("CREATE TABLE IF NOT EXISTS cities (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL UNIQUE,
                    INDEX idx_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                echo '<p class="success">✓ Table "cities" prête</p>';

                // Liste des villes du Cameroun (principales + chefs-lieux)
                $cmCities = [
                    'Abong-Mbang','Akom II','Ambam','Bafang','Bafia','Bafoussam','Bali','Bamenda','Bangangté','Banyo','Banyo','Bertoua','Buea','Buéa','Bogo','Bonamoussadi','Bongor','Buea','Campo','Dchang','Dschang','Douala','Ebolowa','Edéa','Edea','Eseka','Foumban','Foumbot','Garoua','Garoua-Boulaï','Guider','Kousseri','Koutaba','Kribi','Kumba','Limbe','Limbé','Lolodorf','Maroua','Mbalmayo','Mbanga','Mbandjock','Mbouda','Mora','Mutengene','Ngaoundere','Ngaoundéré','Nguti','Nkongsamba','Nkoteng','Sangmelima','Sangmélima','Souza','Tibati','Tiko','Touboro','Wum','Yabassi','Yagoua','Yaounde','Yaoundé'
                ];

                // Insérer si absent
                $stmt = $pdo->prepare("INSERT IGNORE INTO cities (name) VALUES (:name)");
                $count = 0;
                foreach ($cmCities as $cityName) {
                    $stmt->execute([':name' => $cityName]);
                    $count += $stmt->rowCount();
                }
                echo '<p class="info">ℹ Villes insérées/présentes: ' . $count . '</p>';
            } catch (PDOException $e) {
                echo '<p class="error">✗ Erreur lors de la préparation des villes : ' . $e->getMessage() . '</p>';
                // ne pas arrêter l'installation pour ça
            }
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 5: Création de la table users</h3>';
            try {
                // Création de la table users
                $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(100) DEFAULT NULL,
                    city VARCHAR(100) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_name (name),
                    INDEX idx_email (email),
                    INDEX idx_city (city)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                echo '<p class="success">✓ Table "users" créée avec succès</p>';
            } catch (PDOException $e) {
                echo '<p class="error">✗ Erreur lors de la création de la table users : ' . $e->getMessage() . '</p>';
            }
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 6: Création de la table vlans</h3>';
            try {
                // Création de la table vlans
                $pdo->exec("CREATE TABLE IF NOT EXISTS vlans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    vlan_number VARCHAR(50) NOT NULL UNIQUE,
                    description TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_vlan_number (vlan_number)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                echo '<p class="success">✓ Table "vlans" créée avec succès</p>';
            } catch (PDOException $e) {
                echo '<p class="error">✗ Erreur lors de la création de la table vlans : ' . $e->getMessage() . '</p>';
            }
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 7: Création de la table user_addresses</h3>';
            try {
                // Création de la table de liaison user_addresses (many-to-many)
                $pdo->exec("CREATE TABLE IF NOT EXISTS user_addresses (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    address_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (address_id) REFERENCES ip_addresses(id) ON DELETE CASCADE,
                    UNIQUE KEY unique_user_address (user_id, address_id),
                    INDEX idx_user_id (user_id),
                    INDEX idx_address_id (address_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                echo '<p class="success">✓ Table "user_addresses" créée avec succès</p>';
            } catch (PDOException $e) {
                echo '<p class="error">✗ Erreur lors de la création de la table user_addresses : ' . $e->getMessage() . '</p>';
            }
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 8: Création de la table address_vlans</h3>';
            try {
                // Création de la table de liaison address_vlans (many-to-many)
                $pdo->exec("CREATE TABLE IF NOT EXISTS address_vlans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    address_id INT NOT NULL,
                    vlan_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (address_id) REFERENCES ip_addresses(id) ON DELETE CASCADE,
                    FOREIGN KEY (vlan_id) REFERENCES vlans(id) ON DELETE CASCADE,
                    UNIQUE KEY unique_address_vlan (address_id, vlan_id),
                    INDEX idx_address_id (address_id),
                    INDEX idx_vlan_id (vlan_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                echo '<p class="success">✓ Table "address_vlans" créée avec succès</p>';
            } catch (PDOException $e) {
                echo '<p class="error">✗ Erreur lors de la création de la table address_vlans : ' . $e->getMessage() . '</p>';
            }
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 9: Création du fichier de configuration</h3>';
            
            // Création du dossier config s'il n'existe pas
            if (!is_dir('config')) {
                mkdir('config', 0755, true);
                echo '<p class="info">✓ Dossier "config" créé</p>';
            }
            
            // Création du fichier de configuration
            $config_content = "<?php
/**
 * Configuration de la base de données pour IP Manager
 * Généré automatiquement par install.php
 */
define('DB_HOST', '$host');
define('DB_USER', '$username');
define('DB_PASS', '$password');
define('DB_NAME', '$database');
";
            
            $config_file = fopen("config/db.php", "w");
            if ($config_file) {
                fwrite($config_file, $config_content);
                fclose($config_file);
                echo '<p class="success">✓ Fichier de configuration "config/db.php" créé avec succès</p>';
            } else {
                throw new Exception("Impossible de créer le fichier de configuration");
            }
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 10: Création des dossiers nécessaires</h3>';
            
            // Création des dossiers nécessaires
            $directories = [
                'logs' => 'Fichiers de logs de l\'application',
                'exports' => 'Fichiers d\'export CSV',
                'src' => 'Classes PHP de l\'application'
            ];
            
            foreach ($directories as $dir => $description) {
                if (!is_dir($dir)) {
                    if (mkdir($dir, 0755, true)) {
                        echo '<p class="success">✓ Dossier "' . $dir . '" créé (' . $description . ')</p>';
                    } else {
                        echo '<p class="error">✗ Erreur lors de la création du dossier "' . $dir . '"</p>';
                    }
                } else {
                    echo '<p class="info">ℹ Dossier "' . $dir . '" existe déjà</p>';
                }
            }
            echo '</div>';

            echo '<div class="step">';
            echo '<h3>Étape 11: Vérification de l\'installation</h3>';
            
            // Vérification que tout fonctionne
            $stmt = $pdo->query("SHOW TABLES LIKE 'ip_addresses'");
            if ($stmt->rowCount() > 0) {
                echo '<p class="success">✓ Table ip_addresses vérifiée</p>';
            }
            
            // Test de connexion avec le fichier de config
            if (file_exists('config/db.php')) {
                require_once 'config/db.php';
                $test_pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
                echo '<p class="success">✓ Configuration de base de données testée</p>';
            }
            
            echo '</div>';

            echo '<div style="text-align: center; margin-top: 2rem; padding: 2rem; background: #d4edda; border-radius: 8px;">';
            echo '<h2 class="success">🎉 Installation terminée avec succès !</h2>';
            echo '<p>Votre application IP Manager est maintenant prête à être utilisée.</p>';
            echo '<a href="public/index.php" class="btn">🚀 Accéder à l\'application</a>';
            echo '</div>';

        } catch(PDOException $e) {
            echo '<div style="text-align: center; margin-top: 2rem; padding: 2rem; background: #f8d7da; border-radius: 8px;">';
            echo '<h2 class="error">❌ Erreur lors de l\'installation</h2>';
            echo '<p class="error">Erreur de base de données : ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<p>Vérifiez que MySQL est démarré et que les paramètres de connexion sont corrects.</p>';
            echo '</div>';
        } catch(Exception $e) {
            echo '<div style="text-align: center; margin-top: 2rem; padding: 2rem; background: #f8d7da; border-radius: 8px;">';
            echo '<h2 class="error">❌ Erreur lors de l\'installation</h2>';
            echo '<p class="error">Erreur : ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        }
        ?>
        
        <div style="margin-top: 2rem; padding: 1rem; background: #e2e3e5; border-radius: 4px;">
            <h3>📋 Informations d'installation</h3>
            <ul>
                <li><strong>Base de données :</strong> <?php echo $database; ?></li>
                <li><strong>Serveur :</strong> <?php echo $host; ?></li>
                <li><strong>Utilisateur :</strong> <?php echo $username; ?></li>
                <li><strong>Version PHP :</strong> <?php echo PHP_VERSION; ?></li>
                <li><strong>Extensions requises :</strong> PDO MySQL</li>
            </ul>
        </div>
    </div>
</body>
</html>