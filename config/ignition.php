<?php

/*
|--------------------------------------------------------------------------
| Ignition overrides
|--------------------------------------------------------------------------
|
| The /_ignition/execute-solution endpoint can execute arbitrary code. Spatie's
| guard enables runnable solutions whenever APP_DEBUG=true and the app
| environment is "local" or "development" — precisely the combination that
| ships in many developers' .env files and then gets copied to a server.
|
| This default forces runnable solutions off regardless of environment. Stack
| traces and the Ignition error page still render; only the code-execution
| endpoint is disabled. An operator can opt back in explicitly with
| IGNITION_ENABLE_RUNNABLE_SOLUTIONS=true (never do this in production).
|
| Merged over the package config, so all other Ignition keys keep their
| vendor defaults.
|
*/

return [
    'enable_runnable_solutions' => env('IGNITION_ENABLE_RUNNABLE_SOLUTIONS', false),
];
