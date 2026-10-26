<?php

/*
 * LinkWatcher configuration
 *
 * 1. Copy this file to config/config.php (that file is ignored by git).
 * 2. Restrict access:   chmod 600 config/config.php
 * 3. Make sure only the public/ folder is reachable from the web.
 *
 * The SMTP password can alternatively be provided via the environment
 * variable LINKWATCHER_SMTP_PASSWORD – then leave 'password' empty here.
 */

return [

    'app' => [
        'name' => 'LinkWatcher',
        // Public URL of the dashboard, used for links in the alert mail.
        'base_url' => 'https://linkwatcher.example.ch',
        'timezone' => 'Europe/Zurich',
    ],

    // Database and lock file – keep them OUTSIDE of public/.
    // 'db_path'   => __DIR__ . '/../data/linkwatcher.sqlite',
    // 'lock_file' => __DIR__ . '/../data/linkwatcher.lock',

    /*
     * Source files with the text keys. One group per file type; 'files' maps language => path in the repo.
     * Format: 'ios_strings'  ("key" = "value";)
     *         'android_xml'  (<string name="key">value</string>)
     *         'auto'         (.xml → android_xml, otherwise ios_strings)
     * Instead of a path you can also give a direct URL: 'de' => ['url' => 'https://…'].
     */
    'sources' => [
        [
            'label' => 'iOS',
            'format' => 'ios_strings',
            'repo' => 'owner/repository',
            'branch' => 'main',
            'files' => [
                'de' => 'path/to/de.lproj/Localizable.strings',
                'fr' => 'path/to/fr.lproj/Localizable.strings',
                'it' => 'path/to/it.lproj/Localizable.strings',
                'rm' => 'path/to/rm.lproj/Localizable.strings',
                'en' => 'path/to/en.lproj/Localizable.strings',
            ],
        ],
        [
            'label' => 'Android',
            'format' => 'android_xml',
            'repo' => 'owner/repository',
            'branch' => 'main',
            'files' => [
                'de' => 'path/to/values-de/strings.xml',
                'fr' => 'path/to/values-fr/strings.xml',
                'it' => 'path/to/values-it/strings.xml',
                'rm' => 'path/to/values-rm/strings.xml',
                'en' => 'path/to/values/strings.xml',
            ],
        ],
    ],

    // Optional: only watch keys whose name matches this regex, e.g. '/_link/'. null = every key containing a URL.
    'key_filter' => null,

    'checker' => [
        'use_head' => true,              // HEAD first, automatic GET fallback
        'connect_timeout' => 10,         // seconds
        'timeout' => 20,                 // seconds per request
        'max_concurrency' => 8,          // parallel requests overall
        'max_per_host' => 2,             // parallel requests per host (be polite)
        'retry_attempts' => 1,           // re-check failed URLs once more …
        'retry_delay' => 15,             // … after this many seconds
        'slow_threshold_ms' => 8000,     // slower responses are a warning (0 = off)
        'redirect_is_warning' => true,   // a redirect to another URL is a warning (hint to update the link)
        'warning_codes' => [401, 403, 429], // treated as warning (often bot protection) instead of error
        'verify_tls' => true,            // a broken certificate is reported as error
    ],

    'alerts' => [
        'enabled' => true,
        'on_warnings' => false,          // also mail when there are only warnings
        'only_new_problems' => false,    // false = mail every night while something is broken
        'stale_after_hours' => 26,       // dashboard shows an alarm if the cron has not run for this long
        'heartbeat_url' => '',           // optional, e.g. https://hc-ping.com/<uuid>
    ],

    'mail' => [
        'transport' => 'smtp',           // 'smtp' or 'mail' (PHP mail())
        'from' => 'linkwatcher@example.ch',
        'from_name' => 'LinkWatcher',
        'to' => ['you@example.ch'],
        'subject_prefix' => '[LinkWatcher]',
        'smtp' => [
            'host' => 'smtp.example.ch',
            'port' => 587,
            'encryption' => 'tls',       // 'tls' (STARTTLS, 587), 'ssl' (465) or 'none'
            'username' => 'linkwatcher@example.ch',
            'password' => '',
            'timeout' => 20,
            'verify_tls' => true,
        ],
    ],

    // Dashboard login. Create the hash with:  php bin/hash-password.php
    'admin' => [
        'username' => 'admin',
        'password_hash' => '',
    ],

    // How long check history and runs are kept.
    'history_days' => 90,
];
