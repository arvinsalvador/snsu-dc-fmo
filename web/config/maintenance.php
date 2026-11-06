<?php

return [
    /*
    | Default warning distance for odometer-based preventive maintenance. A plan may override it
    | with its own "due soon" threshold.
    */
    'odometer_due_soon_km' => (int) env('PM_ODOMETER_DUE_SOON_KM', 500),
];
