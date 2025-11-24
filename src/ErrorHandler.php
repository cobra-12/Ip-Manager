<?php

class ErrorHandler {
    
    private static $logFile = '../logs/application.log';
    
    /**
     * Initialise le gestionnaire d'erreurs
     */
    public static function init() {
        // Créer le dossier logs s'il n'existe pas
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Définir le gestionnaire d'erreurs personnalisé
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
    }
    
    /**
     * Gère les erreurs PHP
     */
    public static function handleError($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        $error = [
            'type' => 'Error',
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        self::log($error);
        return true;
    }
    
    /**
     * Gère les exceptions non capturées
     */
    public static function handleException($exception) {
        $error = [
            'type' => 'Exception',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        self::log($error);
        
        // Afficher une page d'erreur générique
        http_response_code(500);
        echo "Une erreur interne s'est produite. Veuillez réessayer plus tard.";
        exit;
    }
    
    /**
     * Enregistre un message dans le log
     */
    public static function log($data) {
        $logEntry = json_encode($data) . "\n";
        file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Enregistre une erreur d'application
     */
    public static function logError($message, $context = []) {
        $error = [
            'type' => 'Application Error',
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        self::log($error);
    }
    
    /**
     * Enregistre une activité utilisateur
     */
    public static function logActivity($action, $details = []) {
        $activity = [
            'type' => 'Activity',
            'action' => $action,
            'details' => $details,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        self::log($activity);
    }
}
