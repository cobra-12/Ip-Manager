<?php
session_start();

// Vérifier si la configuration existe
if (!file_exists('../config/db.php')) {
    die('Configuration de base de données manquante. Veuillez exécuter install.php d\'abord.');
}

require_once '../config/db.php';

// Vérifier si les classes existent
if (!file_exists('../src/ErrorHandler.php') || 
    !file_exists('../src/CSRFProtection.php') || 
    !file_exists('../src/IPManager.php')) {
    die('Classes manquantes. Veuillez vérifier l\'installation.');
}

require_once '../src/ErrorHandler.php';
require_once '../src/CSRFProtection.php';
require_once '../src/IPManager.php';

// Initialiser le gestionnaire d'erreurs
ErrorHandler::init();

// Vérifier la protection CSRF
CSRFProtection::checkRequest();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP Manager - Accueil</title>
    <link rel="icon" type="image/png" href="assets/image/swecom.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="assets/js/script.js" defer></script>
</head>
<body class="homepage">
    <div class="homepage-container">
        <!-- Logo au centre -->
        <div class="logo-container">
            <img src="assets/image/swecom.png" alt="SWECOM Logo" class="main-logo">
        </div>
        
        <!-- Boutons d'action -->
        <div class="homepage-buttons">
            <a href="list.php" class="homepage-btn btn-list">
                <i class="fas fa-list"></i>
                <span>Liste</span>
            </a>
            <a href="#" id="showAddForm" class="homepage-btn btn-add">
                <i class="fas fa-plus"></i>
                <span>Ajouter</span>
            </a>
            <a href="gestion_ip.php" class="homepage-btn btn-manage">
                <i class="fas fa-cogs"></i>
                <span>Gestion</span>
            </a>
        </div>

        <!-- Formulaire d'ajout IP (masqué par défaut) -->
        <div id="addIpForm" class="add-form-overlay" style="display: none;">
            <div class="add-form-container">
                <div class="add-form-header">
                    <h2>Ajouter une Adresse IP</h2>
                    <button type="button" class="close-form" onclick="closeAddForm()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <?php if(isset($_GET['error'])): ?>
                    <div class="alert alert-error">
                        <?php 
                            switch($_GET['error']) {
                                case 'ip_exists':
                                    echo "Cette adresse IP existe déjà.";
                                    break;
                                case 'vlan_exists':
                                    echo "Ce VLAN existe déjà.";
                                    break;
                                case 'db_error':
                                    echo "Une erreur est survenue lors de l'enregistrement.";
                                    break;
                                default:
                                    echo htmlspecialchars($_GET['error']);
                            }
                        ?>
                    </div>
                <?php endif; ?>

                <?php if(isset($_GET['success'])): ?>
                    <div class="alert alert-success">
                        L'adresse IP a été ajoutée avec succès.
                    </div>
                <?php endif; ?>
                
                <form id="addIPForm" method="POST" action="process/save_ip.php" class="add-form" onsubmit="return submitAddIPForm(event)">
                    <?php echo CSRFProtection::getHiddenField(); ?>
                    <div class="form-group">
                        <label for="ip_address">Adresse IP <span class="required">*</span></label>
                        <input type="text" id="ip_address" name="ip_address" required 
                               placeholder="Saisir l'adresse (n'importe quel format)">
                        <div class="error-message" id="ip_error"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="vlan">VLAN(s) <span class="required">*</span></label>
                        <input type="text" id="vlan" name="vlan" required 
                               placeholder="Ex: 10 ou 10,20,30 pour plusieurs VLANs" 
                               pattern="^[0-9]{1,4}(,[0-9]{1,4})*$"
                               title="Entrez un ou plusieurs numéros de VLAN séparés par des virgules (ex: 10,20,30). Chaque VLAN doit contenir entre 1 et 4 chiffres">
                        <div class="form-hint" style="font-size: 0.875rem; color: #666; margin-top: 0.25rem;">
                            Vous pouvez entrer plusieurs VLANs séparés par des virgules (ex: 10,20,30)
                        </div>
                        <div class="error-message" id="vlan_error"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="customer_name">Client (optionnel)</label>
                        <input type="text" id="customer_name" name="customer_name" 
                               placeholder="Nom du client" maxlength="100">
                        <div class="error-message" id="customer_error"></div>
                    </div>
                    
                    <?php
                    // Charger les villes depuis la base de données
                    $cities = [];
                    try {
                        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
                        $stmt = $pdo->query("SELECT name FROM cities ORDER BY name ASC");
                        $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Exception $e) {
                        $cities = ['Douala', 'Yaoundé']; // Fallback si la table n'existe pas
                    }
                    ?>
                    
                    <div class="form-group">
                        <label for="city">Ville</label>
                        <select id="city" name="city" class="form-control">
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>" <?php echo ($city === 'Douala') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($city); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-message" id="city_error"></div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn-cancel" onclick="closeAddForm()">Annuler</button>
                        <button type="submit" class="btn-submit">
                            <span class="btn-text">Enregistrer</span>
                            <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                        </button>
                    </div>
                    
                    <div id="formError" class="alert alert-error mt-3" style="display: none;"></div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>