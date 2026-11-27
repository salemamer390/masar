<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable implements CanResetPasswordContract
{
    use HasFactory, Notifiable, CanResetPassword;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'employee_id',
        'is_active',
        'last_login_at',
        'tour_seen_at',
        'tour_step',
        'company_id',
        'email_verified_at',
        'email_confirm_deadline_at',
        'email_confirm_reminders',
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
            'tour_seen_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'email_confirm_deadline_at' => 'datetime',
        ];
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** Only accounts created by public signup carry a deadline; every other account is never locked. */
    public function emailConfirmationPending(): bool
    {
        return !$this->hasVerifiedEmail() && $this->email_confirm_deadline_at !== null;
    }

    public function emailConfirmationLocked(): bool
    {
        return $this->emailConfirmationPending() && $this->email_confirm_deadline_at->isPast();
    }

    public function emailConfirmationDaysLeft(): int
    {
        return $this->email_confirm_deadline_at ? max(0, (int) ceil(now()->floatDiffInDays($this->email_confirm_deadline_at, false))) : 0;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill(['email_verified_at' => $this->freshTimestamp()])->save();
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->email;
    }

    /** Mail problems must never break signup or password changes, so failures are only logged. */
    public function sendEmailVerificationNotification(bool $welcome = false): bool
    {
        return $this->notifySafely(new \App\Notifications\Auth\VerifyEmailNotification($welcome));
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notifySafely(new \App\Notifications\Auth\ResetPasswordLinkNotification($token));
    }

    public function sendPasswordChangedNotification(): bool
    {
        return $this->notifySafely(new \App\Notifications\Auth\PasswordChangedNotification());
    }

    public function notifySafely($notification): bool
    {
        try {
            $this->notify($notification);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public function directPermissions(): BelongsToMany { return $this->belongsToMany(Permission::class, 'user_permissions'); }

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

        // Company manager is the owner-level role for its own company. Keep this
        // explicit so older company-manager records still receive all company
        // permissions even if their role_permissions rows are incomplete.
        if ($this->role?->name === 'company_manager') {
            return true;
        }

        if ($this->directPermissions()->exists()) {
            return $this->directPermissions()->where('name', $permissionName)->exists();
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
