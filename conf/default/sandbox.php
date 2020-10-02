<?php

/**
 * DON'T MODIFY THIS FILE!!! READ "conf/README.md" BEFORE.
 */

// Put here the JWT and endpoint provided by Nexmo
return [
    'force_sandbox_mode' => isset($_ENV['FORCE_SANDBOX_MODE']) ? (bool)$_ENV['FORCE_SANDBOX_MODE'] : false,
    'jwt' => '',
    'endpoint' => ''
];
