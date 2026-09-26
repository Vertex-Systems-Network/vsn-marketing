<?php

return [
    // Conservative application defaults; they are not production capacity SLOs.
    'max_depth' => 8,
    'max_nodes' => 100,
    'max_cost' => 100,
    'max_event_days' => 365,
    'max_preview_rows' => 50,
    'max_count_probe' => 250,
    'query_timeout_ms' => 3000,
    'max_proposal_characters' => 2000,
    'max_proposal_events' => 250,
];
