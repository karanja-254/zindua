<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Evidence Disk
    |--------------------------------------------------------------------------
    |
    | The WORM-enabled disk used to persist immutable evidence chunks and
    | forensic artifacts. Should point at an Object Lock enabled bucket
    | (AWS S3 or Cloudflare R2) in production.
    |
    */

    'evidence_disk' => env('EVIDENCE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'evidence_local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'bucket_endpoint' => env('AWS_BUCKET_ENDPOINT', false),
            'throw' => false,
            'report' => false,
            'visibility' => 'private',
            // S3 Object Lock (WORM) options forwarded on writes when configured.
            'options' => array_filter([
                'ObjectLockMode' => env('AWS_OBJECT_LOCK_MODE'),
                'ObjectLockRetainUntilDate' => env('AWS_OBJECT_LOCK_RETAIN_UNTIL'),
            ]),
        ],

        /*
         * Cloudflare R2 (S3-compatible) with Object Lock WORM retention.
         *
         * Object Lock must be enabled on the bucket at creation time. When
         * WORM options are configured they are forwarded on every write so
         * evidence objects are immutable for the retention window.
         */
        'r2' => [
            'driver' => 's3',
            'key' => env('CLOUDFLARE_R2_ACCESS_KEY_ID'),
            'secret' => env('CLOUDFLARE_R2_SECRET_ACCESS_KEY'),
            'region' => env('CLOUDFLARE_R2_DEFAULT_REGION', 'auto'),
            'bucket' => env('CLOUDFLARE_R2_BUCKET'),
            'url' => env('CLOUDFLARE_R2_URL'),
            'endpoint' => env('CLOUDFLARE_R2_ENDPOINT'),
            'use_path_style_endpoint' => env('CLOUDFLARE_R2_USE_PATH_STYLE_ENDPOINT', true),
            'bucket_endpoint' => env('CLOUDFLARE_R2_BUCKET_ENDPOINT', false),
            'throw' => false,
            'report' => false,
            'visibility' => 'private',
            // WORM / Object Lock retention applied to every uploaded object.
            'options' => array_filter([
                'ObjectLockMode' => env('CLOUDFLARE_R2_OBJECT_LOCK_MODE'),
                'ObjectLockRetainUntilDate' => env('CLOUDFLARE_R2_OBJECT_LOCK_RETAIN_UNTIL'),
            ]),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
