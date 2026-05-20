<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app'),
            'serve'  => true,
            'throw'  => false,
        ],
        'backups' => [
            'driver' => 'local',
            'root'   => env('BACKUPS_PATH', '/var/backups'),
            'throw'  => true,
        ],
    ],
    'links' => [],
];
