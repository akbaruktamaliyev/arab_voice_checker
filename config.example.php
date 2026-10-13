<?php
/**
 * QRaft — configuration template.
 *
 * Copy this file to "config.php" and fill in your own values.
 * config.php is ignored by git so your credentials never get committed.
 */

return [
    // Database (MySQL / MariaDB)
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'qraft',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Public base URL of the app, no trailing slash.
    // This is what gets encoded into dynamic QR codes, so it must be the
    // address your visitors actually reach (e.g. https://qr.example.com).
    'base_url' => 'http://qraft.test',

    // Shown in the page title and header.
    'app_name' => 'QRaft',

    // Length of the short code used in dynamic QR links (/q/XXXXXX).
    'code_length' => 6,
];
