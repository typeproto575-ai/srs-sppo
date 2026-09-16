-- 015: Extend notifications.type ENUM with the new certificate status-change
-- notification types introduced in admin/requests/certificate-detail.php:
--   request_under_review (pending -> under_review)
--   request_approved     (under_review -> approved)
--   request_rejected     (any -> rejected)
-- Without this, notify() inserts are silently truncated to '' by the ENUM
-- (same failure mode as certificate_requests.status 'completed').

ALTER TABLE notifications
  MODIFY type ENUM('request_new','application_new','request_ready','event_reminder','conflict',
                   'request_under_review','request_approved','request_rejected') NOT NULL;