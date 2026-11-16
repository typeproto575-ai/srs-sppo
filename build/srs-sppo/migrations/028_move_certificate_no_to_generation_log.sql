-- Certificate numbers belong to issued certificate copies, not register rows.
ALTER TABLE certificate_generation_log
  ADD COLUMN certificate_no VARCHAR(20) NULL AFTER print_status;

ALTER TABLE baptismal_records
  DROP COLUMN certificate_no;