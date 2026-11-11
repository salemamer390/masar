<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A column header a company taught the employee import: the user confirmed
 * «this header means this field» in the review step, so the next workbook
 * with the same header is recognised automatically. Built-in dictionary
 * entries always win over learned ones on conflict.
 */
class ImportColumnAlias extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
}
