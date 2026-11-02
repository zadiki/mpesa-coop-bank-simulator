<?php

return [
    // Seconds between the synchronous acknowledgement and the async callback.
    'callback_delay_seconds' => (int) env('SIM_CALLBACK_DELAY_SECONDS', 3),

    // How long the simulator waits for the client's callback endpoint to answer.
    'callback_timeout_seconds' => (int) env('SIM_CALLBACK_TIMEOUT_SECONDS', 10),

    // Extra delay used by the "delayed" scenario.
    'long_delay_seconds' => (int) env('SIM_LONG_DELAY_SECONDS', 60),

    'default_consumer_key' => env('SIM_DEFAULT_CONSUMER_KEY', 'sim_consumer_key'),
    'default_consumer_secret' => env('SIM_DEFAULT_CONSUMER_SECRET', 'sim_consumer_secret'),

    'coop_consumer_key' => env('SIM_COOP_CONSUMER_KEY', 'coop_consumer_key'),
    'coop_consumer_secret' => env('SIM_COOP_CONSUMER_SECRET', 'coop_consumer_secret'),
];
