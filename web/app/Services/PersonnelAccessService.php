<?php

namespace App\Services;

use App\Models\FmoPersonnel;
use LogicException;
use Spatie\Permission\Models\Role;

class PersonnelAccessService
{
    public function provision(FmoPersonnel $personnel): bool
    {
        if (! $personnel->isAssignable() || $personnel->user->hasRole('FMO Staff')) {
            return false;
        }

        $role = Role::findByName('FMO Staff', 'web');
        if ($role->hasPermissionTo('work_orders.view_all')) {
            throw new LogicException('FMO Staff must not grant view_all for personnel provisioning. Review its role mapping first.');
        }

        $personnel->user->assignRole($role);

        return true;
    }
}
