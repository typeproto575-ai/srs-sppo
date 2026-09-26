-- =====================================================================
-- 030: Let certificate_requests.purpose hold every purpose the public
--      forms offer
-- =====================================================================
-- The public certificate forms (baptismal, confirmation, first communion,
-- marriage) offer purposes such as godparent_sponsor, parish_transfer,
-- seminary_ministry, government_civil, legal_tribunal and genealogy, but the
-- column was still an ENUM of the older list (marriage, school, employment,
-- travel, civil_registration, immigration, legal, other). Choosing one of the
-- newer purposes made the INSERT fail (strict SQL mode: "Data truncated") or
-- silently saved an empty purpose (non-strict mode), so the request was lost.
--
-- VARCHAR keeps every existing value and stops the list from drifting out of
-- sync with the forms again. The forms remain the source of allowed values.
--
-- Safe to re-run: MODIFY to the same definition is a no-op.
-- =====================================================================

ALTER TABLE certificate_requests
  MODIFY COLUMN purpose VARCHAR(40) NOT NULL;
