-- Remove photo_url column from sightings table
ALTER TABLE sightings DROP COLUMN IF EXISTS photo_url;
