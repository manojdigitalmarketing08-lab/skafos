<?php
// Copy this file to mail-config.php on the server and fill in the real values.
// mail-config.php is git-ignored so credentials are never committed.
return [
    'host'      => 'smtp.hostinger.com',
    'username'  => 'CHANGE_ME',
    'password'  => 'CHANGE_ME',
    'port'      => 465,
    'secure'    => 'ssl',
    'from'      => 'CHANGE_ME',
    'from_name' => 'Skafos Men\'s Wellness',
    'to'        => 'CHANGE_ME',
    // MySQL database for hero form leads (run schema.sql once to create the table).
    'db' => [
        'host'    => 'localhost',
        'name'    => 'CHANGE_ME',
        'user'    => 'CHANGE_ME',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
];
