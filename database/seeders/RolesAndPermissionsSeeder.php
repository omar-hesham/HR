<?php

namespace Database\Seeders;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * The §4 matrix. Idempotent: safe to re-run after adding permissions.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::values() as $name) {
            PermissionModel::findOrCreate($name, 'web');
        }

        PermissionModel::findOrCreate('ai.pipeline.manage', 'web');
        PermissionModel::findOrCreate('jobs.retry', 'web');
        PermissionModel::findOrCreate('backups.manage', 'web');
        PermissionModel::findOrCreate('calibration.manage', 'web');

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, 'web')
                ->syncPermissions(array_map(static fn (Permission $p): string => $p->value, $role->permissions()));
        }

        RoleModel::findByName('owner')->givePermissionTo(['jobs.retry', 'backups.manage', 'calibration.manage']);
        RoleModel::findByName('operator')->givePermissionTo(['jobs.retry', 'backups.manage']);
        RoleModel::findByName('hiring_manager')->givePermissionTo('calibration.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
