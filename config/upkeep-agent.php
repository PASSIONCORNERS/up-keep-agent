<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Secret
    |--------------------------------------------------------------------------
    |
    | up-keep sends this to read the agent page. Make one with
    | `php artisan upkeep:agent` in up-keep. Without it the page answers 404,
    | but errors and the scheduler check-in are still recorded, so turning
    | the agent on later shows recent history.
    |
    */

    'secret' => env('UPKEEP_AGENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Health check timeout
    |--------------------------------------------------------------------------
    |
    | Seconds the database and Redis checks may take before they count as
    | failed, so the page answers well inside up-keep's 10 second timeout.
    |
    */

    'health_timeout' => 3,

    /*
    |--------------------------------------------------------------------------
    | Keep errors
    |--------------------------------------------------------------------------
    |
    | Errors that haven't happened for this many days are deleted.
    |
    */

    'keep_errors_days' => 30,

];
