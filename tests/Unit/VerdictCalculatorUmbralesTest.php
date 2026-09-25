<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuleCategory;
use App\Enums\Severity;
use App\Enums\VerdictStatus;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Finding;
use App\Services\Validation\VerdictCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * El estado del veredicto, no el puntaje.
 *
 * El hueco que motiva estos tests: "Rechazado" solo salia con un hallazgo
 * bloqueante. Una pieza con siete incumplimientos mayores y ninguno bloqueante
 * daba cero de puntaje y se iba como "Aprobada con observaciones". El estado y
 * el numero decian cosas distintas sobre la misma pieza, y el que se lee
 * primero es el estado.
 *
 * Ahora hay dos cortes sobre la misma recta —rechazo y observaciones—, los dos
 * configurables. Lo que estos tests fijan es que sigan en orden y que ninguno
 * pueda desaparecer sin dejar rastro.
 *
 * Vive en Feature por la misma razon que el de pesos: el calculador escribe
 * avisos con la fachada Log, que necesita la aplicacion levantada. No toca la
 * base de datos.
 */
class VerdictCalculatorUmbralesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();
    }

    /** @param  array<string, mixed>  $settings */
    private function marcaCon(array $settings): Brand
    {
        $client = new Client();
        $client->settings = [];

        $brand = new Brand();
        $brand->settings = $settings;

        // Con la relacion cargada a mano, Brand::clientSettings() no consulta
        // la base: el test corre sin migraciones.
        $brand->setRelation('client', $client);

        return $brand;
    }

    /**
     * @param  array<string, int>  $conteo  cuantos hallazgos de cada severidad
     */
    private function hallazgos(array $conteo): Collection
    {
        $salida = collect();

        foreach ($conteo as $severidad => $cuantos) {
            for ($i = 0; $i < $cuantos; $i++) {
                $f = new Finding();
                $f->severity = Severity::from($severidad);
                $f->category = RuleCategory::Copy;
                $salida->push($f);
            }
        }

        return $salida;
    }

    /** @param  array<string, int>  $conteo */
    private function estado(array $conteo, ?Brand $brand = null): VerdictStatus
    {
        $resultado = (new VerdictCalculator())->calculate($this->hallazgos($conteo), $brand, 5);

        return VerdictStatus::from($resultado['status']);
    }

    /* ---------------------------------------------------------------- */

    public function test_una_pieza_sin_bloqueantes_pero_con_puntaje_bajo_se_rechaza(): void
    {
        // Cuatro mayores son 60 puntos de penalizacion: 40 de puntaje, bajo el
        // corte de 50. Este es el caso que antes salia "con observaciones".
        $this->assertSame(VerdictStatus::Rejected, $this->estado(['major' => 4]));
    }

    public function test_el_corte_de_rechazo_es_estricto(): void
    {
        // Tres mayores y dos menores son exactamente 55: por encima de 50, no
        // se rechaza. Se fija el borde para que un cambio de redondeo no lo
        // mueva sin que nadie se entere.
        $this->assertSame(
            VerdictStatus::ApprovedWithObservations,
            $this->estado(['major' => 3])
        );

        $this->assertSame(
            VerdictStatus::Rejected,
            $this->estado(['major' => 3, 'minor' => 2])
        );
    }

    public function test_el_bloqueante_rechaza_aunque_el_puntaje_sea_perfecto(): void
    {
        // El bloqueante pesa cero: el puntaje queda en 100 y el estado en
        // rechazado. Son dos hechos ciertos a la vez y no deben interferir.
        $resultado = (new VerdictCalculator())->calculate($this->hallazgos(['blocking' => 1]), null, 5);

        $this->assertSame(VerdictStatus::Rejected->value, $resultado['status']);
        $this->assertSame(100.0, $resultado['score']);
    }

    public function test_un_hallazgo_leve_ya_no_baja_el_veredicto_por_si_solo(): void
    {
        // Un menor son 95 puntos, sobre el umbral de observaciones. Antes
        // cualquier hallazgo mandaba la pieza a observaciones sin mirar el
        // puntaje, y eso hacia inerte el ajuste.
        $this->assertSame(VerdictStatus::Approved, $this->estado(['minor' => 1]));
    }

    public function test_el_comportamiento_anterior_se_recupera_con_el_umbral_en_cien(): void
    {
        // Sin tocar codigo: quien quiera que cualquier hallazgo con peso baje
        // el veredicto pone observation_threshold en 100.
        $marca = $this->marcaCon(['observation_threshold' => '100']);

        $this->assertSame(
            VerdictStatus::ApprovedWithObservations,
            $this->estado(['minor' => 1], $marca)
        );
    }

    public function test_los_informativos_no_bajan_el_veredicto(): void
    {
        // Pesan cero por omision, asi que el puntaje queda en 100 incluso con
        // el umbral de observaciones al maximo.
        $marca = $this->marcaCon(['observation_threshold' => '100']);

        $this->assertSame(VerdictStatus::Approved, $this->estado(['info' => 3], $marca));
    }

    public function test_el_umbral_de_rechazo_se_puede_endurecer_por_marca(): void
    {
        $marca = $this->marcaCon(['rejection_threshold' => '75']);

        // Dos mayores son 70: aprobado con observaciones con el corte del
        // sistema, rechazado con el de esta marca.
        $this->assertSame(VerdictStatus::ApprovedWithObservations, $this->estado(['major' => 2]));
        $this->assertSame(VerdictStatus::Rejected, $this->estado(['major' => 2], $marca));
    }

    public function test_el_umbral_llega_como_cadena_desde_el_panel(): void
    {
        // El KeyValue del panel guarda texto. Es exactamente el fallo que tuvo
        // scoring_weights, y no debe repetirse con los umbrales.
        $marca = $this->marcaCon(['rejection_threshold' => '75']);

        $resultado = (new VerdictCalculator())->calculate($this->hallazgos(['major' => 2]), $marca, 5);

        $this->assertSame(75.0, $resultado['scoring_formula_snapshot']['rejection_threshold']);
    }

    public function test_un_rechazo_por_encima_del_de_observaciones_se_recorta_y_avisa(): void
    {
        // Con rechazo 95 y observaciones 90, la banda de observaciones
        // desaparece. Se recorta al de observaciones y queda el aviso.
        $marca = $this->marcaCon([
            'rejection_threshold' => '95',
            'observation_threshold' => '90',
        ]);

        $resultado = (new VerdictCalculator())->calculate($this->hallazgos(['minor' => 1]), $marca, 5);

        $this->assertSame(90.0, $resultado['scoring_formula_snapshot']['rejection_threshold']);
        $this->assertSame(VerdictStatus::Approved->value, $resultado['status']);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $mensaje): bool => str_contains($mensaje, 'rejection_threshold es mayor')
        );
    }

    public function test_un_umbral_fuera_de_rango_se_ignora_y_avisa(): void
    {
        // 150 rechazaria hasta la pieza impecable. Se vuelve al del sistema.
        $marca = $this->marcaCon(['rejection_threshold' => '150']);

        $resultado = (new VerdictCalculator())->calculate($this->hallazgos([]), $marca, 5);

        $this->assertSame(50.0, $resultado['scoring_formula_snapshot']['rejection_threshold']);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $mensaje): bool => str_contains($mensaje, 'fuera del rango')
        );
    }

    public function test_un_umbral_no_numerico_se_ignora_y_avisa(): void
    {
        $marca = $this->marcaCon(['rejection_threshold' => 'cincuenta']);

        $resultado = (new VerdictCalculator())->calculate($this->hallazgos([]), $marca, 5);

        $this->assertSame(50.0, $resultado['scoring_formula_snapshot']['rejection_threshold']);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $mensaje): bool => str_contains($mensaje, 'no numerico')
        );
    }

    public function test_un_umbral_vacio_usa_el_del_sistema_sin_avisar(): void
    {
        // Dejar el campo en blanco es una forma legitima de decir "el que
        // venga por omision". No es un error y no debe ensuciar el log.
        $marca = $this->marcaCon(['rejection_threshold' => '']);

        $resultado = (new VerdictCalculator())->calculate($this->hallazgos([]), $marca, 5);

        $this->assertSame(50.0, $resultado['scoring_formula_snapshot']['rejection_threshold']);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_cero_reglas_resueltas_manda_por_encima_de_todo(): void
    {
        // Una pieza que nadie midio no es una pieza rechazada ni aprobada.
        $resultado = (new VerdictCalculator())->calculate($this->hallazgos([]), null, 0);

        $this->assertSame(VerdictStatus::NotEvaluated->value, $resultado['status']);
        $this->assertSame(0.0, $resultado['score']);
    }

    public function test_la_version_de_la_formula_sube_a_tres(): void
    {
        // Cambio la regla del estado. Sin subir la version, un veredicto viejo
        // y uno nuevo se explicarian con la misma formula siendo distintos.
        $resultado = (new VerdictCalculator())->calculate($this->hallazgos([]), null, 5);

        $this->assertSame(3, $resultado['scoring_formula_snapshot']['formula_version']);
    }
}
