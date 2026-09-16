ALTER TABLE notifications
  MODIFY type ENUM('request_new','application_new','request_ready','event_reminder','conflict',
                   'request_under_review','request_approved','request_rejected',
                   'application_submitted','application_documents_pending','application_interview_scheduled',
                   'application_banns_in_progress','application_seminar_pending','application_ready_for_approval',
                   'application_approved','application_scheduled','application_completed','application_rejected') NOT NULL;