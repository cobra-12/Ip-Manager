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

try {
    // Connexion à la base de données
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Charger les villes disponibles
    try {
        $cities = [];
        $stmtCities = $pdo->query("SELECT name FROM cities ORDER BY name ASC");
        $cities = $stmtCities->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $cities = ['Douala','Yaoundé']; // fallback minimal si la table n'existe pas
    }
    
    // Initialiser le gestionnaire d'IP
    $ipManager = new IPManager($pdo);
    
    // Récupérer les statistiques globales
    $stats = $ipManager->getStats();
    $statsData = $stats['success'] ? $stats['data'] : null;
    
    // Récupérer le numéro de page actuel (par défaut 1)
    $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($currentPage < 1) $currentPage = 1;
    
    // IMPORTANT : Cette page affiche uniquement les IPs avec statut UP
    // Les IPs avec statut DOWN sont visibles dans gestion_ip.php
    // Quand une IP passe de DOWN à UP (via assignation d'un client), elle apparaît automatiquement ici
    
    // Récupérer les IP avec pagination
    $filters = [
        'status' => 'UP'  // Filtrer uniquement les IP avec statut UP
    ];
    
    // Appliquer les filtres si présents dans l'URL
    if (!empty($_GET['search'])) {
        $filters['search'] = $_GET['search'];
    }
    
    if (!empty($_GET['city'])) {
        $filters['city'] = $_GET['city'];
    }
    
    // Récupérer les données paginées
    $result = $ipManager->getIPs($currentPage, $filters);
    
} catch(PDOException $e) {
    $errorMsg = 'Erreur de connexion à la base de données';
    error_log('Erreur PDO dans list.php: ' . $e->getMessage());
    ErrorHandler::logError('Erreur dans list.php', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} catch(Exception $e) {
    $errorMsg = 'Une erreur inattendue est survenue';
    error_log('Erreur dans list.php: ' . $e->getMessage());
    ErrorHandler::logError('Erreur dans list.php', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des Adresses IP</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <nav class="nav-menu">
            <a href="index.php" class="nav-link">Accueil</a>
            <a href="gestion_ip.php" class="nav-link">Gestion</a>
            <a href="list.php" class="nav-link active">Liste des IP</a>
        </nav>
        <div class="card">
            <h1>Liste des Adresses IP</h1>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Affichage des adresses IP actives (statut UP).
            </div>
            <div class="filters" style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
                <div class="filter-group" style="display: flex; align-items: center; gap: 10px;">
                    <label for="cityFilter" style="margin: 0; font-weight: 500;">Filtrer par ville :</label>
                    <select id="cityFilter" name="city" class="form-control" onchange="filterByCity(this.value)" style="min-width: 200px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">Toutes les villes</option>
                        <?php foreach ($cities as $cityName): ?>
                            <option value="<?php echo htmlspecialchars($cityName); ?>" <?php echo (isset($_GET['city']) && $_GET['city'] === $cityName) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cityName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-container" style="flex: 1; min-width: 250px;">
                    <input type="text" id="searchInput" class="search-input" placeholder="Rechercher une IP, VLAN ou client..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    <i class="fas fa-search search-icon"></i>
                </div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Adresse IP</th>
                            <th>VLAN</th>
                            <th>Client</th>
                            <th>Ville</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($result['data'])): ?>
                        <tr>
                            <td colspan="6" class="text-center">Aucune adresse IP trouvée</td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    if (empty($result['data'])) {
                        echo "<tr><td colspan='6' class='text-center'>Aucune adresse IP trouvée <a href='gestion_ip.php'>Ajouter une adresse IP</a></td></tr>";
                    } elseif (isset($result) && $result['success'] && isset($result['data'])) {
                        foreach($result['data'] as $row) {
                            $status = $row['status'];
                            $statusClass = $status === 'UP' ? 'status-up' : 'status-down';
                            
                            // Afficher les utilisateurs (depuis la relation many-to-many)
                            $users = !empty($row['users']) ? htmlspecialchars($row['users']) : '<span class="text-muted">Non attribué</span>';
                            
                            // Afficher les VLANs (depuis la relation many-to-many)
                            $vlans = !empty($row['vlans']) ? htmlspecialchars($row['vlans']) : '<span class="text-muted">N/A</span>';
                            
                            $city = !empty($row['city']) ? htmlspecialchars($row['city']) : 'N/A';
                            
                            echo "<tr>";
                            echo "<td>{$row['ip_address']}</td>";
                            echo "<td>$vlans</td>";
                            echo "<td>$users</td>";
                            echo "<td>$city</td>";
                            echo "<td><span class='$statusClass'>$status</span></td>";
                            echo "<td class='actions-column'>";
                            if (!empty($row['users'])) {
                                echo "<button class='btn-icon edit' onclick='showEditCustomer({$row['id']}, \"".htmlspecialchars($row['users'], ENT_QUOTES)."\")' title='Modifier'>";
                                echo "<i class='fas fa-edit'></i>";
                                echo "</button>";
                            } else {
                                echo "<button class='btn-icon edit disabled' disabled title='Aucun client à modifier'>";
                                echo "<i class='fas fa-edit'></i>";
                                echo "</button>";
                            }
                            echo "<form method='POST' action='delete_ip.php' style='display: inline;'>";
                            echo CSRFProtection::getHiddenField();
                            echo "<input type='hidden' name='id' value='{$row['id']}'>";
                            echo "<button type='button' class='btn-icon delete' onclick='confirmDelete(this.form)' title='Supprimer'>";
                            echo "<i class='fas fa-trash-alt'></i>";
                            echo "</button>";
                            echo "</form>";
                            echo "</td>";
                            echo "</tr>";
                        }
                    } elseif (isset($result['errors'])) {
                        $errorMessage = is_array($result['errors']) ? implode('<br>', $result['errors']) : $result['errors'];
                        echo "<tr><td colspan='6' class='text-center'>$errorMessage <a href='gestion_ip.php'>Ajouter une adresse IP</a></td></tr>";
                    } else {
                        echo "<tr><td colspan='6' class='text-center'>Aucune donnée disponible <a href='gestion_ip.php'>Ajouter une adresse IP</a></td></tr>";
                    }
                    ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if (isset($result['pagination']) && $result['pagination']['total_pages'] > 1): 
                $currentPage = $result['pagination']['current_page'];
                $totalPages = $result['pagination']['total_pages'];
                $searchParam = !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
                $cityParam = !empty($_GET['city']) ? '&city=' . urlencode($_GET['city']) : '';
                $allParams = $searchParam . $cityParam;
            ?>
            <div class="pagination-container">
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
            </div>
            <?php endif; ?>
            
            <!-- Boutons Import et Export après le tableau -->
            <div class="export-container">
                <a href="import.php" class="btn-import">
                    <i class="fas fa-upload"></i> Importer des données
                </a>
                <form method="POST" action="export.php" style="display: inline;">
                    <?php echo CSRFProtection::getHiddenField(); ?>
                    <input type="hidden" name="action" value="export">
                    <button type="submit" class="btn-export">
                        <i class="fas fa-download"></i> Exporter CSV
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Fonction de confirmation de suppression avec modal personnalisé centré
    function createConfirmModal(form) {
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
        // comportements
        confirmModal.querySelector('.btn-delete-confirm').addEventListener('click', function() {
            confirmModal.remove();
            form.submit();
        });
        confirmModal.querySelector('.btn-cancel-confirm').addEventListener('click', function() {
            confirmModal.remove();
        });
        confirmModal.addEventListener('click', function(e) {
            if (e.target === confirmModal) confirmModal.remove();
        });
        // hover effects
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

        document.body.appendChild(confirmModal);
    }

    // appelé depuis le bouton supprimer
    window.confirmDelete = function(form) {
        createConfirmModal(form);
    };

    // Fonction pour afficher le modal d'édition du client
    window.showEditCustomer = function(id, customerName) {
        const editModal = document.getElementById('editCustomerModal');
        const idInput = document.getElementById('editCustomerId');
        const nameInput = document.getElementById('customerName');
        if (!editModal || !idInput || !nameInput) {
            console.error('Éléments modal non trouvés');
            return;
        }
        idInput.value = id;
        nameInput.value = customerName || '';
        editModal.style.display = 'flex';
        nameInput.focus();
    };

    // Fonction pour fermer un modal
    window.closeModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) modal.style.display = 'none';
    };

    // Fermer le modal en cliquant sur l'overlay (attache après que DOM est prêt)
    document.querySelectorAll('.overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });

    // Fonction pour filtrer par ville
    window.filterByCity = function(city) {
        const url = new URL(window.location);
        if (city) url.searchParams.set('city', city);
        else url.searchParams.delete('city');
        url.searchParams.delete('page');
        window.location.href = url.toString();
    };

    // Recherche entrée Enter
    const searchEl = document.getElementById('searchInput');
    if (searchEl) {
        searchEl.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                const url = new URL(window.location);
                const search = this.value.trim();
                if (search) url.searchParams.set('search', search);
                else url.searchParams.delete('search');
                url.searchParams.delete('page');
                window.location.href = url.toString();
            }
        });
    }
});
</script>

    <!-- Modal pour modifier le client -->
    <div id="editCustomerModal" class="overlay" style="display: none;">
        <div class="modal">
            <h2>Modifier le client</h2>
            <form id="editForm" method="POST" action="update_customer.php">
                <?php echo CSRFProtection::getHiddenField(); ?>
                <input type="hidden" id="editCustomerId" name="id">
                <div class="form-group">
                    <label for="customerName">Nom du client</label>
                    <input type="text" id="customerName" name="customer_name" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-submit">Enregistrer</button>
                    <button type="button" class="btn-cancel" onclick="closeModal('editCustomerModal')">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/js/script.js"></script>
</body>
</html>