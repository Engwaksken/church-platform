-- GOOD SAMARITAN: allow collections that are not from one member (e.g. a basket collection).
-- Run once after database/good_samaritan.sql. It is safe to re-run.
ALTER TABLE good_samaritan MODIFY member_id INT NULL;
