<?php

return [
    'app_name' => 'Dastarkhwan',
    'base_url' => 'http://dastarkhwan.local',  
    'debug'    => true,                          // set to false in production
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'dastarkhwan',
        'user'    => 'dastarkhwan_user',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    
    'session_idle_seconds' => 7200,              // log out after 2 hours of inactivity
];
