-- Store the parish-issued certificate number separately from the register entry number.
ALTER TABLE baptismal_records
  ADD COLUMN certificate_no VARCHAR(20) NULL AFTER entry_no;
