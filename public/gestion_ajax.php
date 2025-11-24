<?php
session_start();

// Vérifier si c'est une requête AJAX
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    die('Accès refusé');
}

// Vérifier si la configuration existe
if (!file_exists('../config/db.php')) {
    http_response_code(500);
    die('Configuration de base de données manquante');
}

require_once '../config/db.php';
require_once '../src/ErrorHandler.php';
require_once '../src/CSRFProtection.php';
require_once '../src/IPManager.php';

// Initialiser le gestionnaire d'erreurs
ErrorHandler::init();

// Vérifier la protection CSRF
CSRFProtection::checkRequest();

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $ipManager = new IPManager($pdo);
    
    // Récupérer les paramètres de recherche
    $search = trim($_GET['search'] ?? '');
    $period = $_GET['period'] ?? 'all';
    $city = trim($_GET['city'] ?? '');
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    
    // Construire les conditions WHERE avec alias de table
    $whereConditions = [];
    $params = [];
    $countParams = [];
    
    // IMPORTANT : Cette page affiche uniquement les IPs avec statut DOWN
    $whereConditions[] = "(ipa.status = 'DOWN' OR ipa.status IS NULL OR ipa.status = '')";
    
    // Appliquer le filtre de période
    if ($period !== 'all') {
        $days = (int)$period;
        if ($days > 0) {
            $whereConditions[] = "(ipa.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) 
                     OR ipa.updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY))";
            $params[] = $days;
            $params[] = $days;
            $countParams[] = $days;
            $countParams[] = $days;
        }
    }
    
    // Filtre par ville
    if (!empty($city)) {
        $whereConditions[] = "ipa.city = ?";
        $params[] = $city;
        $countParams[] = $city;
    }
    
    // Condition de recherche
    if (!empty($search)) {
        $searchTerm = "%$search%";
        // Recherche dans IP, ville, et aussi dans les relations (users, vlans via sous-requêtes)
        $whereConditions[] = "(ipa.ip_address LIKE ? OR ipa.city LIKE ? OR ipa.customer_name LIKE ? 
                OR EXISTS (SELECT 1 FROM user_addresses ua2 JOIN users u2 ON ua2.user_id = u2.id WHERE ua2.address_id = ipa.id AND u2.name LIKE ?)
                OR EXISTS (SELECT 1 FROM address_vlans av2 JOIN vlans v2 ON av2.vlan_id = v2.id WHERE av2.address_id = ipa.id AND v2.vlan_number LIKE ?))";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    // Requête pour compter le nombre total d'enregistrements DOWN
    // Utiliser une sous-requête pour compter correctement après GROUP BY
    $countSql = "SELECT COUNT(*) as total FROM (
                    SELECT ipa.id
                    FROM ip_addresses ipa
                    LEFT JOIN user_addresses ua ON ipa.id = ua.address_id
                    LEFT JOIN users u ON ua.user_id = u.id
                    LEFT JOIN address_vlans av ON ipa.id = av.address_id
                    LEFT JOIN vlans v ON av.vlan_id = v.id
                    $whereClause
                    GROUP BY ipa.id
                ) as counted_ips";
    
    // Debug
    error_log("=== GESTION_AJAX PAGINATION ===");
    error_log("Count SQL: " . $countSql);
    error_log("Count Params: " . print_r($countParams, true));
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $totalItems = (int)($countResult['total'] ?? 0);
    $totalPages = max(1, ceil($totalItems / IPManager::ITEMS_PER_PAGE));
    
    // S'assurer que la page actuelle ne dépasse pas le total
    if ($page > $totalPages && $totalPages > 0) {
        $page = $totalPages;
    }
    
    // Calculer l'offset pour la pagination
    $offset = ($page - 1) * IPManager::ITEMS_PER_PAGE;
    $from = $totalItems > 0 ? $offset + 1 : 0;
    $to = min($offset + IPManager::ITEMS_PER_PAGE, $totalItems);
    
    error_log("Total items: $totalItems, Total pages: $totalPages");
    error_log("Current page: $page, Offset: $offset, From: $from, To: $to");
    error_log("=== FIN GESTION_AJAX PAGINATION ===");
    
    // Requête pour récupérer les données paginées avec relations (utilisateurs et VLANs)
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
    
    // Générer le contenu du tableau directement
    $html = '';
    if (count($recentIPs) > 0) {
        $html .= '<table>';
        $html .= '<thead><tr>';
        $html .= '<th>Adresse IP</th>';
        $html .= '<th>VLAN</th>';
        $html .= '<th>Client</th>';
        $html .= '<th>Ville</th>';
        $html .= '<th>Statut</th>';
        $html .= '<th>Ajouté le</th>';
        $html .= '<th>Actions</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';
        
        foreach ($recentIPs as $row) {
            // Récupérer les utilisateurs et VLANs depuis les relations
            $users = !empty($row['users']) ? htmlspecialchars($row['users']) : '';
            $vlans = !empty($row['vlans']) ? htmlspecialchars($row['vlans']) : '';
            $hasUsers = !empty($users);
            
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($row['ip_address']) . '</td>';
            $html .= '<td>' . ($vlans ?: '<span class="text-muted">N/A</span>') . '</td>';
            $html .= '<td>' . ($users ?: '<span class="text-muted">Non attribué</span>') . '</td>';
            $html .= '<td><span class="city-badge city-' . strtolower($row['city'] ?? '') . '">' . 
                     htmlspecialchars($row['city'] ?? '') . '</span></td>';
            $html .= '<td><span class="status-' . strtolower($row['status'] ?? 'down') . '">' . 
                     htmlspecialchars($row['status'] ?? 'DOWN') . '</span></td>';
            $html .= '<td>' . date('d/m/Y H:i', strtotime($row['created_at'])) . '</td>';
            
            // Boutons d'action
            $html .= '<td class="actions-column">';
            if (!$hasUsers) {
                $html .= '<button class="btn-icon use" onclick="useIP(' . $row['id'] . ')" title="Attribuer un client">';
                $html .= '<i class="fas fa-user-plus"></i></button>';
            } else {
                $html .= '<button class="btn-icon edit" onclick="showEditCustomer(' . $row['id'] . ', \'' . 
                         addslashes($users) . '\', \'' . 
                         addslashes($row['city'] ?? '') . '\')" title="Modifier le client">';
                $html .= '<i class="fas fa-user-edit"></i></button>';
            }
            
            $html .= '<button class="btn-icon edit" onclick="showEditIP(' . $row['id'] . ', \'' . 
                     htmlspecialchars($row['ip_address'], ENT_QUOTES) . '\', \'' . 
                     addslashes($vlans) . '\')" ' . 
                     'title="Modifier IP/VLAN">';
            $html .= '<i class="fas fa-edit"></i></button>';
            
            $html .= '<form method="POST" action="delete_ip.php" style="display: inline;">';
            $html .= CSRFProtection::getHiddenField();
            $html .= '<input type="hidden" name="id" value="' . $row['id'] . '">';
            $html .= '<button type="button" class="btn-icon delete" onclick="confirmDelete(this.form)" ' . 
                     'title="Supprimer">';
            $html .= '<i class="fas fa-trash-alt"></i></button></form>';
            
            $html .= '</td></tr>';
        }
        
        $html .= '</tbody></table>';
        
        // Ajouter la pagination seulement si nécessaire
        if ($totalPages > 1) {
            // Construire les paramètres d'URL
            $urlParams = [];
            if (!empty($period) && $period !== 'all') {
                $urlParams[] = 'period=' . urlencode($period);
            }
            if (!empty($city)) {
                $urlParams[] = 'city=' . urlencode($city);
            }
            if (!empty($search)) {
                $urlParams[] = 'search=' . urlencode($search);
            }
            $allParams = !empty($urlParams) ? '&' . implode('&', $urlParams) : '';
            
            $html .= '<div class="pagination-container" style="margin-top: 20px;">';
            $html .= '<div class="pagination-info" style="margin-bottom: 10px; color: #666; font-size: 0.9rem;">';
            $html .= "Affichage de $from à $to sur $totalItems résultat(s) - Page $page sur $totalPages";
            $html .= '</div>';
            $html .= '<div class="pagination">';
            
            // Bouton Précédent
            if ($page > 1) {
                $html .= '<a href="?page=' . ($page - 1) . $allParams . '" class="pagination-link prev">';
                $html .= '<i class="fas fa-chevron-left"></i><span>prev</span></a>';
            } else {
                $html .= '<span class="pagination-link prev disabled">';
                $html .= '<i class="fas fa-chevron-left"></i><span>prev</span></span>';
            }
            
            // Première page
            if ($page == 1) {
                $html .= '<span class="pagination-link active">1</span>';
            } else {
                $html .= '<a href="?page=1' . $allParams . '" class="pagination-link">1</a>';
            }
            
            // Points de suspension avant les pages du milieu
            if ($page > 4) {
                $html .= '<span class="pagination-ellipsis">...</span>';
            }
            
            // Pages autour de la page courante
            $startPage = max(2, $page - 1);
            $endPage = min($totalPages - 1, $page + 1);
            
            // Ajuster si on est proche du début
            if ($page <= 3) {
                $startPage = 2;
                $endPage = min(4, $totalPages - 1);
            }
            // Ajuster si on est proche de la fin
            if ($page >= $totalPages - 2 && $totalPages > 4) {
                $startPage = max(2, $totalPages - 3);
                $endPage = $totalPages - 1;
            }
            
            // Afficher les pages du milieu
            for ($i = $startPage; $i <= $endPage; $i++) {
                if ($i > 1 && $i < $totalPages) {
                    if ($i == $page) {
                        $html .= '<span class="pagination-link active">' . $i . '</span>';
                    } else {
                        $html .= '<a href="?page=' . $i . $allParams . '" class="pagination-link">' . $i . '</a>';
                    }
                }
            }
            
            // Points de suspension après les pages du milieu
            if ($page < $totalPages - 3 && $totalPages > 5) {
                $html .= '<span class="pagination-ellipsis">...</span>';
            }
            
            // Dernière page si différente de la première
            if ($totalPages > 1) {
                if ($page == $totalPages) {
                    $html .= '<span class="pagination-link active">' . $totalPages . '</span>';
                } else {
                    $html .= '<a href="?page=' . $totalPages . $allParams . '" class="pagination-link">' . $totalPages . '</a>';
                }
            }
            
            // Bouton Suivant
            if ($page < $totalPages) {
                $html .= '<a href="?page=' . ($page + 1) . $allParams . '" class="pagination-link next">';
                $html .= '<span>next</span><i class="fas fa-chevron-right"></i></a>';
            } else {
                $html .= '<span class="pagination-link next disabled">';
                $html .= '<span>next</span><i class="fas fa-chevron-right"></i></span>';
            }
            
            $html .= '</div></div>';
        }
        
    } else {
        $html = '<div class="alert alert-info">';
        $html .= '<i class="fas fa-info-circle"></i> ';
        $html .= 'Aucune adresse IP ne correspond à votre recherche.';
        $html .= '</div>';
    }
    
    // Retourner la réponse JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => $html,
        'pagination' => [
            'total' => $totalItems,
            'per_page' => IPManager::ITEMS_PER_PAGE,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'from' => $from,
            'to' => $to
        ]
    ]);
    
} catch (Exception $e) {
    ErrorHandler::logError('Erreur dans gestion_ajax.php', ['error' => $e->getMessage()]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Une erreur est survenue lors de la recherche.'
    ]);
}
?>
