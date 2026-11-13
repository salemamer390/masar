<?php

namespace Tests;

/** Add the newly required employee columns to legacy integration fixtures.
 * Existing columns and their indexes stay unchanged, including intentionally
 * invalid cells. Requirement/rejection tests use raw fixtures instead.
 */
trait CompleteEmployeeImportFixtures
{
    private function completeEmployeeSheets(array $sheets): array
    {
        foreach ($sheets as &$sheet) {
            $rows = $sheet['rows'] ?? [];
            if (!$rows) continue;
            $headers = $rows[0];
            if (!array_intersect(['الاسم الكامل', 'Full Name'], $headers)) continue;
            $required = ['الرقم القومي' => 'nid', 'الوظيفة' => 'موظف', 'نهاية العقد' => '2027-12-31', 'الراتب الإجمالي' => '5000'];
            if (!array_intersect(['تاريخ التعيين', 'بداية العقد', 'تاريخ بداية العقد'], $headers)) $required['بداية العقد'] = '2024-01-01';
            foreach ($required as $header => $value) {
                if (in_array($header, $headers, true)) continue;
                $rows[0][] = $header;
                foreach ($rows as $index => &$row) {
                    if ($index === 0) continue;
                    $row[] = $value === 'nid' ? '2900101' . str_pad((string) (hexdec(substr(md5((string) $row[0]), 0, 5)) % 9999999), 7, '0', STR_PAD_LEFT) : $value;
                }
                unset($row);
            }
            $sheet['rows'] = $rows;
        }
        unset($sheet);
        return $sheets;
    }
}
