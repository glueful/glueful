-- Drop existing tables if needed (in reverse dependency order)
DROP TABLE IF EXISTS user_roles_lookup CASCADE;
DROP TABLE IF EXISTS permissions CASCADE;
DROP TABLE IF EXISTS blobs CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS roles CASCADE;

-- Create primary tables (no foreign key dependencies)
\i tables/users.sql
\i tables/roles.sql

-- Create secondary tables (depend on primary tables)
\i tables/permissions.sql
\i tables/blobs.sql
\i tables/user_roles_lookup.sql

-- Create functions for user management
\i functions/triggers.sql

-- Add initial data
\i seed/001_admin_user.sql
