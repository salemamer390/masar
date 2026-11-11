<?php

return [
    // While true, a user who has not finished the guided tour cannot send
    // changes (POST/PUT/PATCH/DELETE) except tour progress and logout.
    'enforce' => env('TOUR_ENFORCE', true),
];
