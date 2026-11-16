SET FOREIGN_KEY_CHECKS=0 
-- SRS-SPPO v2 ANIME DEMO SEED v2  
-- Explicit column names matching database/v2/schema.sql  
-- Every INSERT lists columns explicitly to avoid positional mismatches  
-- legitimacy is always NULL on sacrament_records: new entries never store it  
-- No money amounts: payment is manual paid_at / paid_by stamp  
-- Identity lives only in persons: no identity fields copied to other tables  
USE srs_sppo_db  
SET NAMES utf8mb4 
INSERT INTO persons (id, first_name, middle_name, last_name, suffix, sex, birth_date, birth_place, father_name, mother_maiden_name, father_person_id, mother_person_id, address, contact_number, observanda, email, public_reference, baptismal_status, is_deceased, death_date, created_at, updated_at) VALUES (1, 'Tatsuo', NULL, 'Kirishima', NULL, 'male', '1945-03-15', 'Tokyo', 'Kenji Kirishima', 'Miyako Kirishima', NULL, NULL, 'Kanto Region', '0901111111', NULL, 'tatsuo@example.com', NULL, 0, NULL, NOW(), NOW()) 
