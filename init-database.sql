-- Wildlife Map Database Schema
-- This initializes the database with all required tables

-- Enable PostGIS extension
CREATE EXTENSION IF NOT EXISTS postgis;

-- Create sightings table
CREATE TABLE IF NOT EXISTS sightings (
    id SERIAL PRIMARY KEY,
    species VARCHAR(100) NOT NULL,
    photo_url VARCHAR(500),
    location GEOMETRY(Point, 4326) NOT NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    user_id VARCHAR(100),
    device_token VARCHAR(255),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE,
    last_confirmed_at TIMESTAMP WITH TIME ZONE
);

-- Create index on location for spatial queries
CREATE INDEX IF NOT EXISTS idx_sightings_location ON sightings USING GIST(location);

-- Create index on expires_at for efficient expiration checks
CREATE INDEX IF NOT EXISTS idx_sightings_expires_at ON sightings(expires_at);

-- Create index on created_at for sorting
CREATE INDEX IF NOT EXISTS idx_sightings_created_at ON sightings(created_at DESC);

-- Set initial expiration times for any existing records
UPDATE sightings 
SET expires_at = COALESCE(expires_at, created_at + INTERVAL '4 hours'),
    last_confirmed_at = COALESCE(last_confirmed_at, created_at)
WHERE expires_at IS NULL OR last_confirmed_at IS NULL;

-- Create function to automatically set expiration on new records
CREATE OR REPLACE FUNCTION set_expiration_on_insert()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.expires_at IS NULL THEN
        NEW.expires_at := NEW.created_at + INTERVAL '4 hours';
    END IF;
    IF NEW.last_confirmed_at IS NULL THEN
        NEW.last_confirmed_at := NEW.created_at;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create trigger to automatically set expiration
DROP TRIGGER IF EXISTS set_expiration_trigger ON sightings;
CREATE TRIGGER set_expiration_trigger
    BEFORE INSERT ON sightings
    FOR EACH ROW
    EXECUTE FUNCTION set_expiration_on_insert();
