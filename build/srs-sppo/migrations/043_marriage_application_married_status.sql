-- Add the current civil marital status option used by public and walk-in marriage applications.
ALTER TABLE marriage_applications
  MODIFY groom_marital_status ENUM('single', 'widowed', 'annulled', 'married') NOT NULL,
  MODIFY bride_marital_status ENUM('single', 'widowed', 'annulled', 'married') NOT NULL;
