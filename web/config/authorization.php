<?php

return [
    'permissions' => [
        'users.view_pending_registrations',
        'users.approve_registration',
        'users.reject_registration',
        'users.request_registration_correction',
        'users.view',
        'users.manage_status',
        'users.assign_roles',
        'users.assign_permissions',
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
        'permissions.view',
        'permissions.manage',
    ],
    'delegable_permissions' => [
        'users.view_pending_registrations',
        'users.approve_registration',
        'users.reject_registration',
        'users.request_registration_correction',
    ],
    'core_roles' => [
        'Requester', 'FMO Staff', 'FMO Dispatcher', 'FMO Head',
        'Campus Director', 'Director for Instruction', 'FMO Oversight',
        'System Administrator',
    ],
];
