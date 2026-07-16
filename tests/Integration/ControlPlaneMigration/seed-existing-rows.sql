\set ON_ERROR_STOP on

INSERT INTO application_settings (application_id, created_at, updated_at)
SELECT generated_id, '2026-07-12 20:52:00'::timestamp, '2026-07-12 20:52:00'::timestamp
FROM generate_series(100000, 108191) AS generated_id;

INSERT INTO application_deployment_queues (
    application_id,
    deployment_uuid,
    status,
    horizon_job_worker,
    created_at,
    updated_at
)
VALUES
    ('control-plane-migration-lab', 'control-plane-migration-queued', 'queued', NULL, '2026-07-12 20:52:01', '2026-07-12 20:52:01'),
    ('control-plane-migration-lab', 'control-plane-migration-in-progress', 'in_progress', NULL, '2026-07-12 20:52:02', '2026-07-12 20:52:02');
