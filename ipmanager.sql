-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : lun. 24 nov. 2025 à 09:24
-- Version du serveur : 9.1.0
-- Version de PHP : 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `ipmanager`
--

-- --------------------------------------------------------

--
-- Structure de la table `address_vlans`
--

DROP TABLE IF EXISTS `address_vlans`;
CREATE TABLE IF NOT EXISTS `address_vlans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `address_id` int NOT NULL,
  `vlan_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_address_vlan` (`address_id`,`vlan_id`),
  KEY `idx_address_id` (`address_id`),
  KEY `idx_vlan_id` (`vlan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `cities`
--

DROP TABLE IF EXISTS `cities`;
CREATE TABLE IF NOT EXISTS `cities` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `cities`
--

INSERT INTO `cities` (`id`, `name`) VALUES
(1, 'Abong-Mbang'),
(2, 'Akom II'),
(3, 'Ambam'),
(4, 'Bafang'),
(5, 'Bafia'),
(6, 'Bafoussam'),
(7, 'Bali'),
(8, 'Bamenda'),
(9, 'Bangangté'),
(10, 'Banyo'),
(12, 'Bertoua'),
(15, 'Bogo'),
(16, 'Bonamoussadi'),
(17, 'Bongor'),
(13, 'Buea'),
(19, 'Campo'),
(20, 'Dchang'),
(22, 'Douala'),
(21, 'Dschang'),
(23, 'Ebolowa'),
(24, 'Edéa'),
(26, 'Eseka'),
(27, 'Foumban'),
(28, 'Foumbot'),
(29, 'Garoua'),
(30, 'Garoua-Boulaï'),
(31, 'Guider'),
(32, 'Kousseri'),
(33, 'Koutaba'),
(34, 'Kribi'),
(35, 'Kumba'),
(36, 'Limbe'),
(38, 'Lolodorf'),
(39, 'Maroua'),
(40, 'Mbalmayo'),
(42, 'Mbandjock'),
(41, 'Mbanga'),
(43, 'Mbouda'),
(44, 'Mora'),
(45, 'Mutengene'),
(46, 'Ngaoundere'),
(48, 'Nguti'),
(49, 'Nkongsamba'),
(50, 'Nkoteng'),
(51, 'Sangmelima'),
(53, 'Souza'),
(54, 'Tibati'),
(55, 'Tiko'),
(56, 'Touboro'),
(57, 'Wum'),
(58, 'Yabassi'),
(59, 'Yagoua'),
(60, 'Yaounde');

-- --------------------------------------------------------

--
-- Structure de la table `ip_addresses`
--

DROP TABLE IF EXISTS `ip_addresses`;
CREATE TABLE IF NOT EXISTS `ip_addresses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vlan` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `city` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Douala',
  `status` enum('UP','DOWN') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DOWN',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_vlan` (`vlan`),
  KEY `idx_status` (`status`),
  KEY `idx_customer_name` (`customer_name`),
  KEY `idx_city` (`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`),
  KEY `idx_email` (`email`),
  KEY `idx_city` (`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `user_addresses`
--

DROP TABLE IF EXISTS `user_addresses`;
CREATE TABLE IF NOT EXISTS `user_addresses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `address_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_address` (`user_id`,`address_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_address_id` (`address_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `vlans`
--

DROP TABLE IF EXISTS `vlans`;
CREATE TABLE IF NOT EXISTS `vlans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vlan_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vlan_number` (`vlan_number`),
  KEY `idx_vlan_number` (`vlan_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `address_vlans`
--
ALTER TABLE `address_vlans`
  ADD CONSTRAINT `address_vlans_ibfk_1` FOREIGN KEY (`address_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `address_vlans_ibfk_2` FOREIGN KEY (`vlan_id`) REFERENCES `vlans` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `user_addresses`
--
ALTER TABLE `user_addresses`
  ADD CONSTRAINT `user_addresses_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_addresses_ibfk_2` FOREIGN KEY (`address_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
