<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One step of the first-login guided tour as managed from the owner panel.
 *
 * The table holds at most two sets of rows: the published set (is_draft =
 * false) that every user sees, and one working draft (is_draft = true) the
 * owner edits, previews and then publishes or discards. System steps
 * (welcome/closing) keep the tour's mandatory frame: their text is editable
 * but they cannot be removed or deactivated.
 */
class TourStep extends Model
{
    protected $fillable = [
        'key',
        'route_name',
        'target',
        'icon',
        'permission',
        'title_ar',
        'body_ar',
        'title_en',
        'body_en',
        'sort_order',
        'is_active',
        'is_system',
        'is_draft',
        'published_at',
    ];

    protected $casts = [
        'target' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'is_draft' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopeLive($query)
    {
        return $query->where('is_draft', false);
    }

    public function scopeDraft($query)
    {
        return $query->where('is_draft', true);
    }
}
