\set ON_ERROR_STOP on
\if :{?control_plane_require_fixtures}
\else
\set control_plane_require_fixtures true
\endif

CREATE FUNCTION pg_temp.assert_true(assertion boolean, failure_message text)
RETURNS void
LANGUAGE plpgsql
AS $$
BEGIN
    IF assertion IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'CONTROL_PLANE_SCHEMA_ASSERTION: %', failure_message;
    END IF;
END;
$$;

WITH expected(table_name, ordinal_position, column_name, formatted_type, not_null, default_expression, identity_type) AS (
    VALUES
        ('application_blue_green_deployments', 1, 'id', 'bigint', true, 'nextval(''application_blue_green_deployments_id_seq''::regclass)', ''),
        ('application_blue_green_deployments', 2, 'application_id', 'bigint', true, '', ''),
        ('application_blue_green_deployments', 3, 'standalone_docker_id', 'bigint', true, '', ''),
        ('application_blue_green_deployments', 4, 'active_color', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 5, 'pending_color', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 6, 'blue_deployment_uuid', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 7, 'green_deployment_uuid', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 8, 'pending_deployment_uuid', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 9, 'legacy_container_name', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 10, 'operation_deployment_uuid', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 11, 'operation_previous_active_color', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 12, 'operation_previous_deployment_uuid', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 13, 'operation_previous_routing_revision', 'bigint', false, '', ''),
        ('application_blue_green_deployments', 14, 'operation_previous_container_name', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 15, 'operation_previous_container_id', 'character varying(64)', false, '', ''),
        ('application_blue_green_deployments', 16, 'operation_candidate_container_name', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 17, 'operation_candidate_container_id', 'character varying(64)', false, '', ''),
        ('application_blue_green_deployments', 18, 'operation_rollback_managed_filename', 'character varying(255)', false, '', ''),
        ('application_blue_green_deployments', 19, 'operation_routing_mutated_at', 'timestamp(0) without time zone', false, '', ''),
        ('application_blue_green_deployments', 20, 'operation_legacy_routing_snapshot_version', 'smallint', false, '', ''),
        ('application_blue_green_deployments', 21, 'operation_legacy_routing_snapshot', 'text', false, '', ''),
        ('application_blue_green_deployments', 22, 'operation_legacy_routing_snapshot_sha256', 'character varying(64)', false, '', ''),
        ('application_blue_green_deployments', 23, 'phase', 'character varying(255)', true, '''idle''::character varying', ''),
        ('application_blue_green_deployments', 24, 'routing_revision', 'bigint', true, '''0''::bigint', ''),
        ('application_blue_green_deployments', 25, 'created_at', 'timestamp(0) without time zone', false, '', ''),
        ('application_blue_green_deployments', 26, 'updated_at', 'timestamp(0) without time zone', false, '', ''),
        ('application_blue_green_deployments', 27, 'deactivation_operation_id', 'character varying(64)', false, '', ''),
        ('application_blue_green_deployments', 28, 'deactivation_started_at', 'timestamp(0) without time zone', false, '', ''),
        ('application_blue_green_deactivations', 1, 'id', 'bigint', true, 'nextval(''application_blue_green_deactivations_id_seq''::regclass)', ''),
        ('application_blue_green_deactivations', 2, 'application_id', 'bigint', true, '', ''),
        ('application_blue_green_deactivations', 3, 'standalone_docker_id', 'bigint', true, '', ''),
        ('application_blue_green_deactivations', 4, 'operation_id', 'character varying(64)', true, '', ''),
        ('application_blue_green_deactivations', 5, 'started_at', 'timestamp(0) without time zone', true, '', ''),
        ('application_blue_green_deactivations', 6, 'queue_cutoff_id', 'bigint', true, '''0''::bigint', ''),
        ('application_blue_green_deactivations', 7, 'phase', 'character varying(255)', true, '''deactivating''::character varying', ''),
        ('application_blue_green_deactivations', 8, 'completed_at', 'timestamp(0) without time zone', false, '', ''),
        ('application_blue_green_deactivations', 9, 'created_at', 'timestamp(0) without time zone', false, '', ''),
        ('application_blue_green_deactivations', 10, 'updated_at', 'timestamp(0) without time zone', false, '', ''),
        ('application_blue_green_deactivations', 11, 'proxy_snapshot', 'text', false, '', '')
), actual AS (
    SELECT relation.relname,
           attribute.attnum::integer,
           attribute.attname,
           format_type(attribute.atttypid, attribute.atttypmod),
           attribute.attnotnull,
           COALESCE(pg_get_expr(attribute_default.adbin, attribute_default.adrelid), ''),
           attribute.attidentity::text
    FROM pg_attribute AS attribute
    JOIN pg_class AS relation ON relation.oid = attribute.attrelid
    JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
    LEFT JOIN pg_attrdef AS attribute_default
        ON attribute_default.adrelid = attribute.attrelid
       AND attribute_default.adnum = attribute.attnum
    WHERE namespace.nspname = 'public'
      AND relation.relname IN ('application_blue_green_deployments', 'application_blue_green_deactivations')
      AND attribute.attnum > 0
      AND NOT attribute.attisdropped
), mismatch AS (
    (SELECT * FROM expected EXCEPT SELECT * FROM actual)
    UNION ALL
    (SELECT * FROM actual EXCEPT SELECT * FROM expected)
)
SELECT pg_temp.assert_true(NOT EXISTS (SELECT 1 FROM mismatch), 'new-table columns, typmods, nullability, defaults, or identity metadata differ');

WITH expected(table_name, column_name, formatted_type, not_null, default_expression, identity_type) AS (
    VALUES
        ('application_settings', 'is_blue_green_deployment_enabled', 'boolean', true, 'false', ''),
        ('application_deployment_queues', 'blue_green_color', 'character varying(255)', false, '', ''),
        ('application_deployment_queues', 'blue_green_phase', 'character varying(255)', false, '', ''),
        ('application_deployment_queues', 'blue_green_routing_revision', 'bigint', false, '', ''),
        ('application_deployment_queues', 'blue_green_previous_container_id', 'character varying(64)', false, '', ''),
        ('application_deployment_queues', 'blue_green_candidate_container_id', 'character varying(64)', false, '', ''),
        ('application_deployment_queues', 'blue_green_rollback_managed_filename', 'character varying(255)', false, '', ''),
        ('application_deployment_queues', 'blue_green_routing_mutated_at', 'timestamp(0) without time zone', false, '', '')
), actual AS (
    SELECT relation.relname,
           attribute.attname,
           format_type(attribute.atttypid, attribute.atttypmod),
           attribute.attnotnull,
           COALESCE(pg_get_expr(attribute_default.adbin, attribute_default.adrelid), ''),
           attribute.attidentity::text
    FROM pg_attribute AS attribute
    JOIN pg_class AS relation ON relation.oid = attribute.attrelid
    JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
    LEFT JOIN pg_attrdef AS attribute_default
        ON attribute_default.adrelid = attribute.attrelid
       AND attribute_default.adnum = attribute.attnum
    WHERE namespace.nspname = 'public'
      AND (
          (relation.relname = 'application_settings' AND attribute.attname LIKE '%blue_green%')
          OR (relation.relname = 'application_deployment_queues' AND attribute.attname LIKE 'blue_green_%')
      )
      AND attribute.attnum > 0
      AND NOT attribute.attisdropped
), mismatch AS (
    (SELECT * FROM expected EXCEPT SELECT * FROM actual)
    UNION ALL
    (SELECT * FROM actual EXCEPT SELECT * FROM expected)
)
SELECT pg_temp.assert_true(NOT EXISTS (SELECT 1 FROM mismatch), 'existing-table additions, typmods, nullability, defaults, or identity metadata differ');

WITH expected(table_name, index_name, key_columns, opclass_name, primary_index, unique_index) AS (
    VALUES
        ('application_blue_green_deployments', 'application_blue_green_deployments_pkey', ARRAY['id'], ARRAY['pg_catalog.int8_ops'], true, true),
        ('application_blue_green_deployments', 'app_blue_green_destination_unique', ARRAY['application_id', 'standalone_docker_id'], ARRAY['pg_catalog.int8_ops', 'pg_catalog.int8_ops'], false, true),
        ('application_blue_green_deployments', 'app_blue_green_standalone_docker_index', ARRAY['standalone_docker_id'], ARRAY['pg_catalog.int8_ops'], false, false),
        ('application_blue_green_deactivations', 'application_blue_green_deactivations_pkey', ARRAY['id'], ARRAY['pg_catalog.int8_ops'], true, true),
        ('application_blue_green_deactivations', 'app_blue_green_deactivation_unique', ARRAY['application_id', 'standalone_docker_id'], ARRAY['pg_catalog.int8_ops', 'pg_catalog.int8_ops'], false, true),
        ('application_blue_green_deactivations', 'app_blue_green_deactivation_docker_index', ARRAY['standalone_docker_id'], ARRAY['pg_catalog.int8_ops'], false, false),
        ('application_blue_green_deactivations', 'app_blue_green_deactivation_phase_index', ARRAY['phase', 'started_at'], ARRAY['pg_catalog.text_ops', 'pg_catalog.timestamp_ops'], false, false)
), actual AS (
    SELECT table_relation.relname,
           index_relation.relname,
           ARRAY(
               SELECT attribute.attname
               FROM unnest(index_definition.indkey) WITH ORDINALITY AS indexed_attribute(attribute_number, position)
               JOIN pg_attribute AS attribute
                 ON attribute.attrelid = table_relation.oid
                AND attribute.attnum = indexed_attribute.attribute_number
               WHERE indexed_attribute.position <= index_definition.indnkeyatts
               ORDER BY indexed_attribute.position
           ),
           ARRAY(
               SELECT opclass_namespace.nspname || '.' || opclass.opcname
               FROM unnest(index_definition.indclass::oid[]) WITH ORDINALITY
                   AS selected_opclass(opclass_oid, position)
               JOIN pg_opclass AS opclass ON opclass.oid = selected_opclass.opclass_oid
               JOIN pg_namespace AS opclass_namespace ON opclass_namespace.oid = opclass.opcnamespace
               ORDER BY selected_opclass.position
           ),
           index_definition.indisprimary,
           index_definition.indisunique
    FROM pg_index AS index_definition
    JOIN pg_class AS table_relation ON table_relation.oid = index_definition.indrelid
    JOIN pg_namespace AS namespace ON namespace.oid = table_relation.relnamespace
    JOIN pg_class AS index_relation ON index_relation.oid = index_definition.indexrelid
    JOIN pg_am AS access_method ON access_method.oid = index_relation.relam
    WHERE namespace.nspname = 'public'
      AND table_relation.relname IN ('application_blue_green_deployments', 'application_blue_green_deactivations')
      AND access_method.amname = 'btree'
      AND index_definition.indisvalid
      AND index_definition.indisready
      AND index_definition.indislive
      AND index_definition.indnatts = index_definition.indnkeyatts
      AND index_definition.indpred IS NULL
      AND index_definition.indexprs IS NULL
      AND NOT EXISTS (
          SELECT 1
          FROM generate_series(0, index_definition.indnkeyatts - 1) AS key_position(position)
          JOIN pg_attribute AS indexed_attribute
            ON indexed_attribute.attrelid = index_definition.indrelid
           AND indexed_attribute.attnum = index_definition.indkey[position]
          WHERE index_definition.indcollation[position] <> indexed_attribute.attcollation
      )
      AND NOT EXISTS (
          SELECT 1
          FROM generate_series(0, index_definition.indnkeyatts - 1) AS key_position(position)
          WHERE index_definition.indoption[position] <> 0
      )
), mismatch AS (
    (SELECT * FROM expected EXCEPT SELECT * FROM actual)
    UNION ALL
    (SELECT * FROM actual EXCEPT SELECT * FROM expected)
)
SELECT pg_temp.assert_true(NOT EXISTS (SELECT 1 FROM mismatch), 'primary, unique, or supporting index catalog differs');

WITH expected(source_table, source_column, target_table, target_column, update_action, delete_action, is_deferrable, is_deferred, is_validated) AS (
    VALUES
        ('application_blue_green_deployments', 'application_id', 'applications', 'id', 'a', 'c', false, false, true),
        ('application_blue_green_deployments', 'standalone_docker_id', 'standalone_dockers', 'id', 'a', 'c', false, false, true),
        ('application_blue_green_deactivations', 'application_id', 'applications', 'id', 'a', 'c', false, false, true),
        ('application_blue_green_deactivations', 'standalone_docker_id', 'standalone_dockers', 'id', 'a', 'c', false, false, true)
), actual AS (
    SELECT source_relation.relname,
           source_attribute.attname,
           target_relation.relname,
           target_attribute.attname,
           constraint_definition.confupdtype::text,
           constraint_definition.confdeltype::text,
           constraint_definition.condeferrable,
           constraint_definition.condeferred,
           constraint_definition.convalidated
    FROM pg_constraint AS constraint_definition
    JOIN pg_class AS source_relation ON source_relation.oid = constraint_definition.conrelid
    JOIN pg_namespace AS namespace ON namespace.oid = source_relation.relnamespace
    JOIN pg_class AS target_relation ON target_relation.oid = constraint_definition.confrelid
    JOIN LATERAL unnest(constraint_definition.conkey, constraint_definition.confkey)
        AS constrained_key(source_attribute_number, target_attribute_number) ON true
    JOIN pg_attribute AS source_attribute
      ON source_attribute.attrelid = source_relation.oid
     AND source_attribute.attnum = constrained_key.source_attribute_number
    JOIN pg_attribute AS target_attribute
      ON target_attribute.attrelid = target_relation.oid
     AND target_attribute.attnum = constrained_key.target_attribute_number
    WHERE namespace.nspname = 'public'
      AND constraint_definition.contype = 'f'
      AND source_relation.relname IN ('application_blue_green_deployments', 'application_blue_green_deactivations')
), mismatch AS (
    (SELECT * FROM expected EXCEPT SELECT * FROM actual)
    UNION ALL
    (SELECT * FROM actual EXCEPT SELECT * FROM expected)
)
SELECT pg_temp.assert_true(NOT EXISTS (SELECT 1 FROM mismatch), 'foreign-key targets, columns, actions, validation, or deferrability differ');

WITH expected(
    table_name,
    column_name,
    sequence_name,
    dependency_type,
    default_expression,
    sequence_type,
    start_value,
    increment_by,
    minimum_value,
    maximum_value,
    cache_size,
    cycles
) AS (
    VALUES
        ('application_blue_green_deployments', 'id', 'application_blue_green_deployments_id_seq', 'a', 'nextval(''application_blue_green_deployments_id_seq''::regclass)', 'bigint', 1::bigint, 1::bigint, 1::bigint, 9223372036854775807::bigint, 1::bigint, false),
        ('application_blue_green_deactivations', 'id', 'application_blue_green_deactivations_id_seq', 'a', 'nextval(''application_blue_green_deactivations_id_seq''::regclass)', 'bigint', 1::bigint, 1::bigint, 1::bigint, 9223372036854775807::bigint, 1::bigint, false)
), actual AS (
    SELECT table_relation.relname,
           attribute.attname,
           sequence_relation.relname,
           dependency.deptype::text,
           pg_get_expr(attribute_default.adbin, attribute_default.adrelid),
           format_type(sequence_definition.seqtypid, NULL),
           sequence_definition.seqstart,
           sequence_definition.seqincrement,
           sequence_definition.seqmin,
           sequence_definition.seqmax,
           sequence_definition.seqcache,
           sequence_definition.seqcycle
    FROM pg_class AS sequence_relation
    JOIN pg_depend AS dependency
      ON dependency.classid = 'pg_class'::regclass
     AND dependency.objid = sequence_relation.oid
     AND dependency.refclassid = 'pg_class'::regclass
    JOIN pg_class AS table_relation ON table_relation.oid = dependency.refobjid
    JOIN pg_namespace AS namespace ON namespace.oid = table_relation.relnamespace
    JOIN pg_attribute AS attribute
      ON attribute.attrelid = table_relation.oid
     AND attribute.attnum = dependency.refobjsubid
    JOIN pg_attrdef AS attribute_default
      ON attribute_default.adrelid = attribute.attrelid
     AND attribute_default.adnum = attribute.attnum
    JOIN pg_sequence AS sequence_definition ON sequence_definition.seqrelid = sequence_relation.oid
    WHERE namespace.nspname = 'public'
      AND sequence_relation.relkind = 'S'
      AND table_relation.relname IN ('application_blue_green_deployments', 'application_blue_green_deactivations')
), mismatch AS (
    (SELECT * FROM expected EXCEPT SELECT * FROM actual)
    UNION ALL
    (SELECT * FROM actual EXCEPT SELECT * FROM expected)
)
SELECT pg_temp.assert_true(NOT EXISTS (SELECT 1 FROM mismatch), 'serial sequence ownership, default binding, or parameters differ');

SELECT pg_temp.assert_true(
    NOT EXISTS (
        SELECT 1
        FROM application_settings
        WHERE is_blue_green_deployment_enabled IS DISTINCT FROM false
    ),
    'existing and defaulted blue-green settings are not false'
);

\if :control_plane_require_fixtures
    SELECT pg_temp.assert_true(
        (SELECT count(*) FROM application_deployment_queues
         WHERE deployment_uuid IN ('control-plane-migration-queued', 'control-plane-migration-in-progress')) = 2,
        'durable queue fixtures did not survive'
    );
\endif

SELECT pg_temp.assert_true(
    NOT EXISTS (
        SELECT 1
        FROM application_deployment_queues
        WHERE status = 'in_progress'
          AND horizon_job_worker IS NOT NULL
    ),
    'active Horizon workers remain during the migration acceptance point'
);

SELECT 'STRUCTURAL_SCHEMA_VALID';
