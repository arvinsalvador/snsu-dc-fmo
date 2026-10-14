<?php

return [
    'generation' => [
        'backfill_days' => (int) env('ROUTINE_BACKFILL_DAYS', 62),
        'lookahead_days' => (int) env('ROUTINE_LOOKAHEAD_DAYS', 35),
    ],
];
