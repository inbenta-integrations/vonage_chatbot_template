<?php

/**
 * DON'T MODIFY THIS FILE!!! READ "conf/README.md" BEFORE.
 */

// Inbenta Hyperchat configuration
return [
    'chat' => [
        'enabled' => false,
        'version' => '1',
        'appId' => '',
        'secret' => '',
        'roomId' => 1,             // Numeric value, no string (without quotes)
        'lang' => '',
        'source' => 3,             // Numeric value, no string (without quotes)
        'guestName' => '',
        'guestContact' => '',
        'regionServer' => 'us',     // Region of the Hyperchat server URL
        'server' => 'https://hyperchat-REGION.inbenta.chat',    // Change "REGION" with your Hyperchat server region
        'server_port' => 443,
        'surveyId' => '',
        'timetable' => [
            'monday'     => ['09:00-18:00'],
            'tuesday'    => ['09:00-18:00'],
            'wednesday'  => ['09:00-18:00'],
            'thursday'   => ['09:00-18:00'],
            'friday'     => ['09:00-18:00'],
            'saturday'   => ['09:00-18:00'],
            'sunday'     => [],
        ]
    ],
    'triesBeforeEscalation' => 0,
    'negativeRatingsBeforeEscalation' => 0
];
