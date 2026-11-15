<?php

namespace App\Services\Imports;

/** Identifies import-only payroll cutoffs without parsing translated messages. */
class OutsidePayrollPeriodException extends \RuntimeException
{
}
