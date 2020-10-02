<?php

// Put here the JWT and endpoint provided by Nexmo
return [
    'force_sandbox_mode' => isset($_ENV['FORCE_SANDBOX_MODE']) ? (bool)$_ENV['FORCE_SANDBOX_MODE'] : true,
    'jwt' => '',
    'endpoint' => ''
];
