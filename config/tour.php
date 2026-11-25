<?php

return [
    // While true, a user who has not finished the guided tour cannot send
    // changes (POST/PUT/PATCH/DELETE) except tour progress and logout.
    'enforce' => env('TOUR_ENFORCE', true),

    // While true, a company with no employees must acknowledge the
    // "set working hours first" notice before it can import anything.
    'import_gate' => env('IMPORT_SETUP_GATE', true),
];
