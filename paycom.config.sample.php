<?php
// sample configuration with fake values
return [
    // Get it in merchant's cabinet in cashbox settings
    'merchant_id' => '69240ea9058e46ea7a1b806a',

    // Login is always "Paycom"
    'login' => 'Paycom',

    // File with cashbox key (key can be found in cashbox settings)
    'keyFile' => 'password.paycom',

    // Your database settings
    'db' => [
        'host'     => '<database host>',
        'database' => '<database name>',
        'username' => '<database username>',
        'password' => '<database password>'
    ],
];
