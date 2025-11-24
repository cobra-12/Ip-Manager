<?php
/**
 * Téléchargement du modèle CSV pour l'import
 * Ce fichier génère un fichier CSV de démonstration pour l'import d'adresses IP
 */

// Nom du fichier
$filename = 'modele-import-ip.csv';

// En-têtes HTTP pour le téléchargement
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Créer un pointeur de fichier de sortie
$output = fopen('php://output', 'w');

// Ajouter l'en-tête BOM pour Excel
fputs($output, "\xEF\xBB\xBF");

// En-têtes du fichier
$headers = ['IP ADDRESS', 'STATUS', 'VLAN', 'CUSTOMER NAMES', 'VILLE'];
fputcsv($output, $headers, ';');

// Exemples de données
$examples = [
    ['172.22.250.2', 'up', '413, 8', 'DOUALA', 'DOUALA'],
    ['172.22.250.3', 'down', '', '', 'DOUALA'],
    ['172.22.250.4', 'up', '413, 556', 'WANTSUK-VODACOM', 'DOUALA'],
    ['172.22.250.5', 'down', '', '', 'DOUALA'],
    ['172.22.250.6', 'up', '2,210,413', '', 'DOUALA'],
    ['192.168.1.10', 'up', 'VLAN20', 'Client Test', 'Yaounde'],
    ['192.168.1.11', 'down', 'VLAN30', '', 'Douala'],
];

// Écrire les exemples
foreach ($examples as $row) {
    fputcsv($output, $row, ';');
}

fclose($output);
exit;
