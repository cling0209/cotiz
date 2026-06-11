<?php

return [
    'commerce_code' => env('TRANSBANK_COMMERCE_CODE', '597055555532'),
    'api_key' => env('TRANSBANK_API_KEY'),
    'environment' => env('TRANSBANK_ENV', 'integration'),
    'return_url' => env('TRANSBANK_RETURN_URL', 'http://localhost:8081/checkout/webpay/return'),
];
