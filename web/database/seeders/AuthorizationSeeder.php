<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (config('authorization.permissions') as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (config('authorization.core_roles') as $name) {
            Role::findOrCreate($name, 'web');
        }

        $registration = [
            'users.view_pending_registrations', 'users.approve_registration',
            'users.reject_registration', 'users.request_registration_correction',
        ];

        foreach (['Campus Director', 'Director for Instruction'] as $name) {
            Role::findByName($name, 'web')->syncPermissions($registration);
        }

        Role::findByName('FMO Head', 'web')->syncPermissions([
            ...$registration, 'users.view', 'users.assign_permissions',
            'permissions.view',
        ]);

        Role::findByName('System Administrator', 'web')
            ->syncPermissions(config('authorization.permissions'));

        Role::findByName('FMO Head', 'web')->givePermissionTo([
            'personnel.view', 'personnel.create', 'personnel.update',
            'personnel.manage_status', 'personnel.delete', 'skills.view',
            'skills.create', 'skills.update', 'skills.delete',
            'profiles.view_others', 'profiles.update_others',
        ]);
        Role::findByName('Campus Director', 'web')->givePermissionTo(['personnel.view', 'profiles.view_others']);
        Role::findByName('Director for Instruction', 'web')->givePermissionTo(['personnel.view', 'profiles.view_others']);
        Role::findByName('FMO Dispatcher', 'web')->givePermissionTo(['personnel.view', 'skills.view']);
        Role::findByName('FMO Staff', 'web')->givePermissionTo(['profiles.view_own', 'profiles.update_own']);
        Role::findByName('Requester', 'web')->givePermissionTo(['profiles.view_own', 'profiles.update_own']);
        foreach (['Requester', 'FMO Staff', 'FMO Dispatcher', 'Campus Director', 'Director for Instruction'] as $role) {
            Role::findByName($role, 'web')->givePermissionTo(['campuses.view', 'buildings.view', 'locations.view']);
        }
        Role::findByName('FMO Head', 'web')->givePermissionTo([
            'campuses.view', 'campuses.create', 'campuses.update', 'campuses.manage_status', 'campuses.delete',
            'buildings.view', 'buildings.create', 'buildings.update', 'buildings.manage_status', 'buildings.delete',
            'locations.view', 'locations.create', 'locations.update', 'locations.manage_status', 'locations.delete',
            'work_orders.create', 'work_orders.view_all', 'work_orders.update_own_submitted', 'work_orders.resubmit_own', 'work_order_categories.view', 'work_order_categories.create', 'work_order_categories.update', 'work_order_categories.manage_status', 'work_order_categories.delete',
            'work_orders.screen', 'work_orders.request_information', 'work_orders.recommend', 'work_orders.approve', 'work_orders.disapprove', 'work_orders.return_to_screening',
            'work_orders.assign', 'work_orders.reassign', 'work_orders.remove_assignee',
            'work_orders.view_assessments', 'work_orders.resolve_assessment', 'work_orders.cancel', 'work_orders.mark_beyond_scope', 'work_orders.refer_external',
            'work_orders.view_execution', 'work_orders.verify_completion', 'work_orders.return_for_work', 'work_orders.manage_sessions',
        ]);
        foreach (['Requester', 'FMO Staff'] as $role) {
            Role::findByName($role, 'web')->givePermissionTo(['work_orders.create', 'work_orders.view_own', 'work_orders.update_own_submitted', 'work_orders.resubmit_own', 'work_order_categories.view']);
        }
        Role::findByName('FMO Staff', 'web')->givePermissionTo(['work_orders.view_assigned', 'work_orders.assess_assigned']);
        Role::findByName('FMO Staff', 'web')->givePermissionTo(['work_orders.start_assigned', 'work_orders.update_assigned', 'work_orders.end_session', 'work_orders.submit_completion']);
        foreach (['Campus Director', 'Director for Instruction', 'FMO Dispatcher', 'FMO Oversight'] as $role) {
            Role::findByName($role, 'web')->givePermissionTo(['work_orders.create', 'work_orders.view_all', 'work_orders.update_own_submitted', 'work_orders.resubmit_own', 'work_order_categories.view']);
        }
        Role::findByName('FMO Dispatcher', 'web')->givePermissionTo(['work_orders.screen', 'work_orders.request_information', 'work_orders.recommend', 'work_orders.assign', 'work_orders.reassign', 'work_orders.remove_assignee', 'work_orders.view_assessments']);
        foreach (['FMO Dispatcher', 'Campus Director', 'Director for Instruction', 'FMO Oversight'] as $role) {
            Role::findByName($role, 'web')->givePermissionTo(['work_orders.view_execution']);
        }
        Role::findByName('Campus Director', 'web')->givePermissionTo(['work_orders.create_direct', 'work_orders.assign_direct', 'work_orders.view_assessments']);
        foreach (['Director for Instruction', 'FMO Oversight'] as $role) {
            Role::findByName($role, 'web')->givePermissionTo(['work_orders.view_assessments']);
        }
        Role::findByName('System Administrator', 'web')->givePermissionTo(config('authorization.permissions'));

        Role::findByName('FMO Head', 'web')->givePermissionTo([
            'dashboard.view_management', 'reports.view_work_orders', 'reports.export_work_orders',
            'reports.view_personnel_history', 'reports.view_location_history',
        ]);
        Role::findByName('FMO Dispatcher', 'web')->givePermissionTo([
            'dashboard.view_management', 'reports.view_work_orders', 'reports.export_work_orders',
            'reports.view_personnel_history', 'reports.view_location_history',
        ]);
        foreach (['Campus Director', 'Director for Instruction', 'FMO Oversight'] as $role) {
            Role::findByName($role, 'web')->givePermissionTo([
                'dashboard.view_oversight', 'reports.view_work_orders', 'reports.export_work_orders',
                'reports.view_personnel_history', 'reports.view_location_history',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
