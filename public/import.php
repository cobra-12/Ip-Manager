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

$uploadMessage = '';
$uploadError = '';
$importResults = [];

// Traiter l'upload du fichier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    try {
        CSRFProtection::checkRequest();
        
        $file = $_FILES['excel_file'];
        
        // Vérifier les erreurs d'upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Erreur lors de l'upload du fichier");
        }
        
        // Vérifier l'extension du fichier
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['xlsx', 'xls', 'csv'];
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            throw new Exception("Format de fichier non supporté. Utilisez .xlsx, .xls ou .csv");
        }
        
        // Traiter le fichier selon son type
        if ($fileExtension === 'csv') {
            $importResults = importFromCSV($file['tmp_name']);
        } else {
            // Pour Excel, demander la conversion en CSV
            throw new Exception("Veuillez convertir votre fichier Excel en CSV avant l'import. Utilisez 'Enregistrer sous' > 'CSV (délimiteur : point-virgule)'");
        }
        
        $uploadMessage = "Importation réussie : {$importResults['success']} enregistrement(s) ajouté(s)";
        if ($importResults['errors'] > 0) {
            $uploadMessage .= ", {$importResults['errors']} erreur(s)";
        }
        if ($importResults['duplicates'] > 0) {
            $uploadMessage .= ", {$importResults['duplicates']} doublon(s) ignoré(s)";
        }
        
    } catch (Exception $e) {
        $uploadError = $e->getMessage();
        ErrorHandler::logError('Erreur lors de l\'importation', ['error' => $e->getMessage()]);
    }
}

/**
 * Détecte le délimiteur CSV utilisé dans le fichier
 */
function detectDelimiter($line) {
    // Nettoyer un éventuel BOM et espaces invisibles
    $line = preg_replace("/^[\xEF\xBB\xBF]+/", '', (string)$line);
    $line = str_replace(["\r", "\n"], '', $line);
    $delimiters = [';', ',', "\t"];
    $maxCount = 0;
    $detectedDelimiter = ';';
    
    foreach ($delimiters as $delimiter) {
        $count = substr_count($line, $delimiter);
        if ($count > $maxCount) {
            $maxCount = $count;
            $detectedDelimiter = $delimiter;
        }
    }
    
    return $detectedDelimiter;
}

/**
 * Détecte le mapping des colonnes en analysant les en-têtes
 */
function detectColumnMapping($headers) {
    // Normaliser BOM et espaces sur la première cellule
    if (!empty($headers)) {
        $headers[0] = preg_replace("/^[\xEF\xBB\xBF]+/", '', (string)$headers[0]);
    }
    $mapping = [
        'ip' => 0,
        'status' => -1,
        'vlan' => -1,
        'customer' => -1,
        'city' => -1
    ];
    
    // Analyser les en-têtes pour détecter les colonnes
    foreach ($headers as $index => $header) {
        // Remplacer les espaces insécables et normaliser
        $header = strtolower(trim(str_replace("\xC2\xA0", ' ', (string)$header)));
        
        // Détection IP
        if (strpos($header, 'ip') !== false || strpos($header, 'address') !== false) {
            $mapping['ip'] = $index;
        }
        // Détection STATUS
        elseif (strpos($header, 'status') !== false || strpos($header, 'statut') !== false || strpos($header, 'etat') !== false) {
            $mapping['status'] = $index;
        }
        // Détection VLAN
        elseif (strpos($header, 'vlan') !== false) {
            $mapping['vlan'] = $index;
        }
        // Détection CLIENT/CUSTOMER
        elseif (strpos($header, 'customer') !== false || strpos($header, 'client') !== false || strpos($header, 'name') !== false) {
            $mapping['customer'] = $index;
        }
        // Détection VILLE/CITY
        elseif (strpos($header, 'ville') !== false || strpos($header, 'city') !== false) {
            $mapping['city'] = $index;
        }
    }
    
    return $mapping;
}

/**
 * Mappe intelligemment les données d'une ligne en fonction du contenu
 * Détecte automatiquement si c'est STATUS (up/down) ou VLAN (nombres)
 */
function mapRowData($data, $mapping) {
    // Utiliser la liste des villes connues si disponible
    global $KNOWN_CITIES;
    // Normaliser chaque cellule: enlever espaces insécables et trim
    foreach ($data as $k => $v) {
        $data[$k] = trim(str_replace(["\xC2\xA0"], ' ', (string)$v));
    }

    $result = [
        'ip' => '',
        'status' => '',
        'vlan' => '',
        'customer' => '',
        'city' => 'Douala'
    ];
    
    $usedIndices = []; // Suivre les indices déjà utilisés
    
    // Étape 1 : Utiliser le mapping des en-têtes si disponible
    if ($mapping['ip'] >= 0 && isset($data[$mapping['ip']])) {
        $result['ip'] = trim($data[$mapping['ip']]);
        $usedIndices[] = $mapping['ip'];
    }
    
    if ($mapping['status'] >= 0 && isset($data[$mapping['status']])) {
        $result['status'] = trim($data[$mapping['status']]);
        $usedIndices[] = $mapping['status'];
    }
    
    if ($mapping['vlan'] >= 0 && isset($data[$mapping['vlan']])) {
        $result['vlan'] = trim($data[$mapping['vlan']]);
        $usedIndices[] = $mapping['vlan'];
    }
    
    if ($mapping['customer'] >= 0 && isset($data[$mapping['customer']])) {
        $result['customer'] = trim($data[$mapping['customer']]);
        $usedIndices[] = $mapping['customer'];
    }
    
    if ($mapping['city'] >= 0 && isset($data[$mapping['city']])) {
        $result['city'] = trim($data[$mapping['city']]);
        $usedIndices[] = $mapping['city'];
    }
    
    // Étape 2 : Détection intelligente pour les colonnes non mappées
    // D'abord, chercher STATUS et VILLE (les plus distinctifs)
    for ($i = 0; $i < count($data); $i++) {
        if (in_array($i, $usedIndices)) continue;
        
        $value = trim($data[$i]);
        $valueLower = strtolower($value);
        
        // Détecter STATUS (up ou down) - priorité haute
        if (($valueLower === 'up' || $valueLower === 'down') && empty($result['status'])) {
            $result['status'] = strtoupper($value);
            $usedIndices[] = $i;
        }
        // Détecter la VILLE si elle est dans la liste des villes connues
        elseif (!empty($KNOWN_CITIES) && in_array($valueLower, $KNOWN_CITIES, true) && $result['city'] === 'Douala') {
            $result['city'] = ucfirst($valueLower);
            $usedIndices[] = $i;
        }
    }
    
    // Ensuite, chercher IP et VLAN
    for ($i = 0; $i < count($data); $i++) {
        if (in_array($i, $usedIndices)) continue;
        
        $value = trim($data[$i]);
        
        // Détecter IP (format xxx.xxx.xxx.xxx)
        if (preg_match('/^(?:\d{1,3}\.){3}\d{1,3}$/', $value) && empty($result['ip'])) {
            $result['ip'] = $value;
            $usedIndices[] = $i;
        }
        // Détecter VLAN (nombres, peut contenir espaces, virgules, points ou texte comme "VLAN20")
        elseif (!empty($value) && empty($result['vlan'])) {
            // VLAN: toute valeur contenant au moins un chiffre et qui n'est pas une IP ni un status ni une ville connue
            $isIp = preg_match('/^(?:\d{1,3}\.){3}\d{1,3}$/', $value);
            $isStatus = ($valueLower === 'up' || $valueLower === 'down');
            $isCity = (!empty($KNOWN_CITIES) && in_array($valueLower, $KNOWN_CITIES, true));
            if (!$isIp && !$isStatus && !$isCity && preg_match('/\d/', $value)) {
                $result['vlan'] = $value;
                $usedIndices[] = $i;
            }
        }
    }
    
    // Enfin, assigner le reste au client (s'il reste des colonnes)
    for ($i = 0; $i < count($data); $i++) {
        if (in_array($i, $usedIndices)) continue;
        
        $value = trim($data[$i]);
        $valueLower = strtolower($value);
        // Ignorer si c'est un statut, une ville connue, ou une valeur typique de VLAN (chiffrée)
        $isStatus = ($valueLower === 'up' || $valueLower === 'down');
        $isCity = (!empty($KNOWN_CITIES) && in_array($valueLower, $KNOWN_CITIES, true));
        $looksLikeVLAN = preg_match('/\d/', $value);
        if (!empty($value) && empty($result['customer']) && !$isStatus && !$isCity && !$looksLikeVLAN) {
            $result['customer'] = $value;
            $usedIndices[] = $i;
            break; // Ne prendre qu'une seule colonne pour le client
        }
    }

    // Valeur par défaut du statut si non détectée
    if (empty($result['status'])) {
        $result['status'] = 'DOWN';
    }
    
    return $result;
}

/**
 * Import depuis un fichier CSV
 */
function importFromCSV($filePath) {
    $results = ['success' => 0, 'errors' => 0, 'duplicates' => 0, 'details' => []];
    // Charger la liste des villes connues (en minuscules) pour la détection
    global $KNOWN_CITIES;
    $KNOWN_CITIES = [];
    
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ipManager = new IPManager($pdo);
    try {
        $stmtCities = $pdo->query("SELECT name FROM cities");
        $KNOWN_CITIES = array_map('strtolower', $stmtCities->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        // Fallback minimal si la table n'existe pas
        $KNOWN_CITIES = ['douala','yaounde','yaoundé'];
    }
    
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        throw new Exception("Impossible de lire le fichier");
    }
    
    // Détecter le délimiteur (sanitiser la première ligne)
    $firstLine = fgets($handle);
    $delimiter = detectDelimiter($firstLine);
    rewind($handle);
    
    // Lire la première ligne (en-têtes)
    $headers = fgetcsv($handle, 1000, $delimiter);
    if ($headers === false) {
        // Fichier vide
        fclose($handle);
        throw new Exception("Fichier CSV vide ou illisible");
    }
    $columnMapping = detectColumnMapping($headers);
    
    $lineNumber = 1;
    
    while (($data = fgetcsv($handle, 1000, $delimiter)) !== false) {
        $lineNumber++;
        
        // Ignorer les lignes vides
        if (empty($data) || (count($data) === 1 && empty($data[0]))) {
            continue;
        }
        
        // Mapper intelligemment les données
        $mappedData = mapRowData($data, $columnMapping);

        // Fallback: si aucune IP détectée par le mapping, scanner la ligne pour trouver une IP
        if (empty(trim($mappedData['ip'] ?? ''))) {
            foreach ($data as $cell) {
                $cell = trim((string)$cell);
                if (preg_match('/^(?:\d{1,3}\.){3}\d{1,3}$/', $cell)) {
                    $mappedData['ip'] = $cell;
                    break;
                }
            }
        }
        // Fallback: si VLAN vide, prendre la première valeur contenant des chiffres qui n'est pas IP/STATUS/VILLE
        if (empty(trim($mappedData['vlan'] ?? ''))) {
            foreach ($data as $cell) {
                $cellTrim = trim((string)$cell);
                $low = strtolower($cellTrim);
                $isIp = preg_match('/^(?:\d{1,3}\.){3}\d{1,3}$/', $cellTrim);
                $isStatus = ($low === 'up' || $low === 'down');
                $isCity = (!empty($KNOWN_CITIES) && in_array($low, $KNOWN_CITIES));
                if (!$isIp && !$isStatus && !$isCity && preg_match('/\d/', $cellTrim)) {
                    $mappedData['vlan'] = $cellTrim;
                    break;
                }
            }
        }
        
        $ip = trim(str_replace(["\xC2\xA0"], ' ', $mappedData['ip'] ?? ''));
        $statusInput = strtoupper(trim($mappedData['status'] ?? ''));
        $vlanInput = trim(str_replace(["\xC2\xA0"], ' ', $mappedData['vlan'] ?? ''));
        $customerNameInput = trim(str_replace(["\xC2\xA0"], ' ', $mappedData['customer'] ?? ''));
        $city = trim(str_replace(["\xC2\xA0"], ' ', $mappedData['city'] ?? 'Douala'));
        
        // Nettoyer et valider le statut
        $status = ($statusInput === 'UP') ? 'UP' : 'DOWN';
        
        // Validation basique - IP obligatoire, VLAN optionnel (peut être ajouté plus tard)
        if (empty($ip)) {
            $results['errors']++;
            $results['details'][] = "Ligne $lineNumber : IP manquante";
            continue;
        }
        
        // Valider le format de l'IP
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $results['errors']++;
            $results['details'][] = "Ligne $lineNumber : Format IP invalide ($ip)";
            continue;
        }
        
        // Le VLAN est maintenant optionnel - une IP peut exister sans VLAN et le VLAN peut être ajouté plus tard
        
        // Vérifier si l'IP existe déjà
        if ($ipManager->ipExists($ip)) {
            $results['duplicates']++;
            $results['details'][] = "Ligne $lineNumber : IP $ip existe déjà";
            continue;
        }
        
        // Traiter les VLANs : permettre plusieurs VLANs séparés par virgule
        $vlans = [];
        if (!empty($vlanInput)) {
            // Séparer par virgule et nettoyer
            $vlanArray = array_map('trim', explode(',', $vlanInput));
            $vlans = array_filter($vlanArray, function($v) { return !empty($v); });
        }
        
        // Traiter les clients : permettre plusieurs clients séparés par virgule
        $customers = [];
        if (!empty($customerNameInput)) {
            // Séparer par virgule et nettoyer
            $customerArray = array_map('trim', explode(',', $customerNameInput));
            $customers = array_filter($customerArray, function($c) { return !empty($c); });
        }
        
        // Ajouter l'IP avec les nouvelles relations many-to-many
        // addIP() déterminera automatiquement le statut : UP si customer existe, DOWN sinon
        try {
            // Passer les VLANs et clients comme arrays pour gérer les relations many-to-many
            // Permettre plusieurs VLANs séparés par virgule et plusieurs clients séparés par virgule
            $vlanParam = !empty($vlans) ? $vlans : ($vlanInput ?: '');
            $customerParam = !empty($customers) ? $customers : ($customerNameInput ?: '');
            
            $result = $ipManager->addIP($ip, $vlanParam, $customerParam, $city);
            
            if ($result['success']) {
                // Si le CSV indique explicitement UP mais aucun client n'a été fourni,
                // forcer le statut à UP (cas où l'IP est utilisée mais pas encore assignée à un client)
                if ($status === 'UP' && empty($customers)) {
                    try {
                        $addressId = $result['id'];
                        $stmt = $pdo->prepare("UPDATE ip_addresses SET status = 'UP' WHERE id = ?");
                        $stmt->execute([$addressId]);
                    } catch (Exception $e) {
                        // Ignorer l'erreur, le statut par défaut est OK
                        error_log("Erreur lors de la mise à jour du statut pour IP $ip : " . $e->getMessage());
                    }
                }
                // Si le CSV indique DOWN mais qu'un client a été fourni,
                // le statut sera automatiquement UP par addIP(), ce qui est correct
                
                $results['success']++;
            } else {
                $results['errors']++;
                $results['details'][] = "Ligne $lineNumber : " . implode(', ', $result['errors'] ?? ['Erreur inconnue']);
            }
        } catch (Exception $e) {
            $results['errors']++;
            $results['details'][] = "Ligne $lineNumber : " . $e->getMessage();
        }
    }
    
    fclose($handle);
    return $results;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Excel - IP Manager</title>
    <link rel="icon" type="image/png" href="assets/image/swecom.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .import-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
        }
        
        .upload-area {
            border: 2px dashed #ccc;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            background: #f9f9f9;
            margin: 20px 0;
            transition: all 0.3s;
        }
        
        .upload-area:hover {
            border-color: #007bff;
            background: #f0f8ff;
        }
        
        .upload-area i {
            font-size: 48px;
            color: #007bff;
            margin-bottom: 20px;
        }
        
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        
        .file-input-wrapper input[type=file] {
            position: absolute;
            left: -9999px;
        }
        
        .file-input-wrapper label {
            display: inline-block;
            padding: 12px 24px;
            background-color: #007bff;
            color: white;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .file-input-wrapper label:hover {
            background-color: #0056b3;
        }
        
        .instructions {
            background: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .instructions h3 {
            margin-top: 0;
            color: #007bff;
        }
        
        .instructions ol {
            margin: 10px 0;
            padding-left: 25px;
        }
        
        .instructions li {
            margin: 8px 0;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .result-details {
            max-height: 300px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-top: 10px;
        }
        
        .result-details ul {
            list-style: none;
            padding: 0;
        }
        
        .result-details li {
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        
        #fileName {
            margin-top: 15px;
            font-weight: bold;
            color: #28a745;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="nav-menu">
            <a href="index.php" class="nav-link">Accueil</a>
            <a href="gestion_ip.php" class="nav-link">Gestion</a>
            <a href="list.php" class="nav-link">Liste des IP</a>
            <a href="import.php" class="nav-link active">Import Excel</a>
        </nav>
        
        <div class="import-container">
            <div class="card">
                <h1><i class="fas fa-file-excel"></i> Importation de fichier Excel</h1>
                
                <?php if (!empty($uploadMessage)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($uploadMessage); ?>
                        <?php if (!empty($importResults['details'])): ?>
                            <div class="result-details">
                                <strong>Détails :</strong>
                                <ul>
                                    <?php foreach ($importResults['details'] as $detail): ?>
                                        <li><?php echo htmlspecialchars($detail); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($uploadError)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($uploadError); ?>
                    </div>
                <?php endif; ?>
                
                <div class="instructions">
                    <h3><i class="fas fa-info-circle"></i> Instructions</h3>
                    <ol>
                        <li>Préparez votre fichier Excel avec les colonnes suivantes (l'ordre n'a pas d'importance) :
                            <ul>
                                <li><strong>IP ADDRESS :</strong> Adresse IP (obligatoire)</li>
                                <li><strong>STATUS :</strong> État (up ou down) - détecté automatiquement</li>
                                <li><strong>VLAN :</strong> Numéro(s) VLAN (optionnel, peut être plusieurs séparés par virgule) - détecté automatiquement</li>
                                <li><strong>CUSTOMER NAMES :</strong> Nom(s) du/des client(s) (optionnel, peut être plusieurs séparés par virgule)</li>
                                <li><strong>VILLE :</strong> Ville (parmi toutes les villes du Cameroun)</li>
                            </ul>
                        </li>
                        <li>Enregistrez votre fichier Excel en format <strong>CSV (délimiteur : point-virgule ou virgule)</strong></li>
                        <li>Cliquez sur "Choisir un fichier" et sélectionnez votre fichier CSV</li>
                        <li>Cliquez sur "Importer" pour lancer l'importation</li>
                    </ol>
                    <p><strong>✨ Intelligence automatique :</strong></p>
                    <ul>
                        <li>Le programme détecte automatiquement l'ordre des colonnes en lisant les en-têtes</li>
                        <li>Il fait la différence entre STATUS (up/down) et VLAN (nombres)</li>
                        <li>Le délimiteur (point-virgule ou virgule) est détecté automatiquement</li>
                        <li>Les adresses IP existantes sont ignorées pour éviter les doublons</li>
                    </ul>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <?php echo CSRFProtection::getHiddenField(); ?>
                    
                    <div class="upload-area">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <h3>Sélectionnez votre fichier CSV</h3>
                        <p>Formats acceptés : .csv (recommandé), .xlsx, .xls</p>
                        
                        <div class="file-input-wrapper">
                            <input type="file" name="excel_file" id="excel_file" accept=".csv,.xlsx,.xls" required onchange="showFileName(this)">
                            <label for="excel_file">
                                <i class="fas fa-folder-open"></i> Choisir un fichier
                            </label>
                        </div>
                        
                        <div id="fileName"></div>
                    </div>
                    
                    <div style="text-align: center; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary" style="padding: 12px 30px; font-size: 16px;">
                            <i class="fas fa-upload"></i> Importer les données
                        </button>
                    </div>
                </form>
                
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <h3>Besoin d'aide ?</h3>
                    <p>Téléchargez un modèle de fichier CSV pour vous guider :</p>
                    <a href="download_template.php" class="btn btn-secondary">
                        <i class="fas fa-download"></i> Télécharger le modèle CSV
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function showFileName(input) {
        const fileNameDisplay = document.getElementById('fileName');
        if (input.files && input.files[0]) {
            fileNameDisplay.textContent = 'Fichier sélectionné : ' + input.files[0].name;
        }
    }
    </script>
</body>
</html>
