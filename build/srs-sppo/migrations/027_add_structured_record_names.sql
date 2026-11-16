-- Add structured name components while retaining the existing full-name fields.
ALTER TABLE baptismal_records
    ADD COLUMN child_first_name VARCHAR(100) NULL AFTER child_full_name,
    ADD COLUMN child_middle_name VARCHAR(100) NULL AFTER child_first_name,
    ADD COLUMN child_last_name VARCHAR(100) NULL AFTER child_middle_name,
    ADD COLUMN child_suffix VARCHAR(20) NULL AFTER child_last_name;

ALTER TABLE confirmation_records
    ADD COLUMN confirmand_first_name VARCHAR(100) NULL AFTER confirmand_full_name,
    ADD COLUMN confirmand_middle_name VARCHAR(100) NULL AFTER confirmand_first_name,
    ADD COLUMN confirmand_last_name VARCHAR(100) NULL AFTER confirmand_middle_name,
    ADD COLUMN confirmand_suffix VARCHAR(20) NULL AFTER confirmand_last_name;

ALTER TABLE first_communion_records
    ADD COLUMN communicant_first_name VARCHAR(100) NULL AFTER communicant_full_name,
    ADD COLUMN communicant_middle_name VARCHAR(100) NULL AFTER communicant_first_name,
    ADD COLUMN communicant_last_name VARCHAR(100) NULL AFTER communicant_middle_name,
    ADD COLUMN communicant_suffix VARCHAR(20) NULL AFTER communicant_last_name;

ALTER TABLE marriage_records
    ADD COLUMN husband_first_name VARCHAR(100) NULL AFTER husband_full_name,
    ADD COLUMN husband_middle_name VARCHAR(100) NULL AFTER husband_first_name,
    ADD COLUMN husband_last_name VARCHAR(100) NULL AFTER husband_middle_name,
    ADD COLUMN husband_suffix VARCHAR(20) NULL AFTER husband_last_name,
    ADD COLUMN wife_first_name VARCHAR(100) NULL AFTER wife_maiden_name,
    ADD COLUMN wife_middle_name VARCHAR(100) NULL AFTER wife_first_name,
    ADD COLUMN wife_last_name VARCHAR(100) NULL AFTER wife_middle_name,
    ADD COLUMN wife_suffix VARCHAR(20) NULL AFTER wife_last_name;