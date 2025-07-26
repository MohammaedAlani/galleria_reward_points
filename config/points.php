<?php

return [
    // Add Points this will be divided by 100
    'points_per_iqd' => env('POINTS_PER_IQD', 0.01),

    // Use Points this will be multiplied by 4
    'iqd_per_point' => env('IQD_PER_POINT', 4),

    // Points for each transaction
    'points_per_use' => env('POINTS_PER_USE', 0.25),
];
