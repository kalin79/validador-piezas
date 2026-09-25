# Validador de Piezas

Revisa las piezas gráficas contra las reglas de marca **antes** de que lleguen al director de arte. Cada pieza sale con uno de estos veredictos: aprobada, aprobada con observaciones, rechazada o requiere revisión. El veredicto viene con el motivo, la evidencia y la corrección sugerida.

Principio rector: **sin evidencia no hay "cumple"**. Si una regla no se pudo verificar (falta el canal, la IA falló, el modelo dudó), la pieza no se aprueba y pasa a revisión humana.

## Cómo funciona

1. **Motor determinista.** Mide formato, paleta (CIEDE2000), contraste (WCAG) y duplicados. Es exacto, barato y se ejecuta primero.
2. **Motor de juicio.** Claude, con la API de Anthropic, evalúa copy, tono, cumplimiento normativo y logo. Debe pronunciarse regla por regla: cumple, incumple, no aplica o no determinable, cada una con evidencia y confianza.
3. **Cobertura.** Registra qué se verificó realmente en cada regla. Solo una regla verificada puede contar como cumplida.
4. **Veredicto.**

| Estado | Cuándo |
|---|---|
| Rechazado | Hay algún incumplimiento bloqueante con evidencia sólida, o la calidad es menor a 50 |
| Requiere revisión | Hay reglas que no se pudieron verificar y ningún motivo de rechazo |
| Sin evaluar | No se verificó ninguna regla |
| Aprobado con observaciones | Todas las reglas están verificadas y la calidad está entre 50 y 89 |
| Aprobado | Todas las reglas están verificadas y la calidad es 90 o más |

La **calidad** empieza en 100 y cada regla incumplida resta una sola vez, según su hallazgo más grave. Los bloqueantes no restan calidad: deciden si la pieza puede publicarse. En reglas bloqueantes, mayores y no anulables, una conclusión del modelo solo cuenta con confianza de 0,7 o más; por debajo, decide una persona.

## Stack

- PHP 8.3+, Laravel 13, Filament 5 (panel completo, con Livewire), MySQL 8.
- Laravel Sanctum para la API del plugin de Figma.
- spatie/laravel-permission (roles) y spatie/laravel-backup (respaldos).
- Cola `database` con worker, y el programador de tareas (`schedule:run`).
- No usa Inertia ni Vue: toda la interfaz es Filament.

## Instalación local

```bash
composer install
cp .env.example .env && php artisan key:generate
# Completar DB_* y ANTHROPIC_API_KEY en .env
php artisan migrate --seed          # roles; en local también carga datos de demostración
composer run dev                    # servidor, cola, logs y vite
php artisan schedule:work           # opcional: tareas programadas en local
```

Para los datos de demostración hay que definir `DEMO_ADMIN_EMAIL` y `DEMO_ADMIN_PASSWORD` (12 caracteres o más). En producción el seeder de demostración no corre.

## Comandos útiles

| Comando | Para qué |
|---|---|
| `php artisan validacion:diagnostico [id] --regla=COMP-002` | Detalle de una validación: veredicto, costo, hallazgos, pendientes y lo que declaró el modelo |
| `php artisan entorno:verificar` | Revisa el servidor y la configuración (debug, discos, IA, cola, tokens, idioma, logs) |
| `php artisan integridad:verificar [--todos] [--avisar]` | Compara cada archivo con su huella SHA-256 |
| `php artisan validaciones:cerrar-colgadas` | Cierra como fallidas las validaciones que no terminaron |
| `php artisan archivos:privatizar [--aplicar]` | Mueve archivos antiguos del disco público al privado |
| `php artisan respaldo:probar` | Restaura el último respaldo en una base temporal y lo verifica |

## Envío al director

Una pieza se envía al director desde su carga, con el botón **Enviar al director** de la fila. El director la aprueba o la devuelve desde **Operación → Director**. Todo queda en la bitácora.

- **Se puede enviar** si el veredicto de la última validación es aprobado o aprobado con observaciones. Si hubo revisión humana, cuenta el veredicto del revisor. Si el botón está deshabilitado, al pasar el cursor se ve el motivo.
- **No se puede enviar** si la pieza ya tiene un envío pendiente o ya fue aprobada, ni si la marca no tiene ningún director asignado.
- **Quién decide:** usuarios con el rol `director`, solo sobre las marcas de sus equipos. Nadie decide sobre una pieza que subió o envió.
- **Devolver** exige un comentario. Después se sube la versión corregida en la misma carga y se envía de nuevo.
- **Aprobar** queda bloqueado si, después del envío, se revalidó la pieza y el nuevo veredicto ya no la aprueba.
- **Avisos:** en el panel y por correo, al director cuando recibe una pieza y a quien envió cuando el director decide.

## Consumo de IA

En el panel: **Operación → Consumo de IA** (permiso `audit.view`; cada usuario ve solo sus clientes). Muestra, por cliente y periodo, las validaciones con IA, los tokens y el costo, con el desglose por marca y por modelo. Se puede exportar a CSV.

- **Tokens:** exactos. Son los que devuelve la API en cada respuesta.
- **Costo:** tokens × tarifa pública de `config/ai.php`. Es exacto si la cuenta no tiene descuentos; la fuente oficial es la factura de Anthropic.
- No incluye las ejecuciones simuladas. Si un modelo no tiene tarifa, sus tokens se cuentan y su costo figura como "sin tarifa", no como cero.

## API v1 (plugin de Figma)

Autenticación: `Authorization: Bearer <token>`. El token se obtiene con `POST /api/v1/login` o en *Mis tokens*. Vence a los 30 días y deja de funcionar si el usuario se desactiva.

| Método | Ruta | Qué hace |
|---|---|---|
| POST | `/api/v1/login` | Correo y contraseña; devuelve el token |
| GET | `/api/v1/yo` | Quién soy y si puedo validar |
| GET | `/api/v1/marcas` | Marcas visibles para el token |
| POST | `/api/v1/validaciones` | Valida una pieza (multipart: `image`, `brand`, `channel`, `external_ref`) |
| GET | `/api/v1/validaciones/{id}` | Consulta una validación sin volver a gastar |
| POST | `/api/v1/logout` | Revoca el token actual |

Campos clave de la respuesta de validación:

- `verdict`, `score`.
- `passed`: `true` solo si el veredicto es "aprobado".
- `can_send_to_director`: `true` si el veredicto efectivo (el de la revisión humana, si la hubo) es aprobado o aprobado con observaciones.
- `director`: estado del último envío al director (`pending`, `approved`, `returned`, `withdrawn`) con su comentario, o `null`.
- `rules.passed_codes`: solo reglas verificadas.
- `rules.failed_codes`.
- `rules.not_evaluated_codes` y `rules.not_evaluated_reasons`: qué no se pudo verificar y por qué.
- `audit.model`, `audit.cost_usd`, `audit.ai_stop_reason`.

El parámetro `model` solo lo respeta un `super_admin`. `channel` tiene que ser un canal registrado en `config/channels.php`.

## Documentación

- [docs/DESPLIEGUE.md](docs/DESPLIEGUE.md): puesta en producción en un VPS.
- [docs/RUNBOOK-respaldos.md](docs/RUNBOOK-respaldos.md): respaldos y restauración.
- [docs/AUDITORIA-2026-09.md](docs/AUDITORIA-2026-09.md): auditoría interna y checklist de remediación.
- [docs/NOTAS-NEGOCIO.md](docs/NOTAS-NEGOCIO.md): notas originales sobre el flujo y los conceptos del negocio.
