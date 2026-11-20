-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: srs_sppo_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `content` text NOT NULL,
  `event_date` date DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `posted_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_an_poster` (`posted_by`),
  CONSTRAINT `fk_an_poster` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcements`
--

LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `actor_name` varchar(150) DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `target_table` varchar(100) DEFAULT NULL,
  `target_id` int(10) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_al_target` (`target_table`,`target_id`),
  KEY `idx_al_created` (`created_at`),
  KEY `fk_al_user` (`user_id`),
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `certificate_requests`
--

DROP TABLE IF EXISTS `certificate_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `certificate_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_no` varchar(20) NOT NULL,
  `channel` enum('online','onsite') NOT NULL DEFAULT 'onsite',
  `sacrament_type` enum('baptism','first_communion','confirmation','marriage') NOT NULL,
  `subject_name` varchar(150) NOT NULL,
  `subject_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`subject_details`)),
  `requester_name` varchar(150) NOT NULL,
  `requester_relationship` enum('self','parent','guardian','spouse','sibling','child','other') NOT NULL DEFAULT 'self',
  `requester_relationship_other` varchar(100) DEFAULT NULL,
  `requester_email` varchar(150) NOT NULL,
  `requester_contact_number` varchar(30) DEFAULT NULL,
  `purpose` varchar(40) DEFAULT NULL,
  `purpose_other` varchar(150) DEFAULT NULL,
  `privacy_consent_at` datetime DEFAULT NULL,
  `person_id` int(10) unsigned DEFAULT NULL,
  `record_id` int(10) unsigned DEFAULT NULL,
  `matched_by` int(10) unsigned DEFAULT NULL,
  `matched_at` datetime DEFAULT NULL,
  `status` enum('new','matched','for_signature','ready','released','rejected','cancelled') NOT NULL DEFAULT 'new',
  `certificate_no` varchar(30) DEFAULT NULL,
  `printed_by` int(10) unsigned DEFAULT NULL,
  `printed_at` datetime DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `claimant_type` enum('self','representative') DEFAULT NULL,
  `representative_name` varchar(150) DEFAULT NULL,
  `representative_relationship` varchar(120) DEFAULT NULL,
  `id_verified` tinyint(1) NOT NULL DEFAULT 0,
  `claimant_notes` text DEFAULT NULL,
  `released_by` int(10) unsigned DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `paid_by` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `anonymized_at` datetime DEFAULT NULL,
  `retention_eligible_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_no` (`request_no`),
  KEY `idx_cr_status` (`status`),
  KEY `idx_cr_subject` (`subject_name`),
  KEY `idx_cr_person` (`person_id`),
  KEY `idx_cr_retention` (`status`,`channel`,`retention_eligible_at`,`anonymized_at`),
  KEY `fk_cr_record` (`record_id`),
  KEY `fk_cr_matched` (`matched_by`),
  KEY `fk_cr_printed` (`printed_by`),
  KEY `fk_cr_released` (`released_by`),
  KEY `fk_cr_paid` (`paid_by`),
  CONSTRAINT `fk_cr_matched` FOREIGN KEY (`matched_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cr_paid` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cr_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cr_printed` FOREIGN KEY (`printed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cr_record` FOREIGN KEY (`record_id`) REFERENCES `sacrament_records` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cr_released` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `certificate_requests`
--

LOCK TABLES `certificate_requests` WRITE;
/*!40000 ALTER TABLE `certificate_requests` DISABLE KEYS */;
INSERT INTO `certificate_requests` VALUES (1,'CR-ANIME-0001','online','baptism','Himawari Uzumaki',NULL,'Himawari Uzumaki','self',NULL,'himawari@example.com',NULL,'school',NULL,NULL,NULL,NULL,NULL,NULL,'new',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:10:25','2026-11-17 05:10:25'),(2,'CR-ANIME-0002','onsite','baptism','Satsuki Kirishima',NULL,'Ricardo Mendoza','self',NULL,'ricardo.mendoza@example.com',NULL,'school',NULL,NULL,NULL,NULL,NULL,NULL,'matched',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:10:25','2026-11-17 05:10:25'),(3,'CR-ANIME-0003','onsite','first_communion','Lucy Heartfilia',NULL,'Lucy Heartfilia','self',NULL,'lucy@example.com',NULL,'personal',NULL,NULL,NULL,NULL,NULL,NULL,'ready',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:10:25','2026-11-17 05:10:25'),(5,'CR-ANIME-0005','online','baptism','Kiki Kizz',NULL,'Kiki Kizz','self',NULL,'kiki@example.com',NULL,'marriage',NULL,NULL,NULL,NULL,NULL,NULL,'rejected',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:10:25','2026-11-17 05:10:25');
/*!40000 ALTER TABLE `certificate_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `funeral_records`
--

DROP TABLE IF EXISTS `funeral_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funeral_records` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int(10) unsigned NOT NULL,
  `date_of_death` date NOT NULL,
  `date_of_burial` date DEFAULT NULL,
  `age_at_death` smallint(5) unsigned DEFAULT NULL,
  `civil_status` enum('single','married','widowed') DEFAULT NULL,
  `spouse_name` varchar(150) DEFAULT NULL,
  `parents_name` varchar(255) DEFAULT NULL,
  `sacraments_received` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sacraments_received`)),
  `cause_of_death` varchar(255) DEFAULT NULL,
  `place_of_burial` varchar(150) DEFAULT NULL,
  `service_type` enum('mass','blessing') NOT NULL,
  `service_date` date DEFAULT NULL,
  `service_time` time DEFAULT NULL,
  `minister_name` varchar(150) DEFAULT NULL,
  `book_no` varchar(20) DEFAULT NULL,
  `page_no` varchar(20) DEFAULT NULL,
  `line_no` varchar(20) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `encoded_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_funeral_person` (`person_id`),
  KEY `idx_funeral_date_of_death` (`date_of_death`),
  KEY `fk_funeral_encoder` (`encoded_by`),
  CONSTRAINT `fk_funeral_encoder` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_funeral_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `funeral_records`
--

LOCK TABLES `funeral_records` WRITE;
/*!40000 ALTER TABLE `funeral_records` DISABLE KEYS */;
INSERT INTO `funeral_records` VALUES (1,1,'1998-03-02','1998-03-05',53,'married','Yasuko Kirishima','Kirishima family','{\"baptism\": true, \"first_communion\": true, \"confirmation\": true, \"marriage\": true}','old age','Parish Cemetery','mass','1998-03-05','09:00:00','Fr. Vicente Roque','FB-001','1','1','0911111111',1,'2026-11-17 05:05:03','2026-11-17 05:05:03');
/*!40000 ALTER TABLE `funeral_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `channel` enum('inapp','email') NOT NULL,
  `type` varchar(60) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  `link` varchar(200) DEFAULT NULL,
  `recipient` varchar(150) DEFAULT NULL,
  `delivery_status` enum('sent','failed','disabled','invalid') DEFAULT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `entity_type` varchar(40) DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_no_unread` (`channel`,`is_read`,`created_at`),
  KEY `idx_no_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `persons`
--

DROP TABLE IF EXISTS `persons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `persons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(150) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `sex` enum('male','female') DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `birth_place` varchar(150) DEFAULT NULL,
  `father_name` varchar(150) DEFAULT NULL,
  `mother_maiden_name` varchar(150) DEFAULT NULL,
  `father_person_id` int(10) unsigned DEFAULT NULL,
  `mother_person_id` int(10) unsigned DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `observanda` text DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `public_reference` varchar(48) DEFAULT NULL,
  `baptismal_status` enum('baptized_catholic','baptized_non_catholic','unbaptized') DEFAULT NULL,
  `is_deceased` tinyint(1) NOT NULL DEFAULT 0,
  `death_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_persons_public_reference` (`public_reference`),
  KEY `idx_persons_name` (`last_name`,`first_name`),
  KEY `idx_persons_birth` (`birth_date`),
  KEY `fk_persons_father` (`father_person_id`),
  KEY `fk_persons_mother` (`mother_person_id`),
  CONSTRAINT `fk_persons_father` FOREIGN KEY (`father_person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_persons_mother` FOREIGN KEY (`mother_person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `persons`
--

LOCK TABLES `persons` WRITE;
/*!40000 ALTER TABLE `persons` DISABLE KEYS */;
INSERT INTO `persons` VALUES (1,'Tatsuo',NULL,'Kirishima',NULL,'male','1945-03-15',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-11-17 05:04:35','2026-11-17 05:04:35'),(2,'Yasuko',NULL,'Kirishima',NULL,'female','1947-06-20',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-11-17 05:04:35','2026-11-17 05:04:35'),(3,'Satsuki',NULL,'Kirishima',NULL,'female','1965-01-05',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-11-17 05:04:35','2026-11-17 05:04:35'),(4,'Mei',NULL,'Kirishima',NULL,'female','1968-01-10',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-11-17 05:04:35','2026-11-17 05:04:35');
/*!40000 ALTER TABLE `persons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sacrament_applications`
--

DROP TABLE IF EXISTS `sacrament_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sacrament_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_no` varchar(20) NOT NULL,
  `channel` enum('online','onsite') NOT NULL DEFAULT 'onsite',
  `sacrament_type` enum('baptism','first_communion','confirmation','marriage') NOT NULL,
  `person_id` int(10) unsigned DEFAULT NULL,
  `batch_id` int(10) unsigned DEFAULT NULL,
  `spouse_person_id` int(10) unsigned DEFAULT NULL,
  `applicant_name` varchar(150) NOT NULL,
  `applicant_email` varchar(150) NOT NULL,
  `applicant_contact` varchar(30) DEFAULT NULL,
  `preferred_date` date DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `status` enum('new','reviewing','requirements_pending','approved','scheduled','completed','rejected') NOT NULL DEFAULT 'new',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`requirements`)),
  `record_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `paid_by` int(10) unsigned DEFAULT NULL,
  `processed_by` int(10) unsigned DEFAULT NULL,
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `privacy_consent_at` datetime DEFAULT NULL,
  `anonymized_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_no` (`request_no`),
  KEY `idx_sa_status` (`status`),
  KEY `idx_sa_type` (`sacrament_type`,`preferred_date`),
  KEY `idx_sa_name` (`applicant_name`),
  KEY `idx_sa_assigned` (`assigned_to`),
  KEY `idx_sa_batch` (`batch_id`),
  KEY `fk_sa_person` (`person_id`),
  KEY `fk_sa_spouse` (`spouse_person_id`),
  KEY `fk_sa_record` (`record_id`),
  KEY `fk_sa_paid_by` (`paid_by`),
  KEY `fk_sa_processed` (`processed_by`),
  CONSTRAINT `fk_sa_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sa_batch` FOREIGN KEY (`batch_id`) REFERENCES `sacrament_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sa_paid_by` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sa_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sa_processed` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sa_record` FOREIGN KEY (`record_id`) REFERENCES `sacrament_records` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sa_spouse` FOREIGN KEY (`spouse_person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sacrament_applications`
--

LOCK TABLES `sacrament_applications` WRITE;
/*!40000 ALTER TABLE `sacrament_applications` DISABLE KEYS */;
INSERT INTO `sacrament_applications` VALUES (1,'SA-2026-ANIME01','onsite','baptism',1,NULL,NULL,'Tatsuo Kirishima','tatsuo@example.com',NULL,NULL,NULL,'approved',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:12:28','2026-11-17 05:12:28'),(2,'SA-2026-ANIME02','onsite','baptism',2,NULL,NULL,'Yasuko Kirishima','yasuko@example.com',NULL,NULL,NULL,'approved',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:12:28','2026-11-17 05:12:28'),(3,'SA-2026-ANIME03','onsite','first_communion',3,NULL,NULL,'Satsuki Kirishima','satsuki@example.com',NULL,NULL,NULL,'requirements_pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:12:28','2026-11-17 05:12:28'),(5,'SA-2026-ANIME05','online','baptism',4,NULL,NULL,'Mei Kirishima','mei@example.com',NULL,NULL,NULL,'new',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:12:28','2026-11-17 05:12:28');
/*!40000 ALTER TABLE `sacrament_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sacrament_batches`
--

DROP TABLE IF EXISTS `sacrament_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sacrament_batches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sacrament_type` enum('first_communion','confirmation') NOT NULL,
  `grade_level` varchar(32) NOT NULL,
  `school_year` char(9) NOT NULL,
  `batch_name` varchar(150) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sb_type_year_grade` (`sacrament_type`,`school_year`,`grade_level`),
  KEY `fk_sb_creator` (`created_by`),
  CONSTRAINT `fk_sb_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sacrament_batches`
--

LOCK TABLES `sacrament_batches` WRITE;
/*!40000 ALTER TABLE `sacrament_batches` DISABLE KEYS */;
INSERT INTO `sacrament_batches` VALUES (1,'first_communion','3','2026-2027','Kirishima Catechism Group 2026',1,'2026-11-17 05:09:22'),(2,'confirmation','6','2026-2027','Heartfilia Confirmation Batch 2026',1,'2026-11-17 05:09:22'),(3,'first_communion','4','2026-2027','Uzumaki First Communion 2026',1,'2026-11-17 05:09:22'),(4,'confirmation','7','2026-2027','Tsukino Confirmation Batch 2026',1,'2026-11-17 05:09:22');
/*!40000 ALTER TABLE `sacrament_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sacrament_participants`
--

DROP TABLE IF EXISTS `sacrament_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sacrament_participants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `record_id` int(10) unsigned NOT NULL,
  `role` enum('godfather','godmother','sponsor','principal_sponsor','witness') NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `residence` varchar(150) DEFAULT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_sp_record` (`record_id`),
  CONSTRAINT `fk_sp_record` FOREIGN KEY (`record_id`) REFERENCES `sacrament_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sacrament_participants`
--

LOCK TABLES `sacrament_participants` WRITE;
/*!40000 ALTER TABLE `sacrament_participants` DISABLE KEYS */;
INSERT INTO `sacrament_participants` VALUES (1,1,'godfather','Kenta Saito','Kanto Region',1),(2,1,'godmother','Yumiko Sato','Kanto Region',2),(3,2,'sponsor','Tatsuo Kirishima','Kanto Region',1),(4,3,'godmother','Mira Heartfilia','Magnolia Town',1),(5,4,'principal_sponsor','Mira Heartfilia','Magnolia Town',1),(6,4,'sponsor','Hiashi Hyuga','Konohagakure',1),(7,4,'godfather','Mamoru Chiba','Tokyo',1),(8,4,'witness','Tsunade','Konoha',2),(9,5,'godfather','Jiraiya','Konohagakure',1),(10,5,'godmother','Tsunade','Konohagakure',2);
/*!40000 ALTER TABLE `sacrament_participants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sacrament_records`
--

DROP TABLE IF EXISTS `sacrament_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sacrament_records` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sacrament_type` enum('baptism','first_communion','confirmation','marriage') NOT NULL,
  `person_id` int(10) unsigned NOT NULL,
  `spouse_person_id` int(10) unsigned DEFAULT NULL,
  `source` enum('this_parish','other_parish') NOT NULL DEFAULT 'this_parish',
  `event_date` date NOT NULL,
  `place` varchar(150) DEFAULT NULL,
  `minister_name` varchar(150) DEFAULT NULL,
  `entry_no` varchar(20) DEFAULT NULL,
  `book_no` varchar(20) DEFAULT NULL,
  `page_no` varchar(20) DEFAULT NULL,
  `line_no` varchar(20) DEFAULT NULL,
  `parents_union_type` enum('canonical','civil','common_law','unmarried') DEFAULT NULL,
  `parents_marriage_parish` varchar(255) DEFAULT NULL,
  `legitimacy` enum('legitimate','illegitimate') DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `remarks` text DEFAULT NULL,
  `book_verified_by` int(10) unsigned DEFAULT NULL,
  `book_verified_at` datetime DEFAULT NULL,
  `encoded_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sr_person` (`person_id`,`sacrament_type`),
  KEY `idx_sr_spouse` (`spouse_person_id`),
  KEY `idx_sr_type_date` (`sacrament_type`,`event_date`),
  KEY `idx_sr_book` (`book_no`,`page_no`,`line_no`),
  KEY `fk_sr_verifier` (`book_verified_by`),
  KEY `fk_sr_encoder` (`encoded_by`),
  CONSTRAINT `fk_sr_encoder` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sr_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`),
  CONSTRAINT `fk_sr_spouse` FOREIGN KEY (`spouse_person_id`) REFERENCES `persons` (`id`),
  CONSTRAINT `fk_sr_verifier` FOREIGN KEY (`book_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_sr_spouse` CHECK (`spouse_person_id` is null or `sacrament_type` = 'marriage')
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sacrament_records`
--

LOCK TABLES `sacrament_records` WRITE;
/*!40000 ALTER TABLE `sacrament_records` DISABLE KEYS */;
INSERT INTO `sacrament_records` VALUES (1,'baptism',1,NULL,'this_parish','1998-03-02','Parish Church','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:05:43','2026-11-17 05:05:43'),(2,'baptism',2,NULL,'this_parish','1975-06-20','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:06:18','2026-11-17 05:06:18'),(3,'baptism',3,NULL,'this_parish','1965-01-05','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:06:38','2026-11-17 05:06:38'),(4,'baptism',4,NULL,'this_parish','1968-01-10','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:06:38','2026-11-17 05:06:38'),(5,'first_communion',1,NULL,'this_parish','1998-05-15','St. Patrick Parish','Fr. Mateo Salonga',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:06:38','2026-11-17 05:06:38'),(6,'first_communion',2,NULL,'this_parish','1975-06-25','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:06:38','2026-11-17 05:06:38'),(7,'confirmation',1,NULL,'this_parish','2004-06-15','St. Patrick Parish','Most Rev. Rafael Cuenco',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:12','2026-11-17 05:07:12'),(8,'confirmation',2,NULL,'this_parish','2005-07-10','St. Patrick Parish','Most Rev. Rafael Cuenco',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:12','2026-11-17 05:07:12'),(9,'marriage',1,2,'this_parish','1975-06-20','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:12','2026-11-17 05:07:12'),(10,'baptism',1,NULL,'this_parish','1998-03-02','Parish Church','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(11,'first_communion',1,NULL,'this_parish','1998-05-15','St. Patrick Parish','Fr. Mateo Salonga',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(12,'confirmation',1,NULL,'this_parish','2004-06-15','St. Patrick Parish','Most Rev. Rafael Cuenco',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(13,'baptism',2,NULL,'this_parish','1975-06-20','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(14,'first_communion',2,NULL,'this_parish','1975-06-25','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(15,'confirmation',2,NULL,'this_parish','2005-07-10','St. Patrick Parish','Most Rev. Rafael Cuenco',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(16,'baptism',3,NULL,'this_parish','1965-01-05','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(17,'first_communion',3,NULL,'this_parish','1965-05-20','St. Patrick Parish','Fr. Mateo Salonga',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(18,'confirmation',3,NULL,'this_parish','1965-10-20','St. Patrick Parish','Most Rev. Rafael Cuenco',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(19,'baptism',4,NULL,'this_parish','1968-01-10','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(20,'first_communion',4,NULL,'this_parish','1968-05-15','St. Patrick Parish','Fr. Mateo Salonga',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(21,'confirmation',4,NULL,'this_parish','1968-10-20','St. Patrick Parish','Most Rev. Rafael Cuenco',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:07:53','2026-11-17 05:07:53'),(22,'marriage',1,2,'this_parish','1975-06-20','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:08:24','2026-11-17 05:08:24'),(23,'marriage',3,NULL,'this_parish','1990-06-20','St. Patrick Parish','Fr. Vicente Roque',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:08:24','2026-11-17 05:08:24'),(24,'confirmation',3,NULL,'this_parish','1998-10-20','St. Patrick Parish','Most Rev. Rafael Cuenco',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:08:24','2026-11-17 05:08:24'),(25,'confirmation',4,NULL,'this_parish','1968-10-20','St. Patrick Parish','Most Rev. Rafael Cuenco',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-17 05:08:24','2026-11-17 05:08:24');
/*!40000 ALTER TABLE `sacrament_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schedule_events`
--

DROP TABLE IF EXISTS `schedule_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schedule_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` enum('mass','wedding','baptism','other') NOT NULL,
  `title` varchar(150) NOT NULL,
  `public_details` text DEFAULT NULL,
  `event_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `application_id` int(10) unsigned DEFAULT NULL,
  `batch_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_se_batch` (`batch_id`),
  KEY `idx_se_date` (`event_date`),
  KEY `fk_se_application` (`application_id`),
  KEY `fk_se_creator` (`created_by`),
  CONSTRAINT `fk_se_application` FOREIGN KEY (`application_id`) REFERENCES `sacrament_applications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_se_batch` FOREIGN KEY (`batch_id`) REFERENCES `sacrament_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_se_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schedule_events`
--

LOCK TABLES `schedule_events` WRITE;
/*!40000 ALTER TABLE `schedule_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `schedule_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `fk_set_user` (`updated_by`),
  CONSTRAINT `fk_set_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('secretary') NOT NULL DEFAULT 'secretary',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `auth_version` int(10) unsigned NOT NULL DEFAULT 1,
  `reset_token_hash` varchar(255) DEFAULT NULL,
  `reset_expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Anime Secretary','secretary','secretary@example.com','$2y$10$QhPLnuetTwD1or8yvIQnZu0YmjSy1pIZAO10hvAhGD7vVo1AiMdr2','secretary',1,1,NULL,NULL,'2026-11-17 05:03:18','2026-11-17 05:03:18');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-17  5:13:07
