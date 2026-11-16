-- Allow certificate requesters to omit birthplace when they do not know it.
-- The child's name, birthdate, and parent names remain the primary lookup details.
ALTER TABLE baptismal_certificate_requests
  MODIFY COLUMN birthplace VARCHAR(150) NULL;
