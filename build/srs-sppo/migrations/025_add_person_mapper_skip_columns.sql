-- Person Mapper: allow ambiguous unlinked rows to be set aside without
-- treating them as linked records.

ALTER TABLE baptismal_records
    ADD COLUMN mapper_skipped_at DATETIME NULL AFTER person_id;

ALTER TABLE confirmation_records
    ADD COLUMN mapper_skipped_at DATETIME NULL AFTER person_id;

ALTER TABLE first_communion_records
    ADD COLUMN mapper_skipped_at DATETIME NULL AFTER person_id;

ALTER TABLE marriage_records
    ADD COLUMN husband_mapper_skipped_at DATETIME NULL AFTER husband_person_id,
    ADD COLUMN wife_mapper_skipped_at DATETIME NULL AFTER husband_mapper_skipped_at;