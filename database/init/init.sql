-- Drop existing tables if needed (in reverse dependency order)
DROP TABLE IF EXISTS schema_versions;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS auth_sessions;
DROP TABLE IF EXISTS user_roles_lookup;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS blobs;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS profiles;
DROP TABLE IF EXISTS roles;

-- Create primary tables (no foreign key dependencies)
SOURCE tables/users.sql;
SOURCE tables/roles.sql;
SOURCE tables/audit_logs.sql;

-- Create secondary tables (depend on primary tables)
SOURCE tables/permissions.sql;
SOURCE tables/blobs.sql;
SOURCE tables/user_roles_lookup.sql;
SOURCE tables/auth_sessions.sql;
SOURCE tables/profiles.sql;

-- Create functions for user management
SOURCE functions/nanoid.sql;
SOURCE functions/triggers.sql;
SOURCE functions/audit_triggers.sql;

-- Add initial data
SOURCE seed/001_admin_user.sql;
