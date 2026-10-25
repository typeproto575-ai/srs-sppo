-- Keep public-facing event descriptions separate from staff-only notes.
ALTER TABLE schedule_events
  ADD COLUMN public_details TEXT NULL
  AFTER title;
