<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'employee_id',
        'document_type_id',
        'document_name',
        'document_type',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'status',
        'issue_date',
        'expiry_date',
        'uploaded_by',
        'uploaded_at',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'uploaded_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getFormattedSizeAttribute(): string
    {
        if (!$this->file_size) {
            return '';
        }
        if ($this->file_size >= 1048576) {
            return round($this->file_size / 1048576, 1) . ' MB';
        }
        return round($this->file_size / 1024) . ' KB';
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'مكتمل', 'Completed' => 'success',
            'قيد المراجعة', 'Under Review' => 'warning',
            default => 'danger',
        };
    }

    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }
        return route('documents.preview', $this);
    }

    /** Legacy public files remain readable through the guarded route until migrated. */
    public function storageDisk(): string
    {
        return $this->file_path && Storage::disk('local')->exists($this->file_path) ? 'local' : 'public';
    }

    public function isImage(): bool
    {
        return $this->mime_type && str_starts_with($this->mime_type, 'image/');
    }
}
