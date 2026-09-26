<?php
/**
 * Copy to config.php and fill in. Never commit config.php.
 */
return [
    // --- Meta app (developers.facebook.com → your app → Settings → Basic) ---
    'app_id'        => '000000000000000',
    'app_secret'    => 'APP_SECRET_HERE',            // used to verify X-Hub-Signature-256
    'verify_token'  => 'pick-a-long-random-string',  // same string you type in the Webhooks setup
    'graph_version' => 'v23.0',

    // --- One entry per Facebook Page: page_id => Page access token (long-lived) ---
    'pages' => [
        '1302900159578083' => 'PAGE_ACCESS_TOKEN_HERE',   // Nabhi Oil Nepal
    ],

    // --- Database (cPanel → MySQL Databases) ---
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=CPANELUSER_leadbot;charset=utf8mb4',
        'user' => 'CPANELUSER_leadbot',
        'pass' => 'DB_PASSWORD_HERE',
    ],

    // --- Leads dashboard login: generate with  php -r "echo password_hash('yourpass', PASSWORD_DEFAULT);" ---
    'admin_users' => [
        'sales' => '$2y$10$REPLACE_WITH_HASH',
    ],

    // --- Bot behaviour ---
    'bot' => [
        'enabled'              => true,     // false = log leads only, never reply
        'ask_cooldown_sec'     => 45,
        'max_asks'             => 4,
        'max_invalid'          => 2,
        'confirm_cooldown_sec' => 6 * 3600,
        'lead_renew_days'      => 30,
        'quick_reply_phone'    => true,     // show Messenger's native "share my phone number" button
        'human_pause_hours'    => 12,       // bot stays silent after a team member replies from Inbox
        'pass_to_inbox'        => false,    // true = also call pass_thread_control (Handover Protocol)
        'inbox_app_id'         => '263902037430900', // Meta Page Inbox app id (Handover Protocol)
    ],

    'timezone' => 'Asia/Kathmandu',
    'log_file' => __DIR__ . '/storage/bot.log',
];
