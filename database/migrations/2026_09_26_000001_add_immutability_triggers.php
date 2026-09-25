<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inmutabilidad de la evidencia a nivel de base de datos.
 *
 * El trait Immutable protege los modelos, pero se salta con el query builder
 * (Model::whereKey()->update()), con DB::table() o con acceso directo a
 * MySQL. Estos triggers cierran esas vias: la bitacora, los veredictos y las
 * revisiones humanas no se modifican ni se borran; de los hallazgos solo
 * puede cambiar el resultado de la revision humana.
 *
 * Nota para el servidor: con binlog activo, MySQL exige el privilegio SUPER o
 * log_bin_trust_function_creators=1 para crear triggers. Ver RUNBOOK.
 */
return new class extends Migration
{
    /** @var array<string, string> tabla => mensaje */
    private const SIN_CAMBIOS = [
        'audit_logs' => 'La bitacora de auditoria es inmutable.',
        'verdicts' => 'Los veredictos son evidencia de auditoria y no se modifican.',
        'human_reviews' => 'Las revisiones humanas son evidencia de auditoria y no se modifican.',
    ];

    /** Columnas de findings que NO pueden cambiar. */
    private const FINDINGS_FIJAS = [
        'validation_run_id', 'rule_code', 'category', 'severity', 'origin',
        'description', 'evidence', 'evidence_data', 'suggestion', 'confidence',
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();

        foreach (self::SIN_CAMBIOS as $tabla => $mensaje) {
            $this->crear($driver, "{$tabla}_sin_update", $tabla, 'UPDATE', null, $mensaje);
            $this->crear($driver, "{$tabla}_sin_delete", $tabla, 'DELETE', null, $mensaje);
        }

        // rule_id puede pasar a NULL (FK nullOnDelete) sin tocar la evidencia.
        $cambios = array_map(
            fn (string $c): string => $driver === 'mysql' ? "NOT (NEW.{$c} <=> OLD.{$c})" : "NEW.{$c} IS NOT OLD.{$c}",
            self::FINDINGS_FIJAS,
        );
        $cambios[] = $driver === 'mysql'
            ? '(NEW.rule_id IS NOT NULL AND NOT (NEW.rule_id <=> OLD.rule_id))'
            : '(NEW.rule_id IS NOT NULL AND NEW.rule_id IS NOT OLD.rule_id)';

        $this->crear($driver, 'findings_solo_revision', 'findings', 'UPDATE', implode(' OR ', $cambios),
            'De un hallazgo solo puede cambiar el resultado de la revision humana.');
        $this->crear($driver, 'findings_sin_delete', 'findings', 'DELETE', null,
            'Los hallazgos son evidencia de auditoria y no se borran.');
    }

    public function down(): void
    {
        $nombres = ['findings_solo_revision', 'findings_sin_delete'];

        foreach (array_keys(self::SIN_CAMBIOS) as $tabla) {
            $nombres[] = "{$tabla}_sin_update";
            $nombres[] = "{$tabla}_sin_delete";
        }

        foreach ($nombres as $n) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$n}");
        }
    }

    private function crear(string $driver, string $nombre, string $tabla, string $evento, ?string $condicion, string $mensaje): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS {$nombre}");

        $mensaje = str_replace("'", "''", $mensaje);

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $cuerpo = $condicion === null
                ? "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$mensaje}';"
                : "IF {$condicion} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$mensaje}'; END IF;";

            DB::unprepared("CREATE TRIGGER {$nombre} BEFORE {$evento} ON {$tabla} FOR EACH ROW BEGIN {$cuerpo} END");

            return;
        }

        if ($driver === 'sqlite') {
            $cuando = $condicion === null ? '' : " WHEN {$condicion}";

            DB::unprepared("CREATE TRIGGER {$nombre} BEFORE {$evento} ON {$tabla} FOR EACH ROW{$cuando} BEGIN SELECT RAISE(ABORT, '{$mensaje}'); END");

            return;
        }

        // Otros motores (pgsql): se deja constancia en vez de fallar la migracion.
        logger()->warning("Trigger {$nombre} no creado: motor {$driver} sin soporte en esta migracion.");
    }
};
