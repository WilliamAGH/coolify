\set ON_ERROR_STOP on

SELECT
    'application_settings'
    || '|count=' || count(*)
    || '|md5=' || md5(COALESCE(string_agg(
        (to_jsonb(application_setting) - 'is_blue_green_deployment_enabled')::text,
        E'\n' ORDER BY application_setting.id
    ), ''))
FROM application_settings AS application_setting
WHERE application_setting.application_id BETWEEN 100000 AND 108191;

SELECT
    'application_deployment_queues'
    || '|count=' || count(*)
    || '|md5=' || md5(COALESCE(string_agg(
        (to_jsonb(deployment_queue) - ARRAY[
            'blue_green_color',
            'blue_green_phase',
            'blue_green_routing_revision',
            'blue_green_previous_container_id',
            'blue_green_candidate_container_id',
            'blue_green_rollback_managed_filename',
            'blue_green_routing_mutated_at'
        ])::text,
        E'\n' ORDER BY deployment_queue.id
    ), ''))
FROM application_deployment_queues AS deployment_queue
WHERE deployment_queue.deployment_uuid IN (
    'control-plane-migration-queued',
    'control-plane-migration-in-progress'
);
