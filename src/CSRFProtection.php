<?php

class CSRFProtection {
    
    private static $tokenName = 'csrf_token';
    private static $sessionKey = 'csrf_tokens';
    
    /**
     * Génère un token CSRF
     */
    public static function generateToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $token = bin2hex(random_bytes(32));
        
        if (!isset($_SESSION[self::$sessionKey])) {
            $_SESSION[self::$sessionKey] = [];
        }
        
        $_SESSION[self::$sessionKey][$token] = time();
        
        // Nettoyer les anciens tokens (plus de 1 heure)
        self::cleanOldTokens();
        
        return $token;
    }
    
    /**
     * Valide un token CSRF
     */
    public static function validateToken($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($token) || !isset($_SESSION[self::$sessionKey][$token])) {
            return false;
        }
        
        // Vérifier que le token n'est pas trop ancien (1 heure)
        $tokenTime = $_SESSION[self::$sessionKey][$token];
        if (time() - $tokenTime > 3600) {
            unset($_SESSION[self::$sessionKey][$token]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Génère un champ caché avec le token CSRF
     */
    public static function getHiddenField() {
        $token = self::generateToken();
        return '<input type="hidden" name="' . self::$tokenName . '" value="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * Vérifie le token CSRF dans une requête POST
     */
    public static function checkRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST[self::$tokenName] ?? '';
            if (!self::validateToken($token)) {
                http_response_code(403);
                die('Token CSRF invalide');
            }
        }
    }
    
    /**
     * Nettoie les anciens tokens
     */
    private static function cleanOldTokens() {
        $currentTime = time();
        foreach ($_SESSION[self::$sessionKey] as $token => $time) {
            if ($currentTime - $time > 3600) {
                unset($_SESSION[self::$sessionKey][$token]);
            }
        }
    }
}
