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
        // Flujo del director: quien envia y quien decide son permisos
        // separados a proposito.
        'director.send',
        'director.decide',
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
                'director.send',
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
                'director.send',
            ],
            'brand_admin' => [
                // Un administrador de marca tambien trabaja: sin estos dos no
                // podia cargar una pieza, y la salida natural era darle
                // ademas el rol uploader. Apilar roles para tapar un hueco es
                // como se llega despues a apilar auditor sin medir el efecto.
                'submission.create',
                'submission.view_own',
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
                'director.send',
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
            // Aprueba o devuelve las piezas que le envian, solo de las marcas de
            // sus equipos. No carga, no valida y no cambia veredictos: su
            // decision es otra capa, posterior a la del validador.
            'director' => [
                'submission.view_brand',
                'validation.view',
                'knowledge.view',
                'director.decide',
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
