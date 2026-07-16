\set ON_ERROR_STOP on
\pset tuples_only on
\pset format unaligned
\pset fieldsep '|'

SELECT namespace.nspname,
       relation.relname,
       attribute.attnum,
       attribute.attname,
       format_type(attribute.atttypid, attribute.atttypmod),
       attribute.attnotnull,
       attribute.attidentity,
       COALESCE(pg_get_expr(attribute_default.adbin, attribute_default.adrelid), '')
FROM pg_attribute AS attribute
JOIN pg_class AS relation ON relation.oid = attribute.attrelid
JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
LEFT JOIN pg_attrdef AS attribute_default
    ON attribute_default.adrelid = attribute.attrelid
   AND attribute_default.adnum = attribute.attnum
WHERE namespace.nspname = 'public'
  AND relation.relname IN (
      'application_settings',
      'application_deployment_queues',
      'application_blue_green_deployments',
      'application_blue_green_deactivations'
  )
  AND attribute.attnum > 0
  AND NOT attribute.attisdropped
ORDER BY relation.relname, attribute.attnum;

SELECT 'INDEX',
       table_relation.relname,
       index_relation.relname,
       index_definition.indisprimary,
       index_definition.indisunique,
       index_definition.indisvalid,
       index_definition.indisready,
       pg_get_indexdef(index_relation.oid)
FROM pg_index AS index_definition
JOIN pg_class AS table_relation ON table_relation.oid = index_definition.indrelid
JOIN pg_class AS index_relation ON index_relation.oid = index_definition.indexrelid
WHERE table_relation.relname IN (
    'application_blue_green_deployments',
    'application_blue_green_deactivations'
)
ORDER BY table_relation.relname, index_relation.relname;

SELECT 'FOREIGN_KEY',
       source_relation.relname,
       constraint_definition.conname,
       target_relation.relname,
       constraint_definition.confupdtype,
       constraint_definition.confdeltype,
       constraint_definition.condeferrable,
       constraint_definition.condeferred,
       pg_get_constraintdef(constraint_definition.oid)
FROM pg_constraint AS constraint_definition
JOIN pg_class AS source_relation ON source_relation.oid = constraint_definition.conrelid
JOIN pg_class AS target_relation ON target_relation.oid = constraint_definition.confrelid
WHERE constraint_definition.contype = 'f'
  AND source_relation.relname IN (
      'application_blue_green_deployments',
      'application_blue_green_deactivations'
  )
ORDER BY source_relation.relname, constraint_definition.conname;

SELECT 'SEQUENCE',
       table_relation.relname,
       attribute.attname,
       sequence_relation.relname,
       dependency.deptype
FROM pg_class AS sequence_relation
JOIN pg_depend AS dependency
    ON dependency.classid = 'pg_class'::regclass
   AND dependency.objid = sequence_relation.oid
   AND dependency.refclassid = 'pg_class'::regclass
JOIN pg_class AS table_relation ON table_relation.oid = dependency.refobjid
JOIN pg_attribute AS attribute
    ON attribute.attrelid = table_relation.oid
   AND attribute.attnum = dependency.refobjsubid
WHERE sequence_relation.relkind = 'S'
  AND table_relation.relname IN (
      'application_blue_green_deployments',
      'application_blue_green_deactivations'
  )
ORDER BY table_relation.relname, attribute.attname;
