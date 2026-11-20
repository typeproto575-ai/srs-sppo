-- Align baptism register union classifications and record the parish of the
-- parents' canonical marriage. Preserve existing legacy classifications.
ALTER TABLE sacrament_records
  MODIFY COLUMN parents_union_type
    ENUM('legitimate','civil','natural','canonical','common_law','unmarried') NULL DEFAULT NULL,
  ADD COLUMN parents_marriage_parish VARCHAR(255) NULL AFTER parents_union_type;

UPDATE sacrament_records
SET parents_union_type = CASE parents_union_type
  WHEN 'legitimate' THEN 'canonical'
  WHEN 'natural' THEN 'unmarried'
  ELSE parents_union_type
END
WHERE parents_union_type IN ('legitimate', 'natural');

ALTER TABLE sacrament_records
  MODIFY COLUMN parents_union_type
    ENUM('canonical','civil','common_law','unmarried') NULL DEFAULT NULL;
