-- Decouples "internal" (unlimited/free usage, treated as Roaming Nepal
-- staff) from the `role` column. Previously the only way to grant this was
-- to set role='internal', which only worked for agent-tier accounts and
-- would have destroyed an agency owner's owner-level permissions (team
-- management, branding) had it been applied to them, since those checks
-- require role='owner'.
ALTER TABLE users
  ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

-- Carry forward any existing role='internal' accounts so they keep their
-- unlimited status under the new flag.
UPDATE users SET is_internal = 1, role = 'agent' WHERE role = 'internal';
