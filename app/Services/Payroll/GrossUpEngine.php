<?php

namespace App\Services\Payroll;

class GrossUpEngine
{
    public function solve(float $targetNet, callable $netFromGross, float $tolerance = 0.0001): float
    {
        $targetNet = max(0.01, $targetNet);
        $lo = max(0.01, $targetNet);
        $hi = max($targetNet * 2, $targetNet + 50000);

        for ($i = 0; $i < 120; $i++) {
            if ($netFromGross($hi) >= $targetNet) break;
            $hi *= 1.5;
        }

        for ($i = 0; $i < 120; $i++) {
            $mid = ($lo + $hi) / 2;
            $net = $netFromGross($mid);
            if (abs($net - $targetNet) <= $tolerance) {
                return $mid;
            }
            if ($net < $targetNet) $lo = $mid;
            else $hi = $mid;
        }

        return ($lo + $hi) / 2;
    }
}
