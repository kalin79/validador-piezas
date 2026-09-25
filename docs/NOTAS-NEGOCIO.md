# Notas de negocio

> Notas originales del README (agosto 2026), movidas aquí sin cambios al reescribir el README en la auditoría de septiembre 2026.

######## ACTUALMENTE COMO SE MANEJA MI SISTEMA ############

No. Hoy no aprende nada. Y quiero ser preciso en esto porque es lo primero que un cliente te va a preguntar, y la respuesta correcta es mejor argumento que la que suena bien.

Cómo funciona realmente

Tu aplicación tiene tres capas, y ninguna aprende:

Aritmética. Distancia de color, contraste, dimensiones, medidas del logo. Es cálculo puro. La misma pieza da el mismo número hoy y en tres años. No hay nada que aprender ahí — ya es exacto.

Reglas escritas por personas. COMP-001, TONE-500, PAL-500. Las escribiste tú desde el panel. Versionadas, inmutables una vez publicadas.

Un modelo que juzga contra esas reglas. Claude lee la pieza y dice si cumple COMP-014. Pero no recuerda nada entre una llamada y otra. Cada validación empieza en cero. Si hoy corriges un juicio suyo, mañana vuelve a cometer el mismo error, porque nadie se lo contó.

El "conocimiento" del sistema no vive en un modelo entrenado: vive en tus reglas, tus paletas, tus brand assets y tu plantilla de prompt. Todo texto, todo editable, todo auditable.

Por qué eso es una virtud

Es tentador prometer que aprende. Pero piensa en la escena que vienes construyendo: un auditor pregunta por qué se rechazó una pieza en marzo.

Con el sistema actual respondes: "con estas siete reglas, versión 2, huella 47981bd7, y aquí está el texto exacto de cada una."

Con un sistema que aprende solo tendrías que responder: "el modelo había ajustado su criterio con las correcciones acumuladas hasta marzo." Eso no se puede reconstruir ni defender.

Un sistema de cumplimiento normativo cuyo criterio cambia sin que nadie lo apruebe es un pasivo, no un activo. Cuando alguien te diga "¿pero aprende?", la respuesta es: "el criterio no cambia solo — cambia cuando ustedes lo aprueban, y queda registrado quién y cuándo."

Qué significaría "que aprenda"

La palabra tapa cuatro cosas muy distintas:

Idea Qué es Veredicto
Reentrenar el modelo Fine-tuning con tus piezas Descartado. Rompe la auditabilidad y el soporte es limitado. Ya lo decidimos en el RAFA
Darle ejemplos Casos reales tuyos dentro del prompt Sí, y es lo de mayor valor
Recordar correcciones Que las decisiones humanas alimenten el criterio Sí, con aprobación de por medio
Medirse Saber si mejora o empeora Sí, y es lo primero
La Fase 4, en orden

Lo bueno es que el andamiaje ya está puesto desde la Fase 1. No parte de cero:

La tabla human_reviews existe y ValidationRun ya tiene la relación
Rule tiene columnas positive_examples y negative_examples sin usar
prompt_templates está versionada igual que las reglas

Primero, medir. Una pantalla de revisión donde un humano marca cada hallazgo como correcto, falso positivo o no detectado. Sin esto no sabes si el sistema mejora — solo tienes la sensación. Es lo único que convierte "creo que funciona" en un número.

Segundo, ejemplos dorados. Cuando el revisor marca un falso positivo, el caso se guarda contra esa regla. Esos ejemplos entran al prompt de la siguiente versión. El modelo no aprendió: la regla se volvió más precisa, y el cambio quedó versionado como cualquier otro.

Ahí está la clave del diseño: cuando notaste que COMP-014 era ambigua y la reescribiste, el sistema mejoró. Solo que quien aprendió fuiste tú. La Fase 4 automatiza la parte de detectar qué regla está fallando y proponer el ajuste — la aprobación sigue siendo humana.

Tercero, calibrar con datos. Hoy el umbral de rechazo es 50 y la tolerancia de color 5.0 ΔE porque yo los elegí con criterio. Con doscientas piezas revisadas puedes calcular cuál umbral separa mejor lo que tu equipo aprueba de lo que rechaza. Eso sí es aprendizaje real, sobre parámetros, no sobre un modelo opaco.

Cuarto, recuperar casos parecidos. Antes de juzgar una pieza, buscar entre las ya revisadas de esa marca las más similares y ponerlas en el prompt como referencia. Es RAG, el enfoque que elegimos desde el RAFA.

El orden importa y es contraintuitivo: hay que resistir la tentación de empezar por los ejemplos, que es lo vistoso. Sin la pantalla de revisión no tienes de dónde sacarlos, ni forma de saber si ayudaron.

Para tu presentación, la frase que yo usaría: "el sistema no aprende solo, y eso es deliberado. Aprende su equipo, y cada mejora queda firmada, versionada y reversible."

Si quieres, te armo el plan de la Fase 4 como documento — alcance, esquema de las pantallas y qué métricas mostrar. Dime y lo hago.

############## QUE SE GANA CON ESTA REVISION HUMAN, HACE QUE MI VALIDADOR SEA MEJOR ? ################

Por sí sola, no. Hoy es un instrumento de medición, no un mecanismo de mejora. Nada en el código toma esas revisiones y cambia el comportamiento del validador.

Lo que hace es decirte dónde está fallando y por qué. La mejora la haces tú.

Es la diferencia entre un termómetro y una medicina. El termómetro no cura a nadie — pero sin él estás medicando a ciegas.

Qué ganas concretamente

1. Sabes qué regla está mal escrita. Hoy, si el sistema marca cosas raras, tu única herramienta es la intuición. Con veinte revisiones aparece algo así:

COMP-014 38% ok 3 · falsos positivos 5
TONE-500 92% ok 11 · falsos positivos 1
PAL-500 100% ok 14 · falsos positivos 0

Eso ya no es opinión. COMP-014 está mal redactada y PAL-500 está fina. Sin el dato, alguien te habría dicho "la IA no sirve" y habrías cambiado de modelo — cuando el problema era una frase ambigua.

2. Sabes si un cambio mejoró o empeoró. Reescribes COMP-014, publicas v3, revalidas el lote. Si la precisión sube de 38% a 85%, funcionó. Si baja, lo reviertes. Sin medición, cada cambio es un acto de fe.

3. Tienes registro de aprobación humana — y esto vale independientemente de la IA. Muchas normativas de publicidad exigen que una persona identificada haya aprobado la pieza. Ahora queda: quién, cuándo, qué decidió, y si contradijo a la máquina y por qué. Eso es cumplimiento, no analítica.

4. Detectas deriva. El día que cambies de Sonnet a Haiku para ahorrar, las métricas te dicen si perdiste calidad. Hoy no tendrías forma de saberlo.

Cómo se convierte en mejora real

El ciclo, con un caso concreto:

Revisas diez piezas. COMP-014 sale al 38%.

Lees los cinco falsos positivos. Descubres el patrón: el modelo marca cualquier pieza con un testimonio, aunque no sea colaboración pagada. El enunciado dice "cuando hay testimonio o colaboración pagada" y esa "o" lo hace disparar de más.

Reescribes la regla: "cuando el testimonio proviene de una colaboración remunerada", y agregas dos ejemplos, uno que cumple y otro que no.

Publicas v3. El historial registra el cambio.

Revalidas el lote. La precisión sube a 85%.

El validador mejoró. No porque la máquina aprendiera, sino porque el sistema te mostró exactamente dónde apuntar. Y el cambio quedó firmado, versionado y reversible.

Lo que no te voy a vender

Cuesta tiempo humano. Si nadie revisa, esto no sirve para nada. Necesitas que alguien juzgue piezas de verdad — unas diez para ver señal gruesa, veinte o treinta para que la precisión por regla signifique algo.

No es automático. Podría serlo: los ejemplos que marcas como falso positivo pueden entrar solos al prompt de la siguiente versión de la regla. Eso es el paso dos de la Fase 4 y no está construido. Pero incluso ahí la aprobación seguiría siendo tuya, a propósito.

Para tu presentación, la frase que yo usaría: "el sistema no solo valida, se mide a sí mismo. Sabe en qué reglas se equivoca y cuánto. Y cada corrección queda firmada y es reversible."

Un competidor puede decir "usamos IA". Pocos pueden mostrar en pantalla en qué se equivocan y cuánto. Ese es el argumento que no se contesta fácil.

################### QUE ES UN FALSO POSITIVO ##################
Es cuando el sistema reporta un problema que no existe.

Un ejemplo de tu propio validador: COMP-014 exige identificar el contenido publicitario cuando hay colaboración pagada. Subes una pieza con el testimonio de un consumidor real, no pagado. El sistema marca "contenido publicitario no identificado" y la rechaza. La pieza estaba bien. El sistema se equivocó.

Eso es un falso positivo. En la pantalla de revisión, presionas ese botón y queda registrado.

Los cuatro casos posibles:

    El sistema dice que hay problema	El sistema no dice nada

Sí había problema Acierto Falso negativo — se le escapó
No había problema Falso positivo — falsa alarma Acierto

El falso negativo es el otro error: la pieza incumplía y el sistema no lo vio. En tu pantalla se registra cuando agregas un hallazgo en "lo que la máquina no vio".

Por qué el falso positivo pesa más de lo que parece: cada vez que el sistema rechaza una pieza correcta, alguien del equipo creativo tiene que rehacer trabajo que estaba bien. Dos o tres veces y dejan de leer los hallazgos, o peor, dejan de usar el validador.

Por eso el prompt de la Fase 3 le dice explícitamente al modelo que prefiera dejar pasar una duda antes que inventar un incumplimiento, y por eso los hallazgos con baja confianza se degradan en vez de bloquear.

Y por eso la métrica de precisión ordena las reglas de peor a mejor: la que más falsas alarmas produce es la que más rápido te quema la confianza del equipo.

###############

########## BLOQUEANTES ##########

Un bloqueante no es "un problema muy grave dentro de una escala". Es binario: si hay uno, la pieza no se publica, sin importar qué tan bien esté hecha todo lo demás. No se compensa, no se negocia, no se promedia.

Ponerle 100 puntos lo trataba como si estuviera en la escala de calidad, y eso lo debilitaba de dos formas: hacía que el puntaje dejara de informar, y sugería que con suficientes cosas buenas se podría equilibrar. No se puede.

Ahora la jerarquía es explícita:

Pregunta La responde Cómo
¿Se puede publicar? El estado Un bloqueante → Rechazado. Fin
¿Qué tan bien está hecha? El puntaje Mayores y menores, en escala

El bloqueante gana siempre porque decide la primera pregunta, que es la que importa antes que ninguna. El puntaje solo describe.

La consecuencia práctica

Sé cuidadoso con cuáles marcas como bloqueantes. Hoy tienes tres de 31 —COMP-001, COMP-002, COMP-003, todas normativas y todas con is_locked— y esa proporción es sana.

Si mañana marcas como bloqueante una regla de estilo, cada pieza con un detalle menor va a salir rechazada y el equipo va a empezar a ignorar el veredicto. El bloqueante conserva su fuerza mientras se use poco.

El criterio que yo aplicaría: bloqueante es lo que expondría a la universidad ante Indecopi o Sunedu. Todo lo demás, por feo que sea, es Mayor.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

