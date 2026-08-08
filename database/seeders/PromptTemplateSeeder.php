<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RuleSetStatus;
use App\Models\PromptTemplate;
use Illuminate\Database\Seeder;

/**
 * Plantilla de prompt versionada.
 *
 * Vive en base de datos y no en el codigo porque validation_runs guarda el
 * prompt_template_id de cada ejecucion: sin version, un cambio de redaccion
 * volveria inexplicables los veredictos anteriores.
 */
class PromptTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $key = (string) config('ai.prompt_key', 'piece_validation');

        if (PromptTemplate::query()->where('key', $key)->whereNull('brand_id')->exists()) {
            $this->command?->info('La plantilla global ya existe, se omite.');

            return;
        }

        PromptTemplate::create([
            'brand_id' => null,
            'key' => $key,
            'version' => 1,
            'name' => 'Validacion de pieza grafica v1',
            'status' => RuleSetStatus::Published,
            'published_at' => now(),
            'system_prompt' => self::systemPrompt(),
            'user_prompt_template' => self::userPromptTemplate(),
            'output_schema' => self::outputSchema(),
        ]);

        $this->command?->info('Plantilla global publicada: '.$key.' v1');
    }

    private static function systemPrompt(): string
    {
        return <<<'TXT'
        Eres un revisor de piezas graficas publicitarias. Tu trabajo es verificar si una pieza
        cumple las reglas de marca que se te entregan, y reportar unicamente incumplimientos
        verificables.

        Principios que rigen tu evaluacion:

        1. Cita evidencia textual. Todo hallazgo debe citar el fragmento exacto de la pieza que
           lo motiva. Si no puedes citar nada concreto, no reportes el hallazgo.

        2. Evalua solo contra las reglas entregadas. No apliques criterios propios de diseno ni
           preferencias esteticas. Si algo te parece mejorable pero ninguna regla lo prohibe, no
           es un hallazgo.

        3. Cada hallazgo referencia una regla. Usa el codigo exacto que aparece entre corchetes
           en la lista de reglas. Un hallazgo sin codigo de regla no sirve.

        4. Prefiere el falso negativo al falso positivo. Reportar un incumplimiento que no existe
           erosiona la confianza en el sistema mas rapido que dejar pasar uno dudoso. Ante la
           duda, no reportes o baja la confianza.

        5. Comunicacion financiera. Cuando la pieza promocione productos o servicios de inversion,
           revisa con especial cuidado: promesas o garantias de rendimiento, ganancias presentadas
           como seguras, proyecciones expuestas como hechos, ausencia de leyenda de riesgo, y
           rendimientos historicos sin su descargo. Estas son las que importan.

        6. Distingue lo que no puedes juzgar. Si la resolucion no te permite leer un texto pequeno
           o determinar si un elemento esta presente, dilo en las notas en vez de adivinar.

        Los hallazgos deterministas que se te entregan ya fueron calculados por codigo, con
        medicion exacta. No los repitas ni los contradigas: son datos, no opiniones. Usalos como
        contexto para tu propio analisis.

        Escribe siempre en espanol neutro. Las descripciones van dirigidas a quien diseno la
        pieza: concretas, sin rodeos y accionables.
        TXT;
    }

    private static function userPromptTemplate(): string
    {
        return <<<'TXT'
        Analiza esta pieza grafica.

        ## Contexto
        Cliente: {{client_name}}
        Marca: {{brand_name}}
        Canal de publicacion: {{channel}}
        Campana: {{campaign}}
        Producto: {{product}}
        Objetivo declarado: {{objective}}
        Dimensiones: {{width}} x {{height}} px

        ## Reglas que debes evaluar
        {{rules}}

        ## Activos de marca que deben aparecer
        {{brand_assets}}

        ## Hallazgos ya detectados por codigo
        {{deterministic_findings}}

        ## Paleta de colores detectada en la pieza
        {{extracted_palette}}

        ## Lo que debes devolver

        1. El texto visible completo de la pieza, transcrito tal cual aparece.
        2. La ubicacion del logotipo si esta presente, con su recuadro en coordenadas
           normalizadas de 0 a 1, donde 0,0 es la esquina superior izquierda.
        3. Un hallazgo por cada regla incumplida, citando el codigo de la regla y la
           evidencia textual que lo motiva.

        Si la pieza cumple todas las reglas, devuelve la lista de hallazgos vacia.
        TXT;
    }

    /**
     * @return array<string, mixed>
     */
    private static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'extracted_text' => [
                    'type' => 'string',
                    'description' => 'Texto visible completo de la pieza, transcrito tal como aparece, respetando saltos de linea.',
                ],
                'text_blocks' => [
                    'type' => 'array',
                    'description' => 'Bloques de texto con su ubicacion, para poder medir contraste y legibilidad por zona.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'role' => [
                                'type' => 'string',
                                'enum' => ['titular', 'subtitulo', 'cuerpo', 'legal', 'llamado_a_accion', 'otro'],
                            ],
                            'bounding_box' => [
                                'type' => 'object',
                                'properties' => [
                                    'x' => ['type' => 'number'],
                                    'y' => ['type' => 'number'],
                                    'width' => ['type' => 'number'],
                                    'height' => ['type' => 'number'],
                                ],
                                'required' => ['x', 'y', 'width', 'height'],
                            ],
                        ],
                        'required' => ['text', 'role'],
                    ],
                ],
                'logo' => [
                    'type' => 'object',
                    'description' => 'Ubicacion del logotipo de la marca en la pieza.',
                    'properties' => [
                        'detected' => ['type' => 'boolean'],
                        'confidence' => ['type' => 'number', 'description' => 'De 0 a 1.'],
                        'bounding_box' => [
                            'type' => 'object',
                            'description' => 'Coordenadas normalizadas de 0 a 1. Solo si detected es verdadero.',
                            'properties' => [
                                'x' => ['type' => 'number'],
                                'y' => ['type' => 'number'],
                                'width' => ['type' => 'number'],
                                'height' => ['type' => 'number'],
                            ],
                            'required' => ['x', 'y', 'width', 'height'],
                        ],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['detected'],
                ],
                'findings' => [
                    'type' => 'array',
                    'description' => 'Un elemento por cada regla incumplida. Vacio si la pieza cumple todo.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'rule_code' => [
                                'type' => 'string',
                                'description' => 'Codigo exacto de la regla incumplida, tal como aparece entre corchetes.',
                            ],
                            'category' => [
                                'type' => 'string',
                                'enum' => ['palette', 'typography', 'copy', 'tone', 'composition', 'compliance', 'required_assets'],
                            ],
                            'severity' => [
                                'type' => 'string',
                                'enum' => ['blocking', 'major', 'minor', 'info'],
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Que incumple la pieza, en una o dos frases dirigidas a quien la diseno.',
                            ],
                            'evidence' => [
                                'type' => 'string',
                                'description' => 'Fragmento textual exacto de la pieza que motiva el hallazgo.',
                            ],
                            'suggestion' => [
                                'type' => 'string',
                                'description' => 'Correccion concreta.',
                            ],
                            'confidence' => [
                                'type' => 'number',
                                'description' => 'De 0 a 1. Por debajo de 0.6 el hallazgo se marca como dudoso.',
                            ],
                        ],
                        'required' => ['rule_code', 'category', 'severity', 'description', 'confidence'],
                    ],
                ],
                'overall_notes' => [
                    'type' => 'string',
                    'description' => 'Observaciones que no encajan en ninguna regla, o limitaciones del analisis.',
                ],
            ],
            'required' => ['extracted_text', 'logo', 'findings'],
        ];
    }
}
