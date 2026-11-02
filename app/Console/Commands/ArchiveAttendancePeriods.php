<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AttendancePeriodArchiveService;
use Illuminate\Console\Command;

class ArchiveAttendancePeriods extends Command
{
    protected $signature = 'attendance:archive-periods {--company= : رقم شركة واحدة}';

    protected $description = 'تسجيل الفترات المنتهية، وقفل وحفظ الحضور بعد مهلة التصحيح في الشهور السابقة (لا يعدّل سجلًا مقفولًا)';

    public function handle(AttendancePeriodArchiveService $service): int
    {
        $opened = 0;
        $closed = 0;
        $companies = Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->pluck('id');
        foreach ($companies as $companyId) {
            $r = $service->sync((int) $companyId);
            $opened += $r['opened'];
            $closed += $r['closed'];
        }
        $this->info("فترات انتهت وفُتحت للتصحيح: {$opened}، فترات اتقفلت وتحفظت: {$closed}.");

        return self::SUCCESS;
    }
}
