CREATE TABLE application_deployment_queues (
    id SERIAL PRIMARY KEY,
    status TEXT NOT NULL,
    horizon_job_worker TEXT
);

CREATE TABLE scheduled_database_backup_executions (
    id SERIAL PRIMARY KEY,
    status TEXT NOT NULL
);

CREATE TABLE scheduled_task_executions (
    id SERIAL PRIMARY KEY,
    status TEXT NOT NULL
);

CREATE TABLE docker_cleanup_executions (
    id SERIAL PRIMARY KEY,
    status TEXT NOT NULL
);

CREATE TABLE backup_quiesce_writer_samples (
    id BIGSERIAL PRIMARY KEY,
    observed_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);

CREATE TABLE applications (
    id BIGSERIAL PRIMARY KEY
);

CREATE TABLE standalone_dockers (
    id BIGSERIAL PRIMARY KEY
);

CREATE TABLE application_settings (
    id BIGSERIAL PRIMARY KEY
);

CREATE TABLE migrations (
    id SERIAL PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    batch INTEGER NOT NULL
);

INSERT INTO migrations (migration, batch)
VALUES
    ('2025_10_10_120000_create_cloud_init_scripts_table', 1),
    ('2025_10_10_120000_create_webhook_notification_settings_table', 1),
    ('2025_10_10_120001_populate_webhook_notification_settings_for_existing_teams', 1);
