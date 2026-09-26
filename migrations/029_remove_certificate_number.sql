-- Certificate numbers are no longer generated or printed by the system.
ALTER TABLE certificate_generation_log
  DROP COLUMN certificate_no;
