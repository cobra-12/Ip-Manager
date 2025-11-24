<?php

class Validation {
    
    /**
     * Valide un nom de client
     */
    public static function validateCustomerName($name) {
        if (empty($name)) {
            return false;
        }
        
        // Un nom de client doit contenir entre 1 et 100 caractères
        return strlen(trim($name)) >= 1 && strlen(trim($name)) <= 100;
    }
    
    /**
     * Valide une ville
     */
    public static function validateCity($city) {
        return in_array($city, ['Douala', 'Yaounde']);
    }
    
    /**
     * Nettoie et valide les données d'entrée
     */
    public static function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Valide toutes les données d'une adresse IP
     */
    public static function validateIPData($ip, $vlan, $customer_name = '', $city = 'Douala') {
        $errors = [];
        
        if (empty($ip)) {
            $errors[] = 'Adresse IP requise';
        }
        
        if (empty($vlan)) {
            $errors[] = 'VLAN requis';
        }
        
        if (!empty($customer_name) && !self::validateCustomerName($customer_name)) {
            $errors[] = 'Nom de client invalide';
        }
        
        if (!self::validateCity($city)) {
            $errors[] = 'Ville invalide';
        }
        
        return $errors;
    }
}