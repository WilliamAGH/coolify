CREATE TABLE control_plane_widget (
    id integer PRIMARY KEY,
    name text NOT NULL,
    enabled boolean NOT NULL
);

INSERT INTO control_plane_widget (id, name, enabled) VALUES
    (1, 'alpha', true),
    (2, 'beta', false),
    (3, 'gamma', true);

CREATE TABLE migrations (
    id serial PRIMARY KEY,
    migration text NOT NULL UNIQUE,
    batch integer NOT NULL
);

INSERT INTO migrations (migration, batch)
VALUES ('2026_07_01_000000_control_plane_baseline', 1);

CREATE TABLE live_write_probe (
    id bigserial PRIMARY KEY,
    marker text NOT NULL
);

CREATE TABLE application_deployment_queues (
    id serial PRIMARY KEY,
    status text NOT NULL
);

CREATE TABLE scheduled_database_backup_executions (
    id serial PRIMARY KEY,
    status text NOT NULL
);

CREATE TABLE scheduled_task_executions (
    id serial PRIMARY KEY,
    status text NOT NULL
);

CREATE TABLE docker_cleanup_executions (
    id serial PRIMARY KEY,
    status text NOT NULL
);

CREATE ROLE coolify_app LOGIN;
GRANT CONNECT ON DATABASE coolify TO coolify_app;
GRANT USAGE ON SCHEMA public TO coolify_app;
GRANT SELECT, INSERT ON live_write_probe TO coolify_app;
GRANT SELECT, INSERT, UPDATE ON scheduled_task_executions TO coolify_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO coolify_app;

CREATE TABLE snapshot_payload AS
SELECT value AS id, md5(value::text) AS payload
FROM generate_series(1, 500000) AS generated(value);

ALTER TABLE snapshot_payload ADD PRIMARY KEY (id);
