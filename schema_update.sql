-- Schema update to add expiration tracking for sightings
-- Run this against the wildlife_map database

-- Add columns for expiration tracking
ALTER TABLE sightings 
ADD COLUMN IF NOT EXISTS expires_at TIMESTAMP WITH TIME ZONE,
ADD COLUMN IF NOT EXISTS last_confirmed_at TIMESTAMP WITH TIME ZONE;

-- Set initial expiration times for existing sightings (4 hours from creation)
UPDATE sightings 
SET expires_at = taken_at + INTERVAL '4 hours',
    last_confirmed_at = taken_at
WHERE expires_at IS NULL;

-- Create index for efficient expiration queries
CREATE INDEX IF NOT EXISTS idx_sightings_expires_at ON sightings(expires_at);
