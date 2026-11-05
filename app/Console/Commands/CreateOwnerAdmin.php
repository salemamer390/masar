<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the dedicated platform owner account interactively. No arguments:
 * nothing sensitive can leak into shell history, and the password is asked
 * with a hidden prompt. Existing users are never modified — a taken email
 * aborts the command.
 */
class CreateOwnerAdmin extends Command
{
    protected $signature = 'masar:create-owner';

    protected $description = 'إنشاء حساب مالك المنصة المخصص للوحة التحكم الخاصة (تفاعلي)';

    public function handle(): int
    {
        $name = trim((string) $this->ask('اسم حساب المالك'));
        if ($name === '') {
            $this->error('الاسم مطلوب.');
            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->ask('البريد الإلكتروني لحساب المالك')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('البريد الإلكتروني غير صالح.');
            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('هذا البريد مسجل بالفعل. لم يتم تغيير أي حساب قائم.');
            return self::FAILURE;
        }

        $password = (string) $this->secret('كلمة المرور (8 أحرف على الأقل — لن تظهر أثناء الكتابة)');
        if (strlen($password) < 8) {
            $this->error('كلمة المرور يجب ألا تقل عن 8 أحرف.');
            return self::FAILURE;
        }
        $confirmation = (string) $this->secret('تأكيد كلمة المرور');
        if ($password !== $confirmation) {
            $this->error('كلمتا المرور غير متطابقتين.');
            return self::FAILURE;
        }

        $userId = null;
        DB::transaction(function () use ($name, $email, $password, &$userId): void {
            $role = Role::firstOrCreate(
                ['name' => 'super_admin', 'company_id' => null],
                ['display_name' => 'Super Admin', 'description' => 'Full system access'],
            );
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role_id' => $role->id,
                'company_id' => null,
                'is_active' => true,
            ]);
            $fresh = $user->fresh(['role']);
            if (!$fresh || !$fresh->isSuperAdmin() || $fresh->company_id !== null) {
                throw new \RuntimeException('Owner account verification failed; rolled back.');
            }
            $userId = $fresh->id;
        });

        $this->info("تم إنشاء حساب المالك بنجاح: #{$userId} — {$name} <{$email}> (super_admin، بدون شركة).");
        $this->line('الخطوة التالية: اضبط OWNER_EMAILS في .env على هذا البريد، ثم شغّل php artisan config:clear');
        $this->line('وسجّل الدخول من صفحة دخول المالك على /owner/login');

        return self::SUCCESS;
    }
}
