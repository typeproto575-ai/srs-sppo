-- 013: Prerequisite flags for sacrament_application approval gates
--
-- baptismal_applications:
--   documents_verified : secretary confirms required documents were presented
--   seminar_attended   : godparents/parents seminar completed
-- confirmation_applications / first_communion_applications:
--   seminar_attended   : seminar completed (documents side is already covered
--                        by baptismal_cert_status on those tables)
-- Marriage needs no new columns: interview, pre-Cana, banns 1/2/3, and both
-- parties' baptismal/confirmation cert statuses already exist.

ALTER TABLE baptismal_applications
  ADD COLUMN IF NOT EXISTS documents_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
  ADD COLUMN IF NOT EXISTS seminar_attended   TINYINT(1) NOT NULL DEFAULT 0 AFTER documents_verified;

ALTER TABLE confirmation_applications
  ADD COLUMN IF NOT EXISTS seminar_attended TINYINT(1) NOT NULL DEFAULT 0 AFTER age_eligibility_flag;

ALTER TABLE first_communion_applications
  ADD COLUMN IF NOT EXISTS seminar_attended TINYINT(1) NOT NULL DEFAULT 0 AFTER age_eligibility_flag;
