-- Store non-booking marriage schedule proposals and staff-recorded agreement.
-- A proposal does not create or reserve a schedule_events row; final confirmation
-- still rechecks availability and creates or updates the calendar event.
ALTER TABLE sacrament_applications
  ADD COLUMN proposed_schedule_date DATE NULL AFTER preferred_time,
  ADD COLUMN proposed_schedule_start_time TIME NULL AFTER proposed_schedule_date,
  ADD COLUMN proposed_schedule_end_time TIME NULL AFTER proposed_schedule_start_time,
  ADD COLUMN schedule_proposal_status ENUM('draft','sent','accepted','confirmed') NULL
    AFTER proposed_schedule_end_time,
  ADD COLUMN schedule_proposal_sent_at DATETIME NULL AFTER schedule_proposal_status,
  ADD COLUMN schedule_proposal_accepted_at DATETIME NULL AFTER schedule_proposal_sent_at,
  ADD COLUMN schedule_proposal_accepted_by INT UNSIGNED NULL AFTER schedule_proposal_accepted_at,
  ADD INDEX idx_sa_schedule_proposal (sacrament_type, schedule_proposal_status),
  ADD CONSTRAINT fk_sa_schedule_proposal_acceptor
    FOREIGN KEY (schedule_proposal_accepted_by) REFERENCES users(id) ON DELETE SET NULL;
