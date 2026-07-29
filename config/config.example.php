<?php
/**
 * MTL configuration template.
 *
 * Copy this file to config/config.php and fill in the real values. The copy is
 * git-ignored, so credentials never reach the repository. On Strato the file is
 * uploaded once and then left alone by subsequent deployments.
 *
 * Every value can also be supplied through an environment variable, which is
 * what the test suite and the CI workflow use. The env() helper prefers the
 * environment and falls back to the literal written here.
 */

declare(strict_types=1);

return [

    // -------------------------------------------------------------------------
    // Application
    // -------------------------------------------------------------------------
    'app' => [
        'name' => 'MTL',

        // Public base URL, no trailing slash. Used for canonical links, the
        // sitemap, e-mail links and the Android asset-links check.
        'url' => env('APP_URL', 'https://mtl.r010.space'),

        // 'production' hides errors and enables caching. 'development' shows
        // full stack traces and disables the view/asset caches.
        'env' => env('APP_ENV', 'production'),

        'debug' => (bool) env('APP_DEBUG', false),

        'timezone' => env('APP_TIMEZONE', 'Europe/Amsterdam'),

        // Interface language. 'nl' and 'en' ship with the application.
        'locale' => env('APP_LOCALE', 'nl'),

        // 32+ random bytes, base64 encoded. Generate with:
        //   php bin/console.php key:generate
        // Rotating this invalidates every session and remember-me token.
        'key' => env('APP_KEY', ''),
    ],

    // -------------------------------------------------------------------------
    // Database (MySQL / MariaDB)
    // -------------------------------------------------------------------------
    // Strato shows these values in the customer panel under "Databases". The
    // host is usually rdbms.strato.de rather than localhost.
    'database' => [
        'host'     => env('DB_HOST', 'rdbms.strato.de'),
        'port'     => (int) env('DB_PORT', 3306),
        'name'     => env('DB_NAME', 'dbs1234567'),
        'user'     => env('DB_USER', 'dbu1234567'),
        'password' => env('DB_PASSWORD', ''),

        // utf8mb4 is required: travel reports contain emoji and non-Latin
        // place names, which three-byte utf8 cannot store.
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',

        // Prefix lets several installations share one Strato database.
        'prefix' => env('DB_PREFIX', ''),
    ],

    // -------------------------------------------------------------------------
    // Sessions
    // -------------------------------------------------------------------------
    'session' => [
        'name'     => 'mtl_session',
        'lifetime' => 43200,      // seconds of inactivity before logout (12h)
        'secure'   => true,       // cookie only over HTTPS
        'samesite' => 'Lax',

        // Days a "remember me" token stays valid.
        'remember_days' => 30,
    ],

    // -------------------------------------------------------------------------
    // Media handling
    // -------------------------------------------------------------------------
    'media' => [
        // Absolute path is derived from the project root at runtime; this is
        // the directory name below storage/.
        'disk' => 'media',

        'images' => [
            'accept' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'],

            // Longest-edge sizes generated for every uploaded image. The
            // originals are always kept untouched.
            'variants' => [
                'thumb'  => 320,
                'small'  => 640,
                'medium' => 1280,
                'large'  => 2048,
            ],

            // JPEG/WebP quality for generated variants (1-100).
            'quality' => 82,

            // Re-encode variants to WebP when the GD build supports it.
            'prefer_webp' => true,

            // Hard ceiling on decoded pixels, guards against decompression
            // bombs on a shared host with a fixed memory_limit.
            'max_pixels' => 80_000_000,
        ],

        'videos' => [
            'accept' => ['video/mp4', 'video/webm', 'video/quicktime'],

            // Shared hosting has no ffmpeg, so videos are stored as uploaded.
            // A poster frame can be supplied by the browser during upload.
            'max_bytes' => 512 * 1024 * 1024,
        ],

        // Size of each chunk the browser uploads, in bytes. Must stay below
        // post_max_size in .user.ini.
        'chunk_bytes' => 4 * 1024 * 1024,
    ],

    // -------------------------------------------------------------------------
    // Mail (password resets and invitations)
    // -------------------------------------------------------------------------
    'mail' => [
        // 'mail' uses PHP's mail() which Strato routes through its own MTA.
        // 'smtp' talks to a server directly. 'log' writes to storage/logs.
        'transport' => env('MAIL_TRANSPORT', 'mail'),

        'from_address' => env('MAIL_FROM', 'noreply@mtl.r010.space'),
        'from_name'    => env('MAIL_FROM_NAME', 'MTL'),

        'smtp' => [
            'host'       => env('SMTP_HOST', ''),
            'port'       => (int) env('SMTP_PORT', 587),
            'username'   => env('SMTP_USER', ''),
            'password'   => env('SMTP_PASSWORD', ''),
            'encryption' => env('SMTP_ENCRYPTION', 'tls'), // tls | ssl | none
        ],
    ],

    // -------------------------------------------------------------------------
    // Security
    // -------------------------------------------------------------------------
    'security' => [
        // Failed logins allowed per account and per IP inside the window.
        'login_attempts' => 8,
        'login_window'   => 900,   // seconds
        'lockout'        => 900,   // seconds locked after exceeding the limit

        // Argon2id parameters. Lower memory_cost if Strato's PHP is memory
        // constrained; 64 MiB works on Hosting Advanced.
        'argon' => [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ],

        // Require a second factor for accounts with the 'admin' role.
        'force_2fa_for_admins' => false,

        // Extra origins allowed to embed or call the JSON API, e.g. the
        // Android wrapper during development.
        'cors_origins' => [],
    ],

    // -------------------------------------------------------------------------
    // Globe
    // -------------------------------------------------------------------------
    'globe' => [
        // Land geometry resolution shipped to the browser.
        // 'low' (110m, ~90 KB) is right for phones, 'high' (50m, ~400 KB)
        // looks better on a desktop or headset.
        'resolution' => env('GLOBE_RESOLUTION', 'low'),

        // Enable the WebXR "enter VR" button when the browser reports support.
        'webxr' => true,
    ],
];
