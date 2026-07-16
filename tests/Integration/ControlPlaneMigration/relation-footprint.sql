\set ON_ERROR_STOP on
\pset tuples_only on
\pset format unaligned
\pset fieldsep '|'

SELECT relation.relname,
       relation.relfilenode,
       pg_relation_size(relation.oid)
FROM pg_class AS relation
WHERE relation.oid IN (
    'application_settings'::regclass,
    'application_deployment_queues'::regclass,
    'applications'::regclass,
    'standalone_dockers'::regclass
)
ORDER BY relation.relname;
