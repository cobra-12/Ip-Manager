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

// Récupérer les adresses IP récemment ajoutées (dernières 24h)
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $ipManager = new IPManager($pdo);
    // Charger les villes disponibles
    try {
        $cities = [];
        $stmtCities = $pdo->query("SELECT name FROM cities ORDER BY name ASC");
        $cities = $stmtCities->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $cities = ['Douala','Yaoundé']; // fallback minimal si la table n'existe pas
    }
    
    // Paramètres de filtrage
    $selectedCity = $_GET['city'] ?? '';
    $period = $_GET['period'] ?? 'all';
    
    // Récupérer le numéro de page actuel (par défaut 1)
    $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($currentPage < 1) $currentPage = 1;
    
    // Restaurer la pagination existante (logique locale avec comptage + GROUP BY)
    $params = [];
    $whereConditions = [];
    
    // Statut DOWN uniquement sur la page gestion
    $whereConditions[] = "(ipa.status = 'DOWN' OR ipa.status IS NULL OR ipa.status = '')";
    
    // Filtre ville
    if (!empty($selectedCity)) {
        $whereConditions[] = "ipa.city = ?";
        $params[] = $selectedCity;
    }
    // Filtre recherche (IP / relations)
    if (!empty($_GET['search'])) {
        $search = '%' . $_GET['search'] . '%';
        $whereConditions[] = "(ipa.ip_address LIKE ? 
            OR EXISTS (SELECT 1 FROM user_addresses ua2 JOIN users u2 ON ua2.user_id = u2.id WHERE ua2.address_id = ipa.id AND u2.name LIKE ?)
            OR EXISTS (SELECT 1 FROM address_vlans av2 JOIN vlans v2 ON av2.vlan_id = v2.id WHERE av2.address_id = ipa.id AND v2.vlan_number LIKE ?))";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    // Compter le total après GROUP BY
    $countSql = "SELECT COUNT(*) as total FROM (
        SELECT ipa.id
        FROM ip_addresses ipa
        LEFT JOIN user_addresses ua ON ipa.id = ua.address_id
        LEFT JOIN users u ON ua.user_id = u.id
        LEFT JOIN address_vlans av ON ipa.id = av.address_id
        LEFT JOIN vlans v ON av.vlan_id = v.id
        $whereClause
        GROUP BY ipa.id
    ) t";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalItems = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    $totalPages = max(1, (int)ceil($totalItems / IPManager::ITEMS_PER_PAGE));
    if ($currentPage > $totalPages) $currentPage = $totalPages;
    $offset = ($currentPage - 1) * IPManager::ITEMS_PER_PAGE;
    
    // Sélection paginée
    $sql = "SELECT 
                ipa.*,
                GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as users,
                GROUP_CONCAT(DISTINCT v.vlan_number ORDER BY v.vlan_number SEPARATOR ', ') as vlans
            FROM ip_addresses ipa
            LEFT JOIN user_addresses ua ON ipa.id = ua.address_id
            LEFT JOIN users u ON ua.user_id = u.id
            LEFT JOIN address_vlans av ON ipa.id = av.address_id
            LEFT JOIN vlans v ON av.vlan_id = v.id
            $whereClause
            GROUP BY ipa.id
            ORDER BY COALESCE(ipa.updated_at, ipa.created_at) DESC, ipa.created_at DESC
            LIMIT " . intval($offset) . ", " . IPManager::ITEMS_PER_PAGE;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recentIPs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pagination = [
        'total' => $totalItems,
        'per_page' => IPManager::ITEMS_PER_PAGE,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'from' => $totalItems ? ($offset + 1) : 0,
        'to' => min($offset + IPManager::ITEMS_PER_PAGE, $totalItems)
    ];
    
} catch(Exception $e) {
    $errorMsg = 'Erreur dans gestion_ip.php: ' . $e->getMessage();
    error_log($errorMsg);
    error_log("Trace: " . $e->getTraceAsString());
    ErrorHandler::logError('Erreur dans gestion_ip.php', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    $recentIPs = [];
    $pagination = [
        'total' => 0,
        'per_page' => IPManager::ITEMS_PER_PAGE,
        'current_page' => 1,
        'total_pages' => 1,
        'from' => 0,
        'to' => 0
    ];
    // Afficher l'erreur à l'utilisateur pour le débogage
    $debugError = $errorMsg;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Adresses IP</title>
    <link rel="icon" type="image/png" href="assets/image/OIP.webp">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
</head>
<body>
    <div class="container">
        <nav class="nav-menu">
            <a href="index.php" class="nav-link">Accueil</a>
            <a href="gestion_.php" class="nav-link active">Gestion</a>
            <a href="list.php" class="nav-link">Liste des IP</a>
</a>
        </nav>
        
        <div class="card">
            <h1>Gestion des Adresses IP</h1>
            
            <?php
            // Récupérer les statistiques globales
            $stats = $ipManager->getStats();
            $statsData = $stats['success'] ? $stats['data'] : null;
            ?>
            
            <?php if (isset($statsData) && $statsData): ?>
            <div class="stats-container" style="margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-value"><?= $statsData['total'] ?></div>
                    <div class="stat-label">Total IP</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value text-success"><?= $statsData['used'] ?></div>
                    <div class="stat-label">IP Utilisées (<?= $statsData['used_percentage'] ?>%)</div>
                    <div class="stat-progress">
                        <div class="progress-bar" style="width: <?= $statsData['used_percentage'] ?>%;"></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-value text-info"><?= $statsData['available'] ?></div>
                    <div class="stat-label">IP Disponibles (<?= $statsData['available_percentage'] ?>%)</div>
                    <div class="stat-progress">
                        <div class="progress-bar bg-info" style="width: <?= $statsData['available_percentage'] ?>%;"></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="filters">
                <div class="filter-group">
                    <label for="period">Période :</label>
                    <select id="period" name="period" onchange="applyFilters()">
                        <option value="1" <?= $period === '1' ? 'selected' : '' ?>>Aujourd'hui</option>
                        <option value="7" <?= $period === '7' ? 'selected' : '' ?>>7 derniers jours</option>
                        <option value="30" <?= $period === '30' ? 'selected' : '' ?>>30 derniers jours</option>
                        <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Toutes les périodes</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="cityFilter">Ville :</label>
                    <select id="cityFilter" name="city" class="form-control" onchange="applyFilters()" style="min-width: 200px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">Toutes les villes</option>
                        <?php foreach ($cities as $cityName): ?>
                            <option value="<?php echo htmlspecialchars($cityName); ?>" <?php echo ($selectedCity === $cityName) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cityName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-container" style="margin-left: auto; max-width: 300px;">
                    <input type="text" id="searchInput" placeholder="Rechercher une IP, VLAN, client..." 
                           value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" 
                           class="search-input"
                           onkeyup="performSearch(this.value)">
                    <i class="fas fa-search search-icon"></i>
                </div>
            </div>
            
            <?php if (isset($debugError)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;">
                    <strong>Erreur de débogage :</strong> <?php echo htmlspecialchars($debugError); ?>
                </div>
            <?php endif; ?>
            
            <div class="table-container" style="margin-bottom: 2rem;">
                <table>
                    <thead>
                        <tr>
                            <th>Adresse IP</th>
                            <th>VLAN</th>
                            <th>Client</th>
                            <th>Ville</th>
                            <th>Statut</th>
                            <th>Ajouté le</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    // Debug: Afficher le contenu de $recentIPs
                    error_log("Nombre d'IPs récupérées: " . count($recentIPs));
                    if (!empty($recentIPs)) {
                        error_log("Première IP: " . print_r($recentIPs[0] ?? 'Aucune donnée', true));
                    }
                    ?>
                    <?php 
                    // Debug: Afficher le contenu de $recentIPs
                    error_log("Affichage du tableau - Nombre d'IPs: " . count($recentIPs));
                    if (!empty($recentIPs)) {
                        error_log("Données des IPs: " . print_r($recentIPs, true));
                    } else {
                        error_log("Aucune IP à afficher. Vérifiez la requête et les filtres.");
                    }
                    ?>
                    <?php if (empty($recentIPs)): ?>
                        <tr>
                            <td colspan="7" class="text-center">
                                Aucune adresse IP disponible.
                                <?php 
                                // Afficher les erreurs SQL s'il y en a
                                if (isset($pdo) && $pdo->errorInfo()[0] !== '00000') {
                                    echo "<br>Erreur SQL: " . htmlspecialchars(print_r($pdo->errorInfo(), true));
                                }
                                ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($recentIPs as $row): 
                            // Récupérer les utilisateurs et VLANs depuis les relations
                            $users = !empty($row['users']) ? htmlspecialchars($row['users']) : '';
                            $vlans = !empty($row['vlans']) ? htmlspecialchars($row['vlans']) : '';
                            $hasUsers = !empty($users);
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['ip_address']); ?></td>
                                <td><?php echo $vlans ?: '<span class="text-muted">N/A</span>'; ?></td>
                                <td><?php echo $users ?: '<span class="text-muted">Non attribué</span>'; ?></td>
                                <td>
                                    <span class="city-badge city-<?php echo strtolower($row['city']); ?>">
                                        <?php echo htmlspecialchars($row['city']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-<?php echo strtolower($row['status']); ?>">
                                        <?php echo $row['status']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></td>
                                <td class="actions-column">
                                    <?php if (!$hasUsers): ?>
                                        <!-- Bouton pour attribuer un client -->
                                        <button class="btn-icon use" onclick="useIP(<?php echo $row['id']; ?>)" title="Attribuer un client">
                                            <i class="fas fa-user-plus"></i>
                                        </button>
                                    <?php else: ?>
                                        <!-- Bouton pour modifier le client -->
                                        <button class="btn-icon edit" onclick='showEditCustomer(<?php echo $row['id']; ?>, "<?php echo addslashes($users); ?>", "<?php echo addslashes($row['city']); ?>")' title="Modifier le client">
                                            <i class="fas fa-user-edit"></i>
                                        </button>
                                    <?php endif; ?>
                                    <!-- Bouton pour modifier l'IP/VLAN -->
                                    <button class="btn-icon edit" onclick='showEditIP(<?php echo $row['id']; ?>, "<?php echo $row['ip_address']; ?>", "<?php echo addslashes($vlans); ?>")' title="Modifier IP/VLAN">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <!-- Bouton pour supprimer -->
                                    <form method="POST" action="delete_ip.php" style="display: inline;">
                                        <?php echo CSRFProtection::getHiddenField(); ?>
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <button type="button" class="btn-icon delete" onclick="confirmDelete(this.form)" title="Supprimer">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if (isset($pagination) && $pagination['total_pages'] > 0): 
                $currentPage = $pagination['current_page'];
                $totalPages = $pagination['total_pages'];
                
                // Construire les paramètres d'URL pour préserver les filtres
                $urlParams = [];
                if (!empty($period) && $period !== 'all') {
                    $urlParams[] = 'period=' . urlencode($period);
                }
                if (!empty($selectedCity)) {
                    $urlParams[] = 'city=' . urlencode($selectedCity);
                }
                if (!empty($_GET['search'])) {
                    $urlParams[] = 'search=' . urlencode($_GET['search']);
                }
                $allParams = !empty($urlParams) ? '&' . implode('&', $urlParams) : '';
            ?>
            <div class="pagination-container" style="margin-top: 20px;">
                <?php if ($pagination['total'] > 0): ?>
                <?php endif; ?>
                <?php if ($totalPages > 1): 
                    // Schéma identique à list.php
                    $searchParam = !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
                    $cityParam = !empty($selectedCity) ? '&city=' . urlencode($selectedCity) : '';
                    $allParams = $searchParam . $cityParam;
                ?>
                <div class="pagination">
                    <!-- Flèche précédente -->
                    <a href="?page=<?= max(1, $currentPage - 1) . $allParams ?>" class="pagination-link prev <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <i class="fas fa-chevron-left"></i>
                        <span>prev</span>
                    </a>
                    
                    <!-- Première page -->
                    <a href="?page=1<?= $allParams ?>" class="pagination-link <?= $currentPage == 1 ? 'active' : '' ?>">1</a>
                    
                    <?php if ($currentPage > 3): ?>
                        <span class="pagination-ellipsis">...</span>
                    <?php endif; ?>
                    
                    <!-- Pages autour de la page courante -->
                    <?php 
                    $startPage = max(2, $currentPage - 1);
                    $endPage = min($totalPages - 1, $currentPage + 1);
                    
                    // Ajuster si on est proche du début ou de la fin
                    if ($currentPage <= 3) {
                        $endPage = min(4, $totalPages - 1);
                    } elseif ($currentPage >= $totalPages - 2) {
                        $startPage = max($totalPages - 3, 2);
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++): 
                        if ($i > 1 && $i < $totalPages):
                    ?>
                        <a href="?page=<?= $i . $allParams ?>" class="pagination-link <?= $i == $currentPage ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php 
                        endif;
                    endfor; 
                    ?>
                    
                    <!-- Points de suspension si nécessaire -->
                    <?php if ($currentPage < $totalPages - 2): ?>
                        <span class="pagination-ellipsis">...</span>
                    <?php endif; ?>
                    
                    <!-- Dernière page si différente de la première -->
                    <?php if ($totalPages > 1): ?>
                        <a href="?page=<?= $totalPages . $allParams ?>" class="pagination-link <?= $currentPage == $totalPages ? 'active' : '' ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>
                    
                    <!-- Flèche suivante -->
                    <a href="?page=<?= min($totalPages, $currentPage + 1) . $allParams ?>" class="pagination-link next <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <span>next</span>
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Boutons d'action -->
            <div class="action-buttons" style="margin: 20px 0; display: flex; gap: 10px;">
                <a href="import.php" class="btn btn-import">
                    <i class="fas fa-upload"></i> Importer des données
                </a>
                <form method="POST" action="export.php" style="display: inline;">
                    <?php echo CSRFProtection::getHiddenField(); ?>
                    <input type="hidden" name="action" value="export">
                    <button type="submit" class="btn btn-export">
                        <i class="fas fa-download"></i> Exporter CSV
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal pour modifier l'IP et le VLAN -->
    <div id="editIPModal" class="overlay" style="display: none;">
        <div class="modal">
            <h2>Modifier l'adresse IP et le VLAN</h2>
            <form id="editIPForm" method="POST" action="update_ip_vlan.php" onsubmit="return false;">
                <?php echo CSRFProtection::getHiddenField(); ?>
                <input type="hidden" id="editIPId" name="id">
                <div class="form-group">
                    <label for="editIPAddress">Adresse IP</label>
                    <input type="text" id="editIPAddress" name="ip_address" required 
                           placeholder="Saisir l'adresse (n'importe quel format)">
                </div>
                <div class="form-group">
                    <label for="editVLAN">VLAN</label>
                    <input type="text" id="editVLAN" name="vlan" required 
                           pattern="^[0-9]{1,4}(,[0-9]{1,4})*$"
                           title="Entrez un ou plusieurs numéros de VLAN séparés par des virgules (ex: 10,20,30)">
                    <div class="form-hint">Séparez les numéros de VLAN par des virgules si nécessaire</div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-submit">Enregistrer</button>
                    <button type="button" class="btn-cancel" onclick="closeModal('editIPModal')">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal pour modifier le client -->
    <div id="editCustomerModal" class="overlay" style="display: none;">
        <div class="modal">
            <h2>Modifier le client</h2>
            <form id="editCustomerForm" method="POST" action="update_customer.php">
                <?php echo CSRFProtection::getHiddenField(); ?>
                <input type="hidden" id="editCustomerId" name="id">
                <div class="form-group">
                    <label for="editCustomerName">Nom du client</label>
                    <input type="text" id="editCustomerName" name="customer_name" required>
                </div>
                <div class="form-group">
                    <label for="editCustomerCity">Ville</label>
                    <select id="editCustomerCity" name="city" class="form-control" required>
                        <?php foreach ($cities as $cityName): ?>
                            <option value="<?php echo htmlspecialchars($cityName); ?>"><?php echo htmlspecialchars($cityName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-submit">Enregistrer</button>
                    <button type="button" class="btn-cancel" onclick="closeModal('editCustomerModal')">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal pour utiliser l'adresse IP -->
    <div id="useIPModal" class="overlay" style="display: none;">
        <div class="modal">
            <h2>Attribuer cette adresse IP</h2>
            <form id="useIPForm" method="POST" action="use_ip.php">
                <?php echo CSRFProtection::getHiddenField(); ?>
                <input type="hidden" id="useIPId" name="id">
                <div class="form-group">
                    <label for="customerName">Nom du client</label>
                    <input type="text" id="customerName" name="customer_name" required>
                </div>
                <div class="form-group">
                    <label for="city">Ville</label>
                    <select id="city" name="city" class="form-control" required>
                        <?php foreach ($cities as $cityName): ?>
                            <option value="<?php echo htmlspecialchars($cityName); ?>"><?php echo htmlspecialchars($cityName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-submit">Confirmer</button>
                    <button type="button" class="btn-cancel" onclick="closeModal('useIPModal')">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Fonction pour effectuer une recherche en temps réel
    function performSearch(query) {
        // Annuler le délai précédent s'il existe
        clearTimeout(window.searchTimeout);
        
        // Si le champ est vide, recharger la page sans paramètre de recherche
        if (query.trim() === '') {
            const url = new URL(window.location);
            url.searchParams.delete('search');
            window.location.href = url.toString();
            return;
        }
        
        // Démarrer un nouveau délai avant d'exécuter la recherche
        window.searchTimeout = setTimeout(() => {
            updateSearchResults(query);
        }, 300); // Délai de 300ms après la dernière frappe
    }
    
    // Fonction pour mettre à jour les résultats de recherche via AJAX
    function updateSearchResults(query) {
        const tableContainer = document.querySelector('.table-container');
        const paginationContainer = document.querySelector('.pagination-container');
        const period = document.getElementById('period').value;
        const city = document.getElementById('cityFilter').value;
        
        if (tableContainer) {
            tableContainer.innerHTML = '<div class="loading">Recherche en cours...</div>';
        }
        
        // Récupérer les paramètres de l'URL actuelle
        const urlParams = new URLSearchParams(window.location.search);
        const currentPage = urlParams.get('page') || 1;
        
        // Construire l'URL de la requête
        const url = `gestion_ajax.php?search=${encodeURIComponent(query)}&page=${currentPage}&period=${encodeURIComponent(period)}&city=${encodeURIComponent(city)}`;
        
        // Effectuer une requête AJAX pour récupérer les résultats mis à jour
        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            cache: 'no-store'
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erreur HTTP! Statut: ${response.status}`);
            }
            return response.json(); // Parser la réponse en JSON
        })
        .then(data => {
            if (!data || !data.success) {
                throw new Error('Réponse du serveur invalide');
            }
            
            // Mettre à jour le contenu du conteneur de tableau
            if (tableContainer && data.html) {
                tableContainer.innerHTML = data.html;
            }
            
            // Mettre à jour la pagination si nécessaire
            updatePagination(data.pagination);
            
            // Réattacher les gestionnaires d'événements si nécessaire
            if (typeof attachActionHandlers === 'function') {
                attachActionHandlers();
            }
        })
        .catch(error => {
            console.error('Erreur lors de la recherche :', error);
            
            if (tableContainer) {
                tableContainer.innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        Une erreur est survenue lors de la recherche. Réessayez ou rechargez la page.
                    </div>`;
            }
            
            // En cas d'erreur, recharger la page normalement après un délai
            setTimeout(() => {
                window.location.href = `gestion_ip.php?search=${encodeURIComponent(query)}`;
            }, 3000);
        });
    }
    
    // Fonction pour mettre à jour la pagination
    function updatePagination(pagination) {
        const paginationContainer = document.querySelector('.pagination');
        if (!paginationContainer || !pagination) return;
        
        const { current_page, total_pages } = pagination;
        let paginationHTML = '';
        
        // Bouton Précédent
        if (current_page > 1) {
            paginationHTML += `
                <a href="?page=${current_page - 1}" class="pagination-link prev">
                    <i class="fas fa-chevron-left"></i>
                </a>`;
        } else {
            paginationHTML += `
                <span class="pagination-link prev disabled">
                    <i class="fas fa-chevron-left"></i>
                </span>`;
        }
        
        // Numéros de page
        for (let i = 1; i <= total_pages; i++) {
            if (i === current_page) {
                paginationHTML += `
                    <span class="pagination-link active">${i}</span>`;
            } else {
                paginationHTML += `
                    <a href="?page=${i}" class="pagination-link">${i}</a>`;
            }
        }
        
        // Bouton Suivant
        if (current_page < total_pages) {
            paginationHTML += `
                <a href="?page=${current_page + 1}" class="pagination-link next">
                    <i class="fas fa-chevron-right"></i>
                </a>`;
        } else {
            paginationHTML += `
                <span class="pagination-link next disabled">
                    <i class="fas fa-chevron-right"></i>
                </span>`;
        }
        
        paginationContainer.innerHTML = paginationHTML;
    }
    
    // Fonction pour afficher le modal d'édition d'IP/VLAN
    function showEditIP(id, ip, vlan) {
        console.log('showEditIP appelé avec:', id, ip, vlan);
        try {
            const modal = document.getElementById('editIPModal');
            const idInput = document.getElementById('editIPId');
            const ipInput = document.getElementById('editIPAddress');
            const vlanInput = document.getElementById('editVLAN');
            
            if (!modal || !idInput || !ipInput || !vlanInput) {
                console.error('Éléments du modal non trouvés');
                alert('Erreur: Les éléments du formulaire de modification ne sont pas disponibles.');
                return;
            }
            
            idInput.value = id;
            ipInput.value = ip || '';
            // Si vlan est vide ou undefined, utiliser une chaîne vide
            vlanInput.value = vlan || '';
            
            // Réinitialiser les messages d'erreur
            const errorDiv = modal.querySelector('.error-message');
            if (errorDiv) errorDiv.textContent = '';
            if (errorDiv) errorDiv.style.display = 'none';
            
            modal.style.display = 'flex';
            console.log('Modal IP/VLAN ouvert avec succès');
        } catch(e) {
            console.error('Erreur dans showEditIP:', e);
            alert('Erreur lors de l\'ouverture du modal: ' + e.message);
        }
    }
    
    // Fonction pour afficher le modal d'édition du client
    function showEditCustomer(id, customerName, city) {
        console.log('showEditCustomer appelé avec:', id, customerName, city);
        try {
            document.getElementById('editCustomerId').value = id;
            document.getElementById('editCustomerName').value = customerName;
            document.getElementById('editCustomerCity').value = city;
            document.getElementById('editCustomerModal').style.display = 'flex';
            console.log('Modal client ouvert');
        } catch(e) {
            console.error('Erreur dans showEditCustomer:', e);
            alert('Erreur lors de l\'ouverture du modal: ' + e.message);
        }
    }
    
    // Fonction pour attribuer un client à une IP
    function useIP(id) {
        console.log('useIP appelé avec:', id);
        try {
            document.getElementById('useIPId').value = id;
            document.getElementById('useIPModal').style.display = 'flex';
            console.log('Modal attribution ouvert');
        } catch(e) {
            console.error('Erreur dans useIP:', e);
            alert('Erreur lors de l\'ouverture du modal: ' + e.message);
        }
    }
    
    // Fonction pour fermer un modal
    function closeModal(modalId) {
        console.log('Fermeture du modal:', modalId);
        document.getElementById(modalId).style.display = 'none';
    }
    
    // Gestion de la soumission du formulaire de modification IP/VLAN
    document.getElementById('editIPForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const form = e.target;
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');
        const originalButtonText = submitButton.innerHTML;
        
        // Afficher l'indicateur de chargement
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement...';
        
        // Envoyer la requête AJAX
        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Erreur réseau');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Fermer le modal et recharger la page pour voir les modifications
                closeModal('editIPModal');
                window.location.reload();
            } else {
                // Afficher les erreurs
                let errorMessage = data.message || 'Une erreur est survenue';
                if (data.errors) {
                    errorMessage = Object.values(data.errors).join('\n');
                }
                
                // Afficher le message d'erreur
                let errorDiv = form.querySelector('.error-message');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'error-message';
                    form.insertBefore(errorDiv, form.firstChild);
                }
                errorDiv.textContent = errorMessage;
                errorDiv.style.display = 'block';
                
                // Faire défiler jusqu'à l'erreur
                errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue lors de la mise à jour: ' + error.message);
        })
        .finally(() => {
            // Réactiver le bouton
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonText;
        });
    });
    
    // Fermer le modal en cliquant sur l'overlay
    document.querySelectorAll('.overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                console.log('Fermeture du modal via overlay');
                this.style.display = 'none';
            }
        });
    });
    
    // Fonction de confirmation de suppression avec modal personnalisé centré
    function confirmDelete(form) {
        // Créer un modal de confirmation personnalisé
        const confirmModal = document.createElement('div');
        confirmModal.className = 'overlay';
        confirmModal.style.display = 'flex';
        confirmModal.innerHTML = `
            <div class="modal" style="max-width: 400px; text-align: center;">
                <h2 style="color: #e74c3c; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 48px; display: block; margin-bottom: 15px;"></i>
                    Confirmation de suppression
                </h2>
                <p style="margin: 20px 0; font-size: 16px; color: #333;">Êtes-vous sûr de vouloir supprimer cette adresse IP ?</p>
                <p style="color: #e74c3c; font-size: 14px; font-weight: bold;">⚠️ Cette action est irréversible !</p>
                <div class="form-actions" style="margin-top: 30px; display: flex; gap: 10px; justify-content: center;">
                    <button type="button" class="btn-delete-confirm" style="background: #e74c3c; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; transition: all 0.3s;">
                        <i class="fas fa-trash"></i> Supprimer
                    </button>
                    <button type="button" class="btn-cancel-confirm" style="background: #95a5a6; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; transition: all 0.3s;">
                        <i class="fas fa-times"></i> Annuler
                    </button>
                </div>
            </div>
        `;
        
        // Ajouter le modal au body
        document.body.appendChild(confirmModal);
        
        // Gérer la soumission
        confirmModal.querySelector('.btn-delete-confirm').addEventListener('click', function() {
            confirmModal.remove();
            form.submit();
        });
        
        // Gérer l'annulation
        confirmModal.querySelector('.btn-cancel-confirm').addEventListener('click', function() {
            confirmModal.remove();
        });
        
        // Fermer en cliquant sur l'overlay
        confirmModal.addEventListener('click', function(e) {
            if (e.target === confirmModal) {
                confirmModal.remove();
            }
        });
        
        // Effet hover sur les boutons
        const deleteBtn = confirmModal.querySelector('.btn-delete-confirm');
        const cancelBtn = confirmModal.querySelector('.btn-cancel-confirm');
        
        deleteBtn.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.05)';
            this.style.boxShadow = '0 5px 15px rgba(231, 76, 60, 0.4)';
        });
        deleteBtn.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
            this.style.boxShadow = 'none';
        });
        
        cancelBtn.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.05)';
            this.style.boxShadow = '0 5px 15px rgba(149, 165, 166, 0.4)';
        });
        cancelBtn.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
            this.style.boxShadow = 'none';
        });
    }
    
    // Fonction pour appliquer les filtres (période et ville)
    function applyFilters() {
        const url = new URL(window.location);
        const period = document.getElementById('period').value;
        const city = document.getElementById('cityFilter').value;
        
        if (period) {
            url.searchParams.set('period', period);
        } else {
            url.searchParams.delete('period');
        }
        
        if (city) {
            url.searchParams.set('city', city);
        } else {
            url.searchParams.delete('city');
        }
        
        url.searchParams.delete('page'); // Reset à la page 1
        window.location.href = url.toString();
    }
    
    // Vérifier que tout est bien chargé
    document.addEventListener('DOMContentLoaded', function() {
        console.log('✅ Page gestion_ip.php chargée');
        console.log('✅ Toutes les fonctions sont définies dans cette page');
    });
    </script>


    <!-- Scripts JavaScript -->
    <script>
        // Fonction pour afficher la modale d'ajout d'IP
        function showAddIPModal() {
            const modal = document.getElementById('addIPModal');
            if (modal) {
                modal.style.display = 'flex';
                const ipInput = document.getElementById('ip_address');
                if (ipInput) ipInput.focus();
            }
        }
        
        // Fonction pour fermer la modale d'ajout d'IP
        function closeAddIPModal() {
            const modal = document.getElementById('addIPModal');
            if (modal) {
                modal.style.display = 'none';
                // Réinitialiser le formulaire
                const form = document.getElementById('addIPForm');
                if (form) form.reset();
                // Cacher les messages d'erreur
                document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
                const formError = document.getElementById('formError');
                if (formError) formError.style.display = 'none';
            }
        }
        
        // Fonction pour soumettre le formulaire via AJAX
        function submitAddIPForm(event) {
            event.preventDefault();
            
            const form = event.target;
            if (!form || !form.action) return false;
            
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            const btnText = submitBtn ? submitBtn.querySelector('.btn-text') : null;
            const spinner = submitBtn ? submitBtn.querySelector('.spinner-border') : null;
            const formError = document.getElementById('formError');
            
            // Réinitialiser les messages d'erreur
            document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
            if (formError) formError.style.display = 'none';
            
            // Désactiver le bouton et afficher le spinner
            if (submitBtn) submitBtn.disabled = true;
            if (btnText) btnText.textContent = 'Traitement...';
            if (spinner) spinner.classList.remove('d-none');
            
            // Envoyer la requête AJAX
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erreur réseau');
                }
                return response.json();
            })
            .then(data => {
                // Supprimer les anciens messages d'alerte
                const existingAlerts = document.querySelectorAll('#addIPForm .alert');
                existingAlerts.forEach(alert => alert.remove());
                
                // Réinitialiser les états d'erreur
                document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
                document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
                
                if (data && data.success) {
                    // Afficher un message de succès
                    const successMsg = data.message || 'Adresse IP ajoutée avec succès';
                    alert(successMsg);
                    
                    // Vider et fermer le formulaire
                    const form = document.getElementById('addIPForm');
                    if (form) {
                        form.reset();
                        closeAddIPModal();
                    }
                } else {
                    // Afficher les erreurs dans une alerte simple
                    if (data && data.errors) {
                        // Pour les erreurs d'IP existante
                        if (data.error_type === 'ip_exists' && data.existing_ip) {
                            const ip = data.form_data?.ip || '';
                            const details = data.existing_ip;
                            
                            let errorMsg = `Cette adresse IP existe déjà : ${ip}\n\n`;
                            errorMsg += `VLAN: ${details.vlan || 'N/A'}\n`;
                            errorMsg += `Client: ${details.customer_name || 'Aucun client'}\n`;
                            errorMsg += `Statut: ${details.status || 'N/A'}`;
                            
                            alert(errorMsg);
                        } else {
                            // Pour les erreurs de validation
                            const errorMessages = [];
                            
                            Object.entries(data.errors).forEach(([field, message]) => {
                                const errorMsg = Array.isArray(message) ? message[0] : message;
                                errorMessages.push(`- ${errorMsg}`);
                                
                                // Mettre en évidence le champ en erreur
                                const inputElement = document.getElementById(field);
                                const errorElement = document.getElementById(`${field}_error`);
                                
                                if (inputElement) {
                                    inputElement.classList.add('is-invalid');
                                    inputElement.addEventListener('input', function clearError() {
                                        this.classList.remove('is-invalid');
                                        if (errorElement) errorElement.textContent = '';
                                        this.removeEventListener('input', clearError);
                                    });
                                }
                                
                                if (errorElement) {
                                    errorElement.textContent = errorMsg;
                                }
                            });
                            
                            if (errorMessages.length > 0) {
                                alert('Veuillez corriger les erreurs suivantes :\n\n' + errorMessages.join('\n'));
                            }
                        }
                    } 
                    
                    // Afficher un message d'erreur général si nécessaire
                    if (data && data.message && !data.errors) {
                        alert(data.message);
                    }
                    
                    // Faire défiler jusqu'au premier champ en erreur
                    const firstError = document.querySelector('.error-message:not(:empty)');
                    if (firstError && firstError.previousElementSibling) {
                        firstError.previousElementSibling.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } else if (formError && formError.textContent.trim() !== '') {
                        formError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                let errorMessage = 'Une erreur est survenue lors de la communication avec le serveur';
                
                if (error.message) {
                    errorMessage += ` : ${error.message}`;
                }
                
                showAlert('danger', errorMessage);
                
                if (formError) {
                    formError.textContent = errorMessage;
                    formError.style.display = 'block';
                    formError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            })
            .finally(() => {
                // Réactiver le bouton et masquer le spinner
                if (submitBtn) submitBtn.disabled = false;
                if (btnText) btnText.textContent = 'Enregistrer';
                if (spinner) spinner.classList.add('d-none');
            });
            
            return false;
        }
        
        // Fonction pour afficher une alerte
        function showAlert(type, message, isHtml = false) {
            if (!message) return;
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type || 'info'} alert-dismissible fade show`;
            alertDiv.role = 'alert';
            alertDiv.setAttribute('aria-live', 'polite');
            
            // Créer le contenu de l'alerte
            const contentDiv = document.createElement('div');
            if (isHtml) {
                contentDiv.innerHTML = message;
            } else {
                contentDiv.textContent = message;
            }
            
            // Bouton de fermeture
            const closeButton = document.createElement('button');
            closeButton.type = 'button';
            closeButton.className = 'btn-close';
            closeButton.setAttribute('data-bs-dismiss', 'alert');
            closeButton.setAttribute('aria-label', 'Fermer');
            
            // Ajouter les éléments au DOM
            alertDiv.appendChild(contentDiv);
            alertDiv.appendChild(closeButton);
            
            // Trouver le conteneur et insérer l'alerte
            const container = document.querySelector('.container');
            if (container) {
                const firstChild = container.firstChild;
                if (firstChild) {
                    container.insertBefore(alertDiv, firstChild);
                } else {
                    container.appendChild(alertDiv);
                }
                
                // Défiler jusqu'à l'alerte
                alertDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // Supprimer l'alerte après 8 secondes (plus long pour les messages d'erreur)
                const alertTimeout = setTimeout(() => {
                    if (alertDiv.parentNode === container) {
                        container.removeChild(alertDiv);
                    }
                }, 8000);
                
                // Gérer la fermeture manuelle
                closeButton.addEventListener('click', () => {
                    clearTimeout(alertTimeout);
                    if (alertDiv.parentNode === container) {
                        container.removeChild(alertDiv);
                    }
                });
            }
        }
        // Fonction utilitaire pour formater les erreurs d'IP existante
        function formatIPExistsError(data) {
            if (!data || !data.existing_ip) return data?.message || 'Cette adresse IP existe déjà';
            
            const ip = data.form_data?.ip || '';
            const details = data.existing_ip;
            let html = `<div class="ip-exists-error">
                <div class="error-title">Cette adresse IP existe déjà : <strong>${ip}</strong></div>
                <div class="error-details">`;
            
            if (details.vlan) html += `<div class="detail-item"><span class="detail-label">VLAN:</span> ${details.vlan}</div>`;
            if (details.customer_name) {
                html += `<div class="detail-item"><span class="detail-label">Client:</span> ${details.customer_name || 'Aucun client'}</div>`;
            }
            if (details.status) html += `<div class="detail-item"><span class="detail-label">Statut:</span> ${details.status}</div>`;
            if (details.created_at) {
                const date = new Date(details.created_at);
                html += `<div class="detail-item"><span class="detail-label">Créée le:</span> ${date.toLocaleString('fr-FR')}</div>`;
            }
            
            html += `</div></div>`;
            return html;
        }
    </script>
    <style>
        /* Style pour les champs en erreur */
        .is-invalid {
            border-color: #dc3545 !important;
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }
        
        .error-message {
            width: 100%;
            margin-top: 0.25rem;
            font-size: 0.875em;
            color: #dc3545;
        }
    </style>
    
    <script src="assets/js/script.js"></script>
</body>
</html>
