<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuleCategory;
use App\Enums\Severity;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Finding;
use App\Services\Validation\VerdictCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * El caso que motivo estos tests: los pesos escritos desde el panel se
 * ignoraban en silencio.
 *
 * El campo de parametros es un KeyValue y guarda cadenas. El calculador exigia
 * un arreglo, asi que descartaba el valor y seguia con los pesos del sistema.
 * El usuario cambiaba el numero, guardaba, revalidaba y obtenia exactamente el
 * mismo puntaje, sin error que investigar.
 *
 * Vive en Feature y no en Unit aunque no toque la base de datos: el calculador
 * escribe avisos con la fachada Log, y una fachada sin aplicacion detras lanza
 * "A facade root has not been set". Ese es justamente el comportamiento que hay
 * que comprobar —que el fallo deje rastro— asi que el test necesita la
 * aplicacion levantada.
 */
class VerdictCalculatorPesosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Se espia el log en vez de silenciarlo: interesa poder afirmar que el
        // aviso se emitio, no solo que el codigo no reviente.
        Log::spy();
    }

    private function marcaCon(mixed $scoringWeights): Brand
    {
        $client = new Client();
        $client->settings = [];

        $brand = new Brand();
        $brand->settings = $scoringWeights === null ? [] : ['scoring_weights' => $scoringWeights];

        // Con la relacion cargada a mano, Brand::clientSettings() no consulta
        // la base: el test corre sin migraciones.
        $brand->setRelation('client', $client);

        return $brand;
    }

    /** @param  array<int, Severity>  $severidades */
    private function hallazgos(array $severidades): Collection
    {
        return collect($severidades)->map(function (Severity $s): Finding {
            $f = new Finding();
            $f->severity = $s;
            $f->category = RuleCategory::Copy;

            return $f;
        });
    }

    /** @return array<string, float> */
    private function pesos(mixed $scoringWeights): array
    {
        $resultado = (new VerdictCalculator())->calculate(
            $this->hallazgos([]),
            $this->marcaCon($scoringWeights),
            5,
        );

        return $resultado['scoring_formula_snapshot']['weights'];
    }

    public function test_sin_ajuste_usa_los_pesos_del_sistema(): void
    {
        $pesos = $this->pesos(null);

        $this->assertSame(15.0, $pesos['major']);
        $this->assertSame(5.0, $pesos['minor']);
        $this->assertSame(0.0, $pesos['blocking']);
    }

    /**
     * El caso central: esto es exactamente lo que produce el panel al escribir
     * el JSON en el campo de valor.
     */
    public function test_acepta_el_json_en_texto_que_produce_el_panel(): void
    {
        $pesos = $this->pesos('{"blocking":0,"major":20,"minor":8,"info":0}');

        $this->assertSame(20.0, $pesos['major']);
        $this->assertSame(8.0, $pesos['minor']);
    }

    public function test_sigue_aceptando_un_arreglo_real(): void
    {
        $pesos = $this->pesos(['blocking' => 0, 'major' => 20, 'minor' => 8, 'info' => 0]);

        $this->assertSame(20.0, $pesos['major']);
        $this->assertSame(8.0, $pesos['minor']);
    }

    public function test_un_texto_ilegible_no_rompe_y_queda_registrado(): void
    {
        $pesos = $this->pesos('mayor 20, menor 8');

        $this->assertSame(15.0, $pesos['major']);
        $this->assertSame(5.0, $pesos['minor']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, 'no se pudo interpretar'))
            ->once();
    }

    /**
     * Un JSON parcial es un caso legitimo: cambiar solo lo que interesa y
     * heredar el resto.
     */
    public function test_las_claves_ausentes_conservan_el_valor_del_sistema(): void
    {
        $pesos = $this->pesos('{"major":25}');

        $this->assertSame(25.0, $pesos['major']);
        $this->assertSame(5.0, $pesos['minor']);
        $this->assertSame(0.0, $pesos['blocking']);
    }

    public function test_una_clave_mal_escrita_no_altera_nada_y_queda_registrada(): void
    {
        $pesos = $this->pesos('{"mayor":25}');

        $this->assertSame(15.0, $pesos['major']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, 'claves que el sistema no usa'))
            ->once();
    }

    /**
     * Un peso negativo sumaria puntaje por incumplir: una pieza con muchos
     * hallazgos sacaria mejor nota que una impecable.
     */
    public function test_un_peso_negativo_se_acota_a_cero(): void
    {
        $pesos = $this->pesos('{"major":-30}');

        $this->assertSame(0.0, $pesos['major']);
    }

    public function test_un_valor_no_numerico_cae_al_del_sistema(): void
    {
        $pesos = $this->pesos('{"major":"mucho"}');

        $this->assertSame(15.0, $pesos['major']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, 'no numerico'))
            ->once();
    }

    /** El efecto real sobre el puntaje, que es lo que el usuario ve. */
    public function test_el_puntaje_cambia_con_los_pesos_del_panel(): void
    {
        $findings = $this->hallazgos([
            Severity::Blocking,
            Severity::Major, Severity::Major, Severity::Major,
            Severity::Minor, Severity::Minor,
        ]);

        $calculador = new VerdictCalculator();

        // Con los pesos del sistema: 100 - (0 + 45 + 10) = 45
        $porOmision = $calculador->calculate($findings, $this->marcaCon(null), 31);
        $this->assertSame(45.0, $porOmision['score']);

        // Con mayor en 20 y menor en 8: 100 - (0 + 60 + 16) = 24
        $ajustado = $calculador->calculate(
            $findings,
            $this->marcaCon('{"blocking":0,"major":20,"minor":8,"info":0}'),
            31,
        );
        $this->assertSame(24.0, $ajustado['score']);

        // El bloqueante no toca el puntaje, pero manda en el veredicto.
        $this->assertSame('rejected', $ajustado['status']);
    }

    public function test_el_veredicto_declara_de_donde_salieron_los_pesos(): void
    {
        $sistema = (new VerdictCalculator())->calculate($this->hallazgos([]), $this->marcaCon(null), 5);
        $propio = (new VerdictCalculator())->calculate($this->hallazgos([]), $this->marcaCon('{"major":20}'), 5);

        $this->assertSame('sistema', $sistema['scoring_formula_snapshot']['weights_source']);
        $this->assertSame('personalizados', $propio['scoring_formula_snapshot']['weights_source']);
    }
}
