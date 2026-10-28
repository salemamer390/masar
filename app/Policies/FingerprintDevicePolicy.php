<?php

namespace App\Policies;

use App\Models\FingerprintDevice;
use App\Models\User;

class FingerprintDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('fingerprint-devices.view');
    }

    public function view(User $user, FingerprintDevice $device): bool
    {
        return $user->hasPermission('fingerprint-devices.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('fingerprint-devices.create');
    }

    public function update(User $user, FingerprintDevice $device): bool
    {
        return $user->hasPermission('fingerprint-devices.update');
    }

    public function delete(User $user, FingerprintDevice $device): bool
    {
        return $user->hasPermission('fingerprint-devices.delete');
    }

    public function test(User $user, FingerprintDevice $device): bool
    {
        return $user->hasPermission('fingerprint-devices.test');
    }

    public function sync(User $user, FingerprintDevice $device): bool
    {
        return $user->hasPermission('fingerprint-devices.sync');
    }
}
