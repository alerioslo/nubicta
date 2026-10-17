<?php

/* Copias de seguridad que cada empresa descarga y restaura desde Configuración. */
return [
    'format' => 1,
    'edition' => env('FRIIGO_EDITION', 'privado'),
    'disk' => 'local',
    'queue_connection' => env('BACKUP_QUEUE_CONNECTION', env('QUEUE_CONNECTION') === 'redis' ? 'redis_long' : env('QUEUE_CONNECTION', 'sync')),
    'public_prefix' => '',
    'retention_days' => 3,
    'skip_tables' => [
        'migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
        'backups', 'vault_keys', 'search_tokens', 'api_tokens', 'personal_access_tokens',
    ],
    'skip_dirs' => ['backups', 'imports', 'tmp'],
    'sealed_markers' => ['app_settings.value', 'audit_logs.old_values', 'audit_logs.new_values'],
];
