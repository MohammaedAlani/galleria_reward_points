<?php

return [
    'base_url' => env('ULTRAMSG_BASE_URL', 'https://api.ultramsg.com'),

    'throttle_per_minute' => (int) env('ULTRAMSG_THROTTLE_PER_MINUTE', 60),

    'default_country_code' => env('ULTRAMSG_DEFAULT_CC', '964'),

    'media_disk' => env('ULTRAMSG_MEDIA_DISK', 'public'),
];
