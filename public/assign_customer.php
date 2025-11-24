<?php
session_start();
require_once '../config/db.php';
require_once '../src/ErrorHandler.php';
require_once '../src/CSRFProtection.php';

// Initialiser le gestionnaire d'erreurs
ErrorHandler::init();

// Vérifier la protection CSRF
CSRFProtection::checkRequest();

$ip = $_GET['ip'] ?? '';
$vlan = $_GET['vlan'] ?? '';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $city = trim($_POST['city'] ?? 'Douala');
    
    if (empty($customer_name)) {
        $error = "Le nom du client est obligatoire";
    } else {
        try {
            $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            require_once '../src/IPManager.php';
            $ipManager = new IPManager($pdo);
            
            // Trouver l'ID de l'adresse IP
            $stmt = $pdo->prepare("SELECT id FROM ip_addresses WHERE ip_address = :ip LIMIT 1");
            $stmt->execute([':ip' => $ip]);
            $address = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($address) {
                // Utiliser la méthode updateCustomer qui gère les relations
                $result = $ipManager->updateCustomer($address['id'], $customer_name, $city);
                
                if ($result['success']) {
                    header('Location: gestion_ip.php?success=ip_assigned');
                    exit;
                } else {
                    $error = "Erreur lors de l'attribution du client: " . implode(', ', $result['errors'] ?? []);
                }
            } else {
                $error = "Aucune adresse IP correspondante trouvée";
            }
            
        } catch(PDOException $e) {
            ErrorHandler::logError('Erreur dans assign_customer.php', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $error = "Erreur lors de l'attribution du client";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attribuer un client</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <nav class="nav-menu">
            <a href="index.php" class="nav-link">Accueil</a>
            <a href="gestion_ip.php" class="nav-link">Gestion</a>
            <a href="list.php" class="nav-link">Liste des IP</a>
        </nav>
        
        <div class="card">
            <h1>Attribuer un client</h1>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <div class="form-container">
                <form method="POST" class="form">
                    <?php echo CSRFProtection::getHiddenField(); ?>
                    
                    <div class="form-group">
                        <label for="ip">Adresse IP</label>
                        <input type="text" id="ip" value="<?php echo htmlspecialchars($ip); ?>" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label for="vlan">VLAN</label>
                        <input type="text" id="vlan" value="<?php echo htmlspecialchars($vlan); ?>" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label for="customer_name">Nom du Client *</label>
                        <input type="text" name="customer_name" id="customer_name" required 
                               value="<?php echo htmlspecialchars($_POST['customer_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="city">Ville</label>
                        <input type="text" name="city" id="city" 
                               value="<?php echo htmlspecialchars($_POST['city'] ?? 'Douala'); ?>">
                    </div>
                    
                    <div class="form-actions">
                        <a href="gestion_ip.php" class="btn btn-cancel">Annuler</a>
                        <button type="submit" class="btn btn-submit">
                            <i class="fas fa-save"></i> Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>