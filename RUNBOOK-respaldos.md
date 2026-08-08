# Validador de Piezas Gráficas
## Respaldos: instalación, verificación y recuperación

Preparado para: Kalin
Fecha: 8 de agosto de 2026
Uso: procedimiento operativo, no documentación de referencia

---

## Resumen

Tres comandos programados: respaldo diario a las 02:00, prueba de restauración los domingos, verificación de huellas de archivo los domingos. Más un monitor que avisa si el respaldo dejó de correr.

Lo que hace esto distinto de configurar `backup:run` y olvidarse: **`respaldo:probar` restaura el zip en una base desechable cada semana y compara los conteos con producción**. Un respaldo que nunca se restauró no es un respaldo; es una carpeta con archivos.

Tiempo de instalación: unos 40 minutos, la mayoría esperando a que se creen las credenciales del destino externo.

---

# Parte 0. Instalar ahora, activar después

**Todo esto viene apagado.** `BACKUP_ENABLED=false` es el valor por defecto, y con eso el planificador no encola nada: los comandos existen y se pueden correr a mano, pero no hay automatización.

Está hecho así a propósito. Un respaldo que intenta subir cada noche a un destino sin credenciales llena el log de errores, y un log lleno de ruido es un log que nadie mira.

## Lo que sí conviene hacer hoy, sin credenciales

```bash
composer require spatie/laravel-backup league/flysystem-aws-s3-v3
```

Copiar los archivos y dejarlos versionados. Con eso el día del despliegue no hay que decidir nada, solo encender.

Y esto ya funciona sin ningún destino externo:

```bash
php artisan integridad:verificar
```

Verifica que cada pieza en disco siga siendo la que se validó. No necesita credenciales, no sube nada, y tiene valor por sí solo: detecta que un archivo dejó de coincidir con su huella.

## Lo que se activa el día del despliegue

Cuatro pasos, en este orden:

```bash
# 1. Encender
BACKUP_ENABLED=true          # en el .env
INTEGRIDAD_PROGRAMADA=true

# 2. Credenciales del destino (Parte 1.2 y 1.3)

# 3. Limpiar la config cacheada
php artisan config:clear

# 4. La linea de cron
crontab -e
# * * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

Y comprobar:

```bash
php artisan schedule:list        # deben aparecer las cinco tareas
php artisan backup:run           # el primero, a mano
php artisan respaldo:probar      # la prueba que importa
```

## Sobre dónde alojarlo

Una cosa que quita presión a la decisión: **el destino del respaldo no tiene que estar en el mismo proveedor que el servidor**. Puedes tener la aplicación en un hosting compartido, en un VPS de Hetzner o en AWS, y los respaldos en Backblaze B2. Son decisiones independientes.

Sobre el servidor en sí, y con el volumen de una agencia mediana en Lima:

| Opción | Costo mensual | Comentario |
|---|---|---|
| **VPS (Hetzner, DigitalOcean)** | US$ 20 – 40 | Suficiente y de sobra. 2 vCPU, 4 GB |
| AWS / Azure / Google Cloud | US$ 60 – 150+ | Sobredimensionado a este volumen, y la factura es difícil de predecir |
| Hosting compartido | US$ 5 – 15 | **No sirve**: necesitas PHP 8.3, procesos en segundo plano y peticiones de 20 segundos |

Los hyperscalers tienen sentido cuando necesitas escalar a demanda o cumplir requisitos de un corporativo que exige un proveedor específico. Para una instalación por cliente con una pieza a la vez, un VPS modesto rinde mejor y cuesta un tercio.

Si un cliente corporativo exige AWS, se hace y se cobra la diferencia — pero no es la decisión que tomes tú por adelantado.

---

# Parte 1. Instalación

## 1.1 El paquete

```bash
composer require spatie/laravel-backup
```

Versión 10.3.1, compatible con Laravel 13 y PHP 8.3. Verificado contra Packagist el 8 de agosto de 2026.

**No publiques el config del paquete.** El zip trae uno ya ajustado a este proyecto: incluye solo `storage/app/private` y `storage/app/public` en vez de todo el `base_path()`, porque el código está en git y se recupera con un clone. Lo que no se recupera de ningún otro lado son las piezas que subieron los clientes.

## 1.2 El destino externo

Un respaldo en el mismo disco que la base no es un respaldo: se pierde con el mismo incidente. Necesitas un destino fuera del servidor.

Agrega a `config/filesystems.php`, dentro de `'disks'`:

```php
'respaldos' => [
    'driver' => 's3',
    'key' => env('BACKUP_S3_KEY'),
    'secret' => env('BACKUP_S3_SECRET'),
    'region' => env('BACKUP_S3_REGION'),
    'bucket' => env('BACKUP_S3_BUCKET'),
    'endpoint' => env('BACKUP_S3_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'throw' => true,
],
```

Y el driver de S3:

```bash
composer require league/flysystem-aws-s3-v3
```

**Qué proveedor.** Cualquiera compatible con S3 sirve. Para el volumen que vas a manejar:

| Proveedor | Costo aproximado | Nota |
|---|---|---|
| **Backblaze B2** | US$ 6 por TB al mes | El más barato. Endpoint tipo `s3.us-west-004.backblazeb2.com` |
| DigitalOcean Spaces | US$ 5 al mes, 250 GB incluidos | Si ya tienes el VPS ahí, es un clic |
| AWS S3 | ~US$ 23 por TB al mes | Más caro y más complejo de configurar |

Con un cliente activo vas a estar bajo los 10 GB. Son centavos.

**Importante sobre las credenciales:** crea una clave de aplicación con permiso **solo de escritura y lectura sobre ese bucket**. Si el servidor se compromete, que no pueda borrar los respaldos históricos. En B2 se llama "Application Key" con alcance a un bucket; en Spaces, una key limitada.

## 1.3 El `.env`

El `.env.example` del zip trae el bloque completo documentado. Lo mínimo:

```
BACKUP_DISK=respaldos
BACKUP_ARCHIVE_PASSWORD=<una frase larga>
BACKUP_S3_KEY=...
BACKUP_S3_SECRET=...
BACKUP_S3_REGION=...
BACKUP_S3_BUCKET=...
BACKUP_S3_ENDPOINT=...
BACKUP_NOTIFICATION_EMAIL=tu@correo
```

**`BACKUP_ARCHIVE_PASSWORD` cifra el zip.** Los respaldos contienen piezas sin publicar y manuales de marca de tus clientes: viajando a un bucket externo, sin cifrar, es una fuga esperando pasar.

Y guarda esa clave en un gestor de contraseñas, no solo en el `.env`. **Sin ella el respaldo es irrecuperable** — si pierdes el servidor y la clave a la vez, tienes archivos que nadie puede abrir.

## 1.4 El planificador

**Requiere `BACKUP_ENABLED=true`.** Con el interruptor en false, las tareas ni siquiera se registran y `schedule:list` no las muestra.

Los comandos programados no corren solos. Una línea en el crontab del usuario que sirve la aplicación:

```bash
crontab -e
```

```
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

Verifica que quedó registrado:

```bash
php artisan schedule:list
```

Deberías ver `backup:clean`, `backup:run`, `respaldo:probar`, `integridad:verificar` y `backup:monitor`.

**Sin esa línea de cron no hay respaldos y nada lo advierte.** Es el error más común, y se descubre el día del incidente.

## 1.5 Notificaciones

`backup:monitor` avisa si el respaldo más reciente es viejo. Para que ese aviso llegue, el correo tiene que funcionar:

```bash
php artisan tinker --execute="
Illuminate\Support\Facades\Mail::raw('Prueba de correo del validador', function (\$m) {
    \$m->to(config('backup.notifications.mail.to'))->subject('Prueba');
});
echo 'enviado'.PHP_EOL;
"
```

Si no llega, configura el `MAIL_*` antes de seguir. Un monitor que no puede avisar no monitorea.

---

# Parte 2. Verificación

## 2.1 El primer respaldo, a mano

```bash
php artisan backup:run
```

Tarda entre unos segundos y varios minutos según cuántas piezas tengas. Al terminar:

```bash
php artisan backup:list
```

Debe aparecer una fila por disco: `local` y `respaldos`. Si `respaldos` sale vacío o con error, las credenciales están mal — y es mejor saberlo ahora.

## 2.2 La prueba que importa

```bash
php artisan respaldo:probar
```

Esto es lo que separa un respaldo real de la ilusión de tener uno. El comando:

1. Toma el zip más reciente del disco local
2. Extrae el `.sql` (descifrándolo si hace falta)
3. Crea una base temporal `restauracion_prueba_<pid>`
4. Carga el dump ahí
5. Cuenta las doce tablas críticas y las compara con producción
6. Borra la base temporal

Salida esperada:

```
tabla                    respaldo   produccion   estado
--------------------------------------------------------------
clients                         2            2   ok
brands                          8            8   ok
rule_sets                      14           14   ok
rules                          31           31   ok
...
Restauracion verificada. El respaldo sirve.
```

**Nunca toca la base de producción.** Solo lee para comparar.

Si algo sale mal, el mensaje dice qué: zip corrupto, sin `.sql` dentro, clave de cifrado equivocada, o tablas vacías.

Para inspeccionar la base restaurada en vez de borrarla:

```bash
php artisan respaldo:probar --conservar
```

## 2.3 Integridad de los archivos

```bash
php artisan integridad:verificar
```

Recalcula el SHA-256 de cada pieza y cada activo de marca, y lo compara con el registrado al subirlos. Un respaldo que copia archivos corruptos guarda la corrupción.

Además tiene valor propio: si una pieza cambió después de haberse validado, el veredicto del historial dejó de referirse a lo que hay en disco. Eso es justo lo que este sistema promete que no pasa.

---

# Parte 3. Recuperación

Esta es la parte que se lee bajo presión. Está escrita para eso.

## 3.1 Se perdió la base, el servidor está bien

```bash
# 1. Poner la aplicación en mantenimiento
php artisan down

# 2. Descargar el respaldo más reciente del destino externo
php artisan backup:list          # ver qué hay y de cuándo

# 3. Extraer el zip (pedirá la clave si está cifrado)
unzip -P "$BACKUP_ARCHIVE_PASSWORD" respaldo.zip -d /tmp/recuperacion

# 4. Cargar el dump
mysql -u root -p validador_piezas < /tmp/recuperacion/db-dumps/mysql-validador_piezas.sql

# 5. Comprobar antes de abrir
php artisan tinker --execute="
echo 'validaciones: '.App\Models\ValidationRun::count().PHP_EOL;
echo 'hallazgos:    '.App\Models\Finding::count().PHP_EOL;
echo 'ultima:       '.App\Models\ValidationRun::latest('id')->first()?->created_at.PHP_EOL;
"

# 6. Verificar que los archivos siguen ahí
php artisan integridad:verificar

# 7. Levantar
php artisan up
```

## 3.2 Se perdió el servidor completo

```bash
# 1. Servidor nuevo con PHP 8.3, MySQL, Composer
git clone <tu-repo> validador-piezas && cd validador-piezas
composer install --no-dev --optimize-autoloader

# 2. Recuperar el .env desde el gestor de contraseñas
#    (nunca está en el respaldo ni en git, a propósito)

# 3. Base vacía y estructura
mysql -u root -p -e "CREATE DATABASE validador_piezas CHARACTER SET utf8mb4"

# 4. Descargar el respaldo del bucket con el cliente del proveedor
#    y extraerlo

# 5. Cargar el dump (trae estructura y datos: no corras migrate antes)
mysql -u root -p validador_piezas < mysql-validador_piezas.sql

# 6. Restaurar los archivos
cp -R recuperacion/storage/app/private storage/app/
cp -R recuperacion/storage/app/public storage/app/
php artisan storage:link

# 7. Permisos
chown -R www-data:www-data storage bootstrap/cache

# 8. Verificar
php artisan integridad:verificar
php artisan test

# 9. Volver a poner el cron del planificador
```

**El paso 2 es el que suele detener todo.** El `.env` no está en el respaldo a propósito —contiene la clave de Anthropic y las credenciales del bucket—, así que tiene que estar en tu gestor de contraseñas. Si no lo está, ponlo ahí hoy.

## 3.3 Un cliente pide restaurar algo puntual

No restaures todo. Usa `--conservar` para tener la base vieja al lado:

```bash
php artisan respaldo:probar --archivo=/ruta/al/respaldo-del-dia.zip --conservar
```

Después consultas la base temporal directamente y copias solo lo que hace falta. Al terminar:

```sql
DROP DATABASE `restauracion_prueba_XXXX`;
```

---

# Parte 4. Qué revisar cada mes

| Comprobación | Cómo |
|---|---|
| El respaldo corrió | `php artisan backup:list` — la fecha del más reciente |
| El destino externo recibe | La misma lista, columna del disco `respaldos` |
| La restauración funciona | `php artisan respaldo:probar` |
| Los archivos están intactos | `php artisan integridad:verificar` |
| El planificador vive | `php artisan schedule:list` y revisar el crontab |
| El correo llega | Que hayan aparecido los avisos de `backup:monitor` |
| La clave de cifrado está guardada | Buscarla en el gestor de contraseñas y abrir un zip con ella |

Esa última fila parece redundante y no lo es. Es el fallo más caro posible: tener años de respaldos y ninguna forma de abrirlos.

---

# Parte 5. Lo que este respaldo NO cubre

Vale ser explícito, porque un respaldo con huecos no declarados es peor que uno pequeño y conocido.

**El `.env`.** A propósito: contiene la clave de Anthropic y las credenciales del bucket. Va en tu gestor de contraseñas.

**El código.** Está en git. Si tu único remoto es GitHub y GitHub desaparece, tienes un problema distinto. Un clon local en otra máquina lo resuelve.

**Los respaldos anteriores del bucket.** Si alguien con las credenciales del servidor borra el bucket, se van todos. Por eso la clave de aplicación debe ser de solo escritura, y por eso vale activar el versionado de objetos si el proveedor lo ofrece.

**El tiempo entre respaldos.** Con uno diario a las 02:00, un fallo a las 23:00 pierde 21 horas de validaciones. Si eso llega a ser inaceptable para un cliente, la respuesta es respaldos cada seis horas, no más frecuentes: la base es pequeña y los archivos son inmutables.

---

## Una nota sobre el orden

Instala el paquete y configura el destino hoy, aunque no tengas cliente todavía. El respaldo que sirve es el que ya estaba corriendo cuando pasó el problema, y el momento en que uno se acuerda de configurarlo es siempre el momento equivocado.

Los cuarenta minutos que cuesta esto son la diferencia entre "perdimos dos días de trabajo" y "estuvimos abajo veinte minutos".
