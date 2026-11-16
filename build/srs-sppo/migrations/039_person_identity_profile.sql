-- Migration 039: Make person identity fields and baptismal status accurate.
-- Baptism is derived from a linked parish baptismal register row; absence of
-- a link is unknown, not proof that a person is unbaptized.

ALTER TABLE persons
  MODIFY baptismal_status
    ENUM('baptized_catholic','baptized_non_catholic','unbaptized') DEFAULT NULL;

UPDATE persons p
SET baptismal_status = CASE
  WHEN EXISTS (
    SELECT 1 FROM baptismal_records br WHERE br.person_id = p.id
  ) THEN 'baptized_catholic'
  ELSE NULL
END;
