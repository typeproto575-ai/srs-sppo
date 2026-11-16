-- =====================================================================
-- 036: notification type for the awaiting-payment stage
-- Purpose: A sacrament application can be placed in (and displayed at)
--          the 'awaiting_payment' status. notify_sacrament_application_status()
--          builds its type as 'application_' . $status, so this stage needs
--          'application_awaiting_payment' in the notifications.type ENUM.
--          Without it the type is silently truncated to '' in non-strict
--          sql_mode (the same problem migrations 015 and 016 fixed).
--
-- Safe to re-run: MODIFY to the same definition is a no-op. No existing
-- value is removed.
-- =====================================================================

ALTER TABLE notifications
  MODIFY type ENUM('request_new','application_new','request_ready','event_reminder','conflict',
                   'request_under_review','request_approved','request_rejected',
                   'application_submitted','application_awaiting_payment','application_documents_pending','application_interview_scheduled',
                   'application_banns_in_progress','application_seminar_pending','application_ready_for_approval',
                   'application_approved','application_scheduled','application_completed','application_rejected',
                   'certificate_picked_up') NOT NULL;
