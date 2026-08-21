<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service Principal credentials (MSAL client credentials flow)
    |--------------------------------------------------------------------------
    | Set these in .env. Never hardcode them here.
    */

    'tenant_id'     => env('POWERBI_TENANT_ID'),
    'client_id'     => env('POWERBI_CLIENT_ID'),
    'client_secret' => env('POWERBI_CLIENT_SECRET'),

    'workspace_id'  => env('POWERBI_WORKSPACE_ID'),
    'report_id'     => env('POWERBI_REPORT_ID'),
    'dataset_id'    => env('POWERBI_DATASET_ID'),

    'api_base'      => env('POWERBI_API_BASE', 'https://api.powerbi.com/v1.0/myorg'),

    /*
    |--------------------------------------------------------------------------
    | OAuth endpoints
    |--------------------------------------------------------------------------
    */

    'authority' => 'https://login.microsoftonline.com/',
    'scope'     => 'https://analysis.windows.net/powerbi/api/.default',

    /*
    |--------------------------------------------------------------------------
    | Refresh polling
    |--------------------------------------------------------------------------
    | poll_interval : seconds to wait between refresh-status checks.
    | poll_timeout  : seconds before we give up and log the refresh as failed.
    */

    'poll_interval' => (int) env('POWERBI_POLL_INTERVAL', 60),
    'poll_timeout'  => (int) env('POWERBI_POLL_TIMEOUT', 900),

    /*
    |--------------------------------------------------------------------------
    | Views lookback
    |--------------------------------------------------------------------------
    | GA4 stores page views as one row per slug per day and we keep them at that
    | grain in `daily_views`. This only bounds how far back each sync re-pulls
    | daily rows — it is NOT an aggregation window. Totals over a date range are
    | computed at read time from `daily_views`.
    */

    'views_lookback_days' => (int) env('POWERBI_VIEWS_LOOKBACK_DAYS', 30),

    /*
    | Trailing days of per-car-per-day fleet status pulled into `daily_status`.
    | Must comfortably exceed the longest UI range (90 days) so range occupancy
    | can be computed for the full window.
    */

    'status_lookback_days' => (int) env('POWERBI_STATUS_LOOKBACK_DAYS', 120),

];
