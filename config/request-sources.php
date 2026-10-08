<?php

use App\Services\Requests\Sources\HrRequestSource;
use App\Services\Requests\Sources\LeaveRequestSource;

return [
    // Leave has its own workflow/table. The remaining HR request types use hr_requests.
    'leave' => LeaveRequestSource::class,
    'advance' => HrRequestSource::class,
    'permission' => HrRequestSource::class,
    'mission' => HrRequestSource::class,
    'document_request' => HrRequestSource::class,
    'other' => HrRequestSource::class,
];
