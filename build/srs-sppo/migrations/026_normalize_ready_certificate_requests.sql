-- A request cannot be ready for pickup without an active certificate generation log.
-- Seed/legacy rows in this state still need generation, so return them to approved.
UPDATE certificate_requests cr
LEFT JOIN certificate_generation_log gl
  ON gl.certificate_request_id = cr.id
 AND gl.superseded_at IS NULL
SET cr.status = 'approved'
WHERE cr.status = 'ready_for_pickup'
  AND gl.id IS NULL;
