<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /** @var array<int, string> */
    private const PERMISSIONS = [
        'submission.create',
        'submission.view_own',
        'submission.view_brand',
        'submission.view_any',
        'validation.trigger',
        'validation.view',
        'review.perform',
        'review.override',
        'knowledge.view',
        'knowledge.edit',
        'knowledge.publish',
        'knowledge.publish_client',
        'audit.view',
        'audit.export',
        'user.manage',
        'team.manage',
        'brand.manage',
        'client.manage',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        $roles = [
            'uploader' => [
                'submission.create',
                'submission.view_own',
                'validation.trigger',
                'validation.view',
                'knowledge.view',
            ],
            'reviewer' => [
                'submission.create',
                'submission.view_own',
                'submission.view_brand',
                'validation.trigger',
                'validation.view',
                'review.perform',
                'review.override',
                'knowledge.view',
            ],
            'brand_admin' => [
                'submission.view_brand',
                'validation.trigger',
                'validation.view',
                'review.perform',
                'review.override',
                'knowledge.view',
                'knowledge.edit',
                'knowledge.publish',
                'audit.view',
                'user.manage',
            ],
            // Solo este rol publica reglas corporativas: un cambio hecho para una
            // marca no debe propagarse al resto sin aprobacion del nivel cliente.
            'client_admin' => [
                'submission.view_brand',
                'validation.view',
                'knowledge.view',
                'knowledge.edit',
                'knowledge.publish',
                'knowledge.publish_client',
                'audit.view',
                'user.manage',
                'team.manage',
                'brand.manage',
            ],
            // Observador puro: ni siquiera puede disparar validaciones.
            'auditor' => [
                'submission.view_any',
                'validation.view',
                'knowledge.view',
                'audit.view',
                'audit.export',
            ],
            'super_admin' => self::PERMISSIONS,
        ];

        foreach ($roles as $name => $permissions) {
            Role::findOrCreate($name)->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
