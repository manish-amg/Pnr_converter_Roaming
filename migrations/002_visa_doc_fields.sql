-- Adds the fields needed for verify.php to show a meaningful summary of an
-- issued visa document, without storing the raw PNR text or full passenger
-- contact details. Run once via phpMyAdmin after schema.sql.

ALTER TABLE documents
  ADD COLUMN passenger_name VARCHAR(160) NULL AFTER type,
  ADD COLUMN route_summary  VARCHAR(120) NULL AFTER passenger_name,
  ADD COLUMN travel_date    DATE NULL AFTER route_summary,
  ADD COLUMN reference_no   VARCHAR(20) NULL AFTER travel_date;

ALTER TABLE documents
  ADD UNIQUE KEY uniq_reference_no (reference_no);
