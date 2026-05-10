<?php

return [
    // Path donde se almacenan los .gz (host: ./storage-backups, container: /var/backups)
    'path' => env('BACKUPS_PATH', '/var/backups'),

    // Path donde se montan en read-only los directorios de las instancias PND
    // (host: /opt → container: /host/instances).
    'instances_path' => env('INSTANCES_PATH', '/host/instances'),

    // Subpath dentro de cada instancia donde vive el .env del backend
    'instance_env_subpath' => 'SistemaDeclaraciones_backend/.env',

    // Nombre del contenedor mongo (en pdnmx_network) — fallback si no
    // se puede leer host del .env de la instancia.
    'mongo_container' => env('MONGO_CONTAINER', 'pdnmx-mongo'),

    // Timeout de mongodump/mongorestore en segundos
    'tools_timeout' => (int) env('MONGO_TOOLS_TIMEOUT', 600),

    // Tamaño máximo permitido para subir respaldos (MB)
    'upload_max_mb' => (int) env('UPLOAD_MAX_MB', 512),
];
