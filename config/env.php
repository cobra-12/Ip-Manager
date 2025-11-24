<?php
/**
 * Configuration d'environnement d'exemple pour IP Manager
 * Copiez ce fichier vers config/env.php et adaptez les valeurs
 */

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ipmanager');

// Configuration de l'application
define('APP_NAME', 'IP Manager');
define('APP_VERSION', '2.0.0');
define('APP_ENV', 'development'); // development, production

// Configuration de sécurité
define('CSRF_TOKEN_LIFETIME', 3600); // Durée de vie des tokens CSRF en secondes
define('SESSION_LIFETIME', 7200); // Durée de vie des sessions en secondes

// Configuration des logs
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR
define('LOG_MAX_SIZE', 10485760); // Taille maximale des fichiers de log (10MB)
define('LOG_MAX_FILES', 5); // Nombre maximum de fichiers de log

// Configuration des exports
define('EXPORT_MAX_RECORDS', 10000); // Nombre maximum d'enregistrements à exporter
define('EXPORT_PATH', 'exports/'); // Dossier des exports

// Configuration de la pagination
define('PAGINATION_DEFAULT_LIMIT', 50); // Nombre d'éléments par page par défaut
define('PAGINATION_MAX_LIMIT', 500); // Nombre maximum d'éléments par page

// Configuration de la recherche
define('SEARCH_MIN_LENGTH', 2); // Longueur minimale pour la recherche
define('SEARCH_MAX_RESULTS', 1000); // Nombre maximum de résultats de recherche

// Messages de l'application
define('MSG_SUCCESS_ADD', 'Adresse IP ajoutée avec succès');
define('MSG_SUCCESS_UPDATE', 'Adresse IP mise à jour avec succès');
define('MSG_SUCCESS_DELETE', 'Adresse IP supprimée avec succès');
define('MSG_ERROR_VALIDATION', 'Erreur de validation des données');
define('MSG_ERROR_DATABASE', 'Erreur de base de données');
define('MSG_ERROR_CSRF', 'Token CSRF invalide');

// Configuration des timeouts
define('DB_TIMEOUT', 30); // Timeout de connexion à la base de données
define('REQUEST_TIMEOUT', 60); // Timeout des requêtes HTTP
