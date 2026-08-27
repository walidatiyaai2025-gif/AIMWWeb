<?php

return ['paypal' => ['base_url' => env('PAYPAL_BASE_URL', 'https://api-m.sandbox.paypal.com'), 'client_id' => env('PAYPAL_CLIENT_ID'), 'client_secret' => env('PAYPAL_CLIENT_SECRET'), 'webhook_id' => env('PAYPAL_WEBHOOK_ID'), 'return_url' => env('PAYPAL_RETURN_URL', env('APP_URL').'/billing/return'), 'cancel_url' => env('PAYPAL_CANCEL_URL', env('APP_URL').'/billing/cancelled')]];
