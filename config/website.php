<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Live fastercars.ae catalogue API
    |--------------------------------------------------------------------------
    | Authoritative source for rank (categories[].pivot.sort), and for the car
    | list, titles, slugs, prices and categories. Preferred over the Power BI
    | copies of the same data because those only move when the dataset refreshes.
    */

    'cars_url' => env('WEBSITE_CARS_URL', 'https://www.fastercars.ae/api/cars'),

    'timeout' => (int) env('WEBSITE_API_TIMEOUT', 60),

    /*
    | When the live API cannot be reached, fall back to the (stale) Power BI
    | tables so the daily snapshot still happens. The sync records a loud
    | warning in refresh_log whenever it does this.
    */

    'fallback_to_powerbi' => (bool) env('WEBSITE_API_FALLBACK', true),

];
