<?php

require_once 'Validation.php';
require_once 'ErrorHandler.php';

class IPManager {
    
    private $pdo;
    const ITEMS_PER_PAGE = 15;  // Nombre d'éléments par page
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Récupère les adresses IP avec pagination et relations (utilisateurs et VLANs)
     * @param int $page Numéro de la page (commence à 1)
     * @param array $filters Filtres de recherche optionnels
     * @return array Tableau contenant les données et les informations de pagination
     */
    public function getIPs($page = 1, $filters = []) {
        try {
            $offset = ($page - 1) * self::ITEMS_PER_PAGE;
            
            // Construction de la requête de base avec jointures pour récupérer utilisateurs et VLANs
            $sql = "SELECT SQL_CALC_FOUND_ROWS 
                        ipa.*,
                        GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as users,
                        GROUP_CONCAT(DISTINCT v.vlan_number ORDER BY v.vlan_number SEPARATOR ', ') as vlans
                    FROM ip_addresses ipa
                    LEFT JOIN user_addresses ua ON ipa.id = ua.address_id
                    LEFT JOIN users u ON ua.user_id = u.id
                    LEFT JOIN address_vlans av ON ipa.id = av.address_id
                    LEFT JOIN vlans v ON av.vlan_id = v.id
                    WHERE 1=1";
            $params = [];
            
            // Application des filtres
            if (!empty($filters['search'])) {
                $sql .= " AND (ipa.ip_address LIKE :search 
                        OR ipa.city LIKE :search
                        OR u.name LIKE :search
                        OR v.vlan_number LIKE :search
                        OR ipa.customer_name LIKE :search)";
                $params[':search'] = '%' . $filters['search'] . '%';
            }
            
            if (!empty($filters['status'])) {
                $sql .= " AND ipa.status = :status";
                $params[':status'] = $filters['status'];
            }
            
            if (!empty($filters['vlan'])) {
                $sql .= " AND v.vlan_number = :vlan";
                $params[':vlan'] = $filters['vlan'];
            }
            
            if (!empty($filters['city'])) {
                $sql .= " AND ipa.city = :city";
                $params[':city'] = $filters['city'];
            }
            
            // Grouper par adresse IP pour éviter les doublons
            $sql .= " GROUP BY ipa.id";
            
            // Tri
            $orderBy = !empty($filters['order_by']) ? $filters['order_by'] : 'ipa.updated_at';
            $orderDir = !empty($filters['order_dir']) ? strtoupper($filters['order_dir']) : 'DESC';
            $orderDir = in_array($orderDir, ['ASC', 'DESC']) ? $orderDir : 'DESC';
            $sql .= " ORDER BY $orderBy $orderDir";
            
            // Pagination
            $sql .= " LIMIT :offset, :limit";
            
            // Préparation et exécution de la requête
            $stmt = $this->pdo->prepare($sql);
            
            // Liaison des paramètres
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->bindValue(':limit', self::ITEMS_PER_PAGE, PDO::PARAM_INT);
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Récupération du nombre total d'enregistrements
            $total = $this->pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
            
            // Calcul du nombre total de pages
            $totalPages = ceil($total / self::ITEMS_PER_PAGE);
            
            return [
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'total' => (int)$total,
                    'per_page' => self::ITEMS_PER_PAGE,
                    'current_page' => (int)$page,
                    'total_pages' => $totalPages,
                    'from' => $offset + 1,
                    'to' => min($offset + self::ITEMS_PER_PAGE, $total)
                ]
            ];
            
        } catch (PDOException $e) {
            error_log("Erreur PDO dans getIPs: " . $e->getMessage());
            return [
                'success' => false,
                'errors' => ['Erreur lors de la récupération des adresses IP']
            ];
        }
    }
    
    /**
     * Récupère ou crée un utilisateur par son nom
     * @param string $name Nom de l'utilisateur
     * @param string $city Ville (optionnel)
     * @return int|false ID de l'utilisateur ou false en cas d'erreur
     */
    private function getOrCreateUser($name, $city = '') {
        try {
            $name = trim($name);
            if (empty($name)) {
                return false;
            }
            
            // Chercher l'utilisateur existant
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE name = ? LIMIT 1");
            $stmt->execute([$name]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                return (int)$user['id'];
            }
            
            // Créer l'utilisateur s'il n'existe pas
            $stmt = $this->pdo->prepare("INSERT INTO users (name, city) VALUES (?, ?)");
            $stmt->execute([$name, $city]);
            return (int)$this->pdo->lastInsertId();
            
        } catch (PDOException $e) {
            error_log("Erreur getOrCreateUser: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupère ou crée un VLAN par son numéro
     * @param string $vlanNumber Numéro du VLAN
     * @return int|false ID du VLAN ou false en cas d'erreur
     */
    private function getOrCreateVlan($vlanNumber) {
        try {
            $vlanNumber = trim($vlanNumber);
            if (empty($vlanNumber)) {
                return false;
            }
            
            // Chercher le VLAN existant
            $stmt = $this->pdo->prepare("SELECT id FROM vlans WHERE vlan_number = ? LIMIT 1");
            $stmt->execute([$vlanNumber]);
            $vlan = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($vlan) {
                return (int)$vlan['id'];
            }
            
            // Créer le VLAN s'il n'existe pas
            $stmt = $this->pdo->prepare("INSERT INTO vlans (vlan_number) VALUES (?)");
            $stmt->execute([$vlanNumber]);
            return (int)$this->pdo->lastInsertId();
            
        } catch (PDOException $e) {
            error_log("Erreur getOrCreateVlan: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ajoute une nouvelle adresse IP avec gestion des relations
     * @param string $ip Adresse IP
     * @param string|array $vlan Numéro(s) de VLAN (string ou array pour plusieurs VLANs)
     * @param string|array $customer_name Nom(s) du/des client(s) (string ou array pour plusieurs utilisateurs)
     * @param string $city Ville
     * @return array Résultat de l'opération
     */
    public function addIP($ip, $vlan, $customer_name = '', $city = 'Douala') {
        try {
            // Démarrer une transaction
            $this->pdo->beginTransaction();
            
            // Vérification minimale des données
            if (empty($ip)) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['L\'adresse IP est obligatoire']];
            }
            
            // Gérer le cas où vlan est un array ou une string
            $vlans = is_array($vlan) ? $vlan : (!empty($vlan) ? [$vlan] : []);
            $customers = is_array($customer_name) ? $customer_name : (!empty($customer_name) ? [$customer_name] : []);
            
            // Nettoyage des entrées
            $ip = trim($ip);
            $city = trim($city);
            
            error_log("Tentative d'ajout de l'IP: $ip, VLANs: " . implode(', ', $vlans) . ", Ville: $city");
            
            // Ne pas imposer le format IP (adresses de monitoring) - seulement non vide
            if (empty($ip)) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['L\'adresse est obligatoire']];
            }
            
            // Vérifier si l'IP existe déjà
            if ($this->ipExists($ip)) {
                $this->pdo->rollBack();
                $stmt = $this->pdo->prepare("SELECT * FROM ip_addresses WHERE ip_address = ?");
                $stmt->execute([$ip]);
                $existingIP = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $details = "";
                if ($existingIP) {
                    $details = sprintf(
                        " (Statut: %s)",
                        $existingIP['status'] ?? 'INCONNU'
                    );
                }
                
                return [
                    'success' => false, 
                    'errors' => ["Cette adresse IP existe déjà$details"],
                    'existing_ip' => $existingIP
                ];
            }
            
            // Déterminer le statut
            $status = !empty($customers) ? 'UP' : 'DOWN';
            
            // Insérer l'adresse IP (sans vlan et customer_name dans la table principale)
            $sql = "
                INSERT INTO ip_addresses (ip_address, city, status) 
                VALUES (:ip, :city, :status)
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':ip' => $ip,
                ':city' => $city,
                ':status' => $status
            ]);
            
            $addressId = (int)$this->pdo->lastInsertId();
            error_log("IP ajoutée avec succès. ID: $addressId");
            
            // Créer les relations avec les VLANs
            if (!empty($vlans)) {
                $stmtVlan = $this->pdo->prepare("INSERT IGNORE INTO address_vlans (address_id, vlan_id) VALUES (?, ?)");
                foreach ($vlans as $vlanNumber) {
                    $vlanNumber = trim($vlanNumber);
                    if (!empty($vlanNumber)) {
                        $vlanId = $this->getOrCreateVlan($vlanNumber);
                        if ($vlanId) {
                            $stmtVlan->execute([$addressId, $vlanId]);
                        }
                    }
                }
            }
            
            // Créer les relations avec les utilisateurs
            if (!empty($customers)) {
                $stmtUser = $this->pdo->prepare("INSERT IGNORE INTO user_addresses (user_id, address_id) VALUES (?, ?)");
                foreach ($customers as $customer) {
                    $customer = trim($customer);
                    if (!empty($customer)) {
                        $userId = $this->getOrCreateUser($customer, $city);
                        if ($userId) {
                            $stmtUser->execute([$userId, $addressId]);
                        }
                    }
                }
            }
            
            // Commiter la transaction
            $this->pdo->commit();
            
            return ['success' => true, 'id' => $addressId];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Erreur PDO dans addIP: " . $e->getMessage());
            
            if ($e->getCode() == '23000') {
                return ['success' => false, 'errors' => ['Cette adresse IP existe déjà dans la base de données']];
            }
            
            return [
                'success' => false, 
                'errors' => [
                    'Erreur lors de l\'ajout de l\'adresse IP', 
                    'Détails: ' . $e->getMessage()
                ]
            ];
        }
    }

    /** 
     * 
    */
    
    /**
     * Met à jour une adresse IP avec gestion des relations
     * @param int $id ID de l'adresse IP
     * @param string $ip Adresse IP
     * @param string|array $vlan Numéro(s) de VLAN (string ou array pour plusieurs VLANs)
     * @param string|array $customer_name Nom(s) du/des client(s) (string ou array pour plusieurs utilisateurs)
     * @return array Résultat de l'opération
     */
    public function updateIP($id, $ip, $vlan, $customer_name = '') {
        try {
            // Démarrer une transaction
            $this->pdo->beginTransaction();
            
            // Vérification minimale des données
            if (empty($ip)) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['L\'adresse IP est obligatoire']];
            }
            
            // Vérifier si l'IP existe déjà (sauf pour l'ID en cours de modification)
            if ($this->ipExists($ip, $id)) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Cette adresse IP existe déjà']];
            }
            
            // Gérer le cas où vlan et customer_name sont des arrays ou des strings
            $vlans = is_array($vlan) ? $vlan : (!empty($vlan) ? [trim($vlan)] : []);
            $customers = is_array($customer_name) ? $customer_name : (!empty($customer_name) ? [trim($customer_name)] : []);
            
            // Déterminer le statut
            $status = !empty($customers) ? 'UP' : 'DOWN';
            
            // Mettre à jour l'adresse IP (sans vlan et customer_name)
            $stmt = $this->pdo->prepare("
                UPDATE ip_addresses 
                SET ip_address = ?, status = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            
            $stmt->execute([
                trim($ip),
                $status,
                $id
            ]);
            
            // Mettre à jour les relations VLAN (sans transaction imbriquée)
            $vlanResult = $this->updateAddressVlansInternal($id, $vlans);
            if (!$vlanResult['success']) {
                $this->pdo->rollBack();
                return $vlanResult;
            }
            
            // Mettre à jour les relations utilisateur (sans transaction imbriquée)
            $customerResult = $this->updateCustomerInternal($id, $customers, '');
            if (!$customerResult['success']) {
                $this->pdo->rollBack();
                return $customerResult;
            }
            
            // Commiter la transaction
            $this->pdo->commit();
            
            return ['success' => true];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'errors' => ['Erreur lors de la mise à jour de l\'adresse IP: ' . $e->getMessage()]];
        }
    }
    
    /**
     * Met à jour les utilisateurs associés à une adresse IP et la ville (méthode privée sans transaction)
     * @param int $id ID de l'adresse IP
     * @param array $customers Tableau des noms de clients
     * @param string $city Ville
     * @return array Résultat de l'opération
     */
    private function updateCustomerInternal($id, $customers, $city) {
        // Vérification minimale
        if (empty($id)) {
            return ['success' => false, 'errors' => ['ID manquant']];
        }
        
        // Nettoyage des entrées
        $city = trim($city);
        
        // Déterminer le statut
        $status = !empty($customers) ? 'UP' : 'DOWN';
        
        // Mettre à jour l'adresse IP (ville et statut)
        $stmt = $this->pdo->prepare("
            UPDATE ip_addresses 
            SET city = :city, 
                status = :status, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':city' => $city,
            ':status' => $status,
            ':id' => $id
        ]);
        
        // Supprimer les anciennes relations utilisateur
        $stmtDelete = $this->pdo->prepare("DELETE FROM user_addresses WHERE address_id = ?");
        $stmtDelete->execute([$id]);
        
        // Créer les nouvelles relations utilisateur
        if (!empty($customers)) {
            $stmtUser = $this->pdo->prepare("INSERT IGNORE INTO user_addresses (user_id, address_id) VALUES (?, ?)");
            foreach ($customers as $customer) {
                $customer = trim($customer);
                if (!empty($customer)) {
                    $userId = $this->getOrCreateUser($customer, $city);
                    if ($userId) {
                        $stmtUser->execute([$userId, $id]);
                    }
                }
            }
        }
        
        return ['success' => true];
    }
    
    /**
     * Met à jour les utilisateurs associés à une adresse IP et la ville
     * @param int $id ID de l'adresse IP
     * @param string|array $customer_name Nom(s) du/des client(s) (string ou array pour plusieurs utilisateurs)
     * @param string $city Ville (optionnel, par défaut 'Douala')
     * @return array Résultat de l'opération
     */
    public function updateCustomer($id, $customer_name, $city = 'Douala') {
        try {
            // Démarrer une transaction
            $this->pdo->beginTransaction();
            
            // Gérer le cas où customer_name est un array ou une string
            $customers = is_array($customer_name) ? $customer_name : (!empty($customer_name) ? [trim($customer_name)] : []);
            
            // Appeler la méthode interne
            $result = $this->updateCustomerInternal($id, $customers, $city);
            
            if (!$result['success']) {
                $this->pdo->rollBack();
                return $result;
            }
            
            // Commiter la transaction
            $this->pdo->commit();
            
            return ['success' => true];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log('Erreur updateCustomer: ' . $e->getMessage());
            return [
                'success' => false, 
                'errors' => [
                    'Erreur lors de la mise à jour du client',
                    $e->getMessage()
                ]
            ];
        }
    }
    
    /**
     * Supprime une adresse IP
     */
    public function deleteIP($id) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM ip_addresses WHERE id = ?");
            $stmt->execute([$id]);
            
            return ['success' => true];
            
        } catch (PDOException $e) {
            return ['success' => false, 'errors' => ['Erreur lors de la suppression de l\'adresse IP']];
        }
    }
    
    /**
     * Récupère toutes les adresses IP avec pagination
     */
    public function getAllIPs($page = 1, $limit = 50, $search = '') {
        try {
            $offset = ($page - 1) * $limit;
            
            $whereClause = "WHERE (customer_name IS NOT NULL AND customer_name != '')";
            $params = [];
            
            if (!empty($search)) {
                $whereClause .= " AND (ip_address LIKE :search1 OR vlan LIKE :search2 OR customer_name LIKE :search3)";
                $searchTerm = "%{$search}%";
                $params = [
                    ':search1' => $searchTerm,
                    ':search2' => $searchTerm,
                    ':search3' => $searchTerm
                ];
            }
            
            // Compter le total
            $countSql = "SELECT COUNT(*) FROM ip_addresses {$whereClause}";
            $countStmt = $this->pdo->prepare($countSql);
            
            // Lier les paramètres pour le comptage
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            
            $countStmt->execute();
            $total = $countStmt->fetchColumn();
            
            // Récupérer les données avec pagination
            // Pour certaines versions de MySQL/MariaDB, on doit utiliser des paramètres positionnels avec LIMIT
            $sql = "SELECT * FROM ip_addresses {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $stmt = $this->pdo->prepare($sql);
            
            // Préparer les paramètres
            $paramIndex = 1;
            
            // Ajouter les paramètres de recherche s'ils existent
            if (!empty($params)) {
                foreach ($params as $value) {
                    $stmt->bindValue($paramIndex++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
                }
            }
            
            // Ajouter les paramètres de pagination
            $stmt->bindValue($paramIndex++, (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue($paramIndex, (int)$offset, PDO::PARAM_INT);
            
            error_log("Requête SQL: " . $sql);
            error_log("Paramètres: " . print_r(array_merge(array_values($params), [(int)$limit, (int)$offset]), true));
            
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $results,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => $limit > 0 ? ceil($total / $limit) : 1
            ];
            
        } catch (PDOException $e) {
            error_log('Erreur getAllIPs: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            ErrorHandler::logError('Erreur lors de la récupération des IPs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false, 
                'errors' => ['Erreur de base de données: ' . $e->getMessage()],
                'data' => []
            ];
        }
    }
    
    /**
     * Récupère les adresses IP disponibles (sans client)
     */
    public function getAvailableIPs($search = '') {
        try {
            $whereClause = "WHERE customer_name = '' OR customer_name IS NULL";
            $params = [];
            
            if (!empty($search)) {
                $whereClause .= " AND (ip_address LIKE ? OR vlan LIKE ?)";
                $searchTerm = "%{$search}%";
                $params = [$searchTerm, $searchTerm];
            }
            
            $sql = "SELECT * FROM ip_addresses {$whereClause} ORDER BY created_at DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            
        } catch (PDOException $e) {
            ErrorHandler::logError('Erreur lors de la récupération des IPs disponibles', [
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'errors' => ['Erreur de base de données']];
        }
    }
    
    /**
     * Vérifie si une adresse IP existe déjà
     */
    public function ipExists($ip, $excludeId = null) {
        try {
            error_log("Vérification de l'existence de l'IP: $ip");
            $sql = "SELECT COUNT(*) as count FROM ip_addresses WHERE ip_address = ?";
            $params = [$ip];
            
            if ($excludeId !== null) {
                $sql .= " AND id != ?";
                $params[] = $excludeId;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $count = (int)$stmt->fetchColumn();
            
            error_log("Nombre d'occurrences trouvées pour $ip : $count");
            return $count > 0;
            
        } catch (PDOException $e) {
            error_log("Erreur lors de la vérification de l'IP $ip : " . $e->getMessage());
            return true; // En cas d'erreur, on considère que l'IP existe pour éviter les doublons
        }
    }

    /**
     * Met à jour les VLANs associés à une adresse IP (méthode privée sans transaction)
     * @param int $addressId ID de l'adresse IP
     * @param array $vlanArray Tableau des numéros de VLAN
     * @return array Résultat de l'opération
     */
    private function updateAddressVlansInternal($addressId, $vlanArray) {
        // Supprimer les anciennes relations VLAN
        $stmtDelete = $this->pdo->prepare("DELETE FROM address_vlans WHERE address_id = ?");
        $stmtDelete->execute([$addressId]);
        
        // Créer les nouvelles relations VLAN
        if (!empty($vlanArray)) {
            $stmtVlan = $this->pdo->prepare("INSERT IGNORE INTO address_vlans (address_id, vlan_id) VALUES (?, ?)");
            foreach ($vlanArray as $vlanNumber) {
                $vlanNumber = trim($vlanNumber);
                if (!empty($vlanNumber)) {
                    $vlanId = $this->getOrCreateVlan($vlanNumber);
                    if ($vlanId) {
                        $stmtVlan->execute([$addressId, $vlanId]);
                    }
                }
            }
        }
        
        return ['success' => true];
    }
    
    /**
     * Met à jour les VLANs associés à une adresse IP
     * @param int $addressId ID de l'adresse IP
     * @param string|array $vlans Numéro(s) de VLAN (string ou array pour plusieurs VLANs)
     * @return array Résultat de l'opération
     */
    public function updateAddressVlans($addressId, $vlans) {
        try {
            // Démarrer une transaction
            $this->pdo->beginTransaction();
            
            // Gérer le cas où vlans est un array ou une string
            $vlanArray = is_array($vlans) ? $vlans : (!empty($vlans) ? [trim($vlans)] : []);
            
            // Appeler la méthode interne
            $result = $this->updateAddressVlansInternal($addressId, $vlanArray);
            
            if (!$result['success']) {
                $this->pdo->rollBack();
                return $result;
            }
            
            // Commiter la transaction
            $this->pdo->commit();
            
            return ['success' => true];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log('Erreur updateAddressVlans: ' . $e->getMessage());
            return [
                'success' => false, 
                'errors' => [
                    'Erreur lors de la mise à jour des VLANs',
                    $e->getMessage()
                ]
            ];
        }
    }
    
    /**
     * Vérifie si un VLAN existe déjà dans la table vlans
     */
    public function vlanExists($vlanNumber) {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM vlans WHERE vlan_number = ?");
            $stmt->execute([trim($vlanNumber)]);
            $count = (int)$stmt->fetchColumn();
            
            error_log("Nombre d'occurrences trouvées pour $vlanNumber : $count");
            return $count > 0;
            
        } catch (PDOException $e) {
            error_log("Erreur lors de la vérification du VLAN $vlanNumber : " . $e->getMessage());
            return true; // En cas d'erreur, on considère que le VLAN existe pour éviter les doublons
        }
    }
    
    /**
     * Récupère les statistiques globales des adresses IP
     * @return array Tableau contenant les statistiques
     */
    public function getStats() {
        try {
            // Requête unique pour obtenir toutes les statistiques nécessaires
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'UP' THEN 1 ELSE 0 END) as used,
                        SUM(CASE WHEN status = 'DOWN' OR status IS NULL THEN 1 ELSE 0 END) as available
                    FROM ip_addresses";
            
            $stmt = $this->pdo->query($sql);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // S'assurer que les valeurs sont bien définies
            $total = (int)$stats['total'];
            $used = (int)$stats['used'];
            $available = (int)$stats['available'];
            
            // Vérifier la cohérence des données
            if (($used + $available) !== $total) {
                // Si incohérence, forcer le recalcul de available
                $available = $total - $used;
            }
            
            // Calculer les pourcentages
            $usedPercentage = $total > 0 ? round(($used / $total) * 100, 2) : 0;
            $availablePercentage = $total > 0 ? 100 - $usedPercentage : 0;
            
            return [
                'success' => true,
                'data' => [
                    'total' => $total,
                    'used' => $used,
                    'available' => $available,
                    'used_percentage' => $usedPercentage,
                    'available_percentage' => $availablePercentage
                ]
            ];
            
        } catch (PDOException $e) {
            error_log('Erreur getStats: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return [
                'success' => false,
                'errors' => ['Erreur lors de la récupération des statistiques: ' . $e->getMessage()]
            ];
        }
    }
    
    /**
     * Exporte les données en CSV
     */
    public function exportToCSV() {
        try {
            $result = $this->getAllIPs(1, 10000); // Récupérer toutes les données
            
            if (!$result['success']) {
                return $result;
            }
            
            $filename = 'ip_addresses_' . date('Y-m-d_H-i-s') . '.csv';
            $filepath = '../exports/' . $filename;
            
            // Créer le dossier exports s'il n'existe pas
            if (!is_dir('../exports')) {
                mkdir('../exports', 0755, true);
            }
            
            $file = fopen($filepath, 'w');
            
            // En-têtes CSV
            fputcsv($file, ['ID', 'Adresse IP', 'VLAN', 'Client', 'Statut', 'Créé le', 'Modifié le']);
            
            // Données
            foreach ($result['data'] as $row) {
                fputcsv($file, [
                    $row['id'],
                    $row['ip_address'],
                    $row['vlan'],
                    $row['customer_name'],
                    $row['status'],
                    $row['created_at'],
                    $row['updated_at']
                ]);
            }
            
            fclose($file);
            
            ErrorHandler::logActivity('Data Exported', ['filename' => $filename]);
            
            return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
            
        } catch (Exception $e) {
            ErrorHandler::logError('Erreur lors de l\'export CSV', [
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'errors' => ['Erreur lors de l\'export']];
        }
    }
}
