<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Client;
use App\Services\AuditLogger;
use App\Services\Consumo\ReporteDeConsumo;
use App\Support\Alcance;
use App\Support\Fecha;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

/**
 * Tokens y costo de IA por cliente en un periodo.
 *
 * Sirve para facturar o prorratear el gasto de la API entre clientes. Los
 * tokens son los reales de cada respuesta; el costo se calcula con la tarifa
 * publica configurada (ver ReporteDeConsumo).
 */
class ConsumoIa extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Operación';

    protected static ?string $navigationLabel = 'Consumo de IA';

    protected static ?string $title = 'Consumo de IA por cliente';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.consumo-ia';

    public ?string $desde = null;

    public ?string $hasta = null;

    public ?string $cliente = null;

    /**
     * Mismo permiso que la bitacora: es informacion de gestion, no operativa.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('audit.view') === true;
    }

    public function mount(): void
    {
        $hoy = CarbonImmutable::now(Fecha::zona());
        $this->desde = $hoy->startOfMonth()->toDateString();
        $this->hasta = $hoy->toDateString();
    }

    public function periodo(string $cual): void
    {
        $hoy = CarbonImmutable::now(Fecha::zona());

        [$d, $h] = match ($cual) {
            'mes_anterior' => [$hoy->subMonthNoOverflow()->startOfMonth(), $hoy->subMonthNoOverflow()->endOfMonth()],
            '30_dias' => [$hoy->subDays(29), $hoy],
            'anio' => [$hoy->startOfYear(), $hoy],
            default => [$hoy->startOfMonth(), $hoy],
        };

        $this->desde = $d->toDateString();
        $this->hasta = $h->toDateString();
    }

    /**
     * @return array<int, string>
     */
    public function getClientesProperty(): array
    {
        return Alcance::clientesVisibles(Client::query())->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getReporteProperty(): ?array
    {
        [$desde, $hasta] = $this->rango();

        if ($desde === null) {
            return null;
        }

        // El cliente es una propiedad publica: se revalida contra el alcance.
        $clientes = filled($this->cliente) && array_key_exists((int) $this->cliente, $this->clientes)
            ? [(int) $this->cliente]
            : null;

        return app(ReporteDeConsumo::class)->generar(
            $desde,
            $hasta,
            auth()->user()->accessibleBrandIds()->all(),
            $clientes,
        );
    }

    public function exportar(): StreamedResponse
    {
        $r = $this->reporte;
        abort_if($r === null, 422);

        app(AuditLogger::class)->log('consumo.exported', null, newValues: [
            'desde' => $r['desde']->toDateString(),
            'hasta' => $r['hasta']->toDateString(),
            'cliente' => $this->cliente,
        ]);

        $nombre = sprintf('consumo-ia_%s_%s.csv', $r['desde']->toDateString(), $r['hasta']->toDateString());

        return response()->streamDownload(function () use ($r): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea las tildes
            fputcsv($out, ['Cliente', 'Marca', 'Modelo', 'Validaciones con IA', 'Tokens entrada', 'Tokens salida', 'Tokens totales', 'Costo USD (tarifa vigente)', 'Costo USD registrado al validar'], ';');

            foreach ($r['clientes'] as $c) {
                foreach ($c['marcas'] as $m) {
                    fputcsv($out, [$c['cliente'], $m['nombre'], '', $m['llamadas'], $m['entrada'], $m['salida'], $m['entrada'] + $m['salida'], $this->num($m), number_format((float) $m['registrado'], 4, ',', '')], ';');
                }

                foreach ($c['modelos'] as $m) {
                    fputcsv($out, [$c['cliente'], '(todas)', $m['nombre'], $m['llamadas'], $m['entrada'], $m['salida'], $m['entrada'] + $m['salida'], $this->num($m), number_format((float) $m['registrado'], 4, ',', '')], ';');
                }

                fputcsv($out, [$c['cliente'], 'TOTAL CLIENTE', '', $c['llamadas'], $c['entrada'], $c['salida'], $c['entrada'] + $c['salida'], $this->num($c), number_format((float) $c['registrado'], 4, ',', '')], ';');
            }

            $t = $r['total'];
            fputcsv($out, ['TOTAL', '', '', $t['llamadas'], $t['entrada'], $t['salida'], $t['entrada'] + $t['salida'], $this->num($t), number_format((float) $t['registrado'], 4, ',', '')], ';');
            fputcsv($out, [], ';');
            fputcsv($out, ['Periodo', $r['desde']->format('d/m/Y').' al '.$r['hasta']->format('d/m/Y').' ('.Fecha::zona().')'], ';');
            fputcsv($out, ['Tarifa', 'Publica de Anthropic verificada el '.config('ai.pricing_verified_at').'. No incluye descuentos ni impuestos. La factura de Anthropic es la fuente oficial.'], ';');
            fclose($out);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function rango(): array
    {
        try {
            $d = CarbonImmutable::parse((string) $this->desde, Fecha::zona());
            $h = CarbonImmutable::parse((string) $this->hasta, Fecha::zona());
        } catch (Throwable) {
            return [null, null];
        }

        if ($h->lessThan($d) || $d->diffInDays($h) > 400) {
            return [null, null];
        }

        return [$d, $h];
    }

    /** @param  array<string, mixed>  $fila */
    private function num(array $fila): string
    {
        if ($fila['costo'] === null) {
            return 'sin tarifa';
        }

        return number_format((float) $fila['costo'], 4, ',', '').($fila['costo_incompleto'] ? ' (parcial)' : '');
    }
}
