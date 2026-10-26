-- Runs once, when the postgres container starts with an empty data volume.
-- The development database ("knowledge") is created from POSTGRES_DB;
-- this adds the dedicated database used by the PHPUnit suite.
CREATE DATABASE knowledge_testing;
