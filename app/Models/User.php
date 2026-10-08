<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'employee_id',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role && ($this->role->name === 'super_admin' || $this->role_id === 1);
    }

    public function isViewer(): bool
    {
        return $this->role && ($this->role->name === 'viewer' || $this->role_id === 7);
    }

    public function hasPermission(string $permissionName): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if (!$this->relationLoaded('role')) {
            $this->load('role.permissions');
        }

        if (!$this->role) {
            return false;
        }

        // Viewer role: strictly read-only
        if ($this->isViewer()) {
            $action = explode('.', $permissionName)[1] ?? '';
            $readActions = ['view', 'print', 'export', 'export_excel'];
            if (!in_array($action, $readActions, true)) {
                return false;
            }
        }

        return $this->role->permissions->contains('name', $permissionName);
    }
}
