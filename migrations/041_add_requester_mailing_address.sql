-- Store an optional postal mailing address for certificate request follow-up.
ALTER TABLE certificate_requests
  ADD COLUMN requester_mailing_address VARCHAR(500) NULL
  AFTER requester_contact_number;
