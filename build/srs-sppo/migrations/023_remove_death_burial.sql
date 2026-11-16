-- Remove the retired Death/Burial registry and its identity links.
-- Run this once against an existing database after taking a verified backup.

DROP TABLE IF EXISTS death_burial_records;
