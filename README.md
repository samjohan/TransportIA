# TransportIA — Sistema de gestión de rutas y gastos

Tres piezas:

- **`backend-reference/`** — archivos Laravel de referencia (migraciones, modelos, controladores, rutas) para copiar a un proyecto Laravel nuevo, conectado a **PostgreSQL**.
- **`web-dashboard/`** — app React para **planificadores** y **contables** (escritorio, siempre en línea).
- **`driver-app/`** — PWA React para **conductores** (celular, funciona **sin internet**).

## Roles

| Rol | Acceso | Funciones |
|---|---|---|
| `planificador` | Web | Asigna rutas (origen, destino, conductor) y presupuesto |
| `contable` | Web | Ve detalle de gastos, gráficos, reportes de discrepancias OCR |
| `conductor` | Celular (PWA) | Ve sus rutas asignadas, registra gastos manual o con foto — funciona offline |

## Levantar todo con Docker Compose

```bash
docker compose up --build
```

Esto levanta cuatro contenedores:

| Servicio | URL | Qué hace |
|---|---|---|
| `db` | — | PostgreSQL 16 (no expuesto al host; solo `backend` lo usa) |
| `backend` | http://localhost:8080 | Crea un proyecto Laravel nuevo dentro de la imagen, le copia encima los archivos de `backend-reference/`, corre migraciones y siembra roles + usuarios demo |
| `web-dashboard` | http://localhost:5183 | Panel de planificador/contable (Vite dev server) |
| `driver-app` | http://localhost:5174 | PWA del conductor (Vite dev server) |

(Los puertos 8000/5173/5432 "por defecto" ya estaban ocupados por otros procesos en esta máquina, por eso el mapeo usa 8080/5183 — ver `docker-compose.yml`.)

Usuarios demo (contraseña `password` para los tres):

- `planificador@demo.com`
- `contable@demo.com`
- `conductor@demo.com`

El conductor demo no tiene rutas asignadas al arrancar — entra como `planificador@demo.com` en http://localhost:5173/planificador y asígnale una (usa el ID numérico del usuario conductor; puedes obtenerlo con `docker compose exec backend php artisan tinker` → `User::where('email','conductor@demo.com')->first()->id`).

Los datos de Postgres persisten en el volumen `db_data`; para arrancar desde cero: `docker compose down -v`.

## Backend — configuración inicial (sin Docker)

```bash
composer create-project laravel/laravel backend
cd backend
composer require laravel/sanctum spatie/laravel-permission

# .env — usar PostgreSQL
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=rutas_gastos
# DB_USERNAME=postgres
# DB_PASSWORD=...

php artisan install:api
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan storage:link
```

Copia los archivos de `backend-reference/` a las rutas equivalentes en tu proyecto Laravel (incluyendo `app/Models/User.php`, que ya trae el trait `HasRoles` de Spatie, y `bootstrap/app.php`, que registra los alias de middleware `role`/`permission` de Spatie — Laravel 11 ya no los registra solo; sin ellos cualquier ruta con `role:...` responde 500 "Target class [role] does not exist"), luego:

```bash
php artisan migrate
php artisan db:seed --class=RoleSeeder
```

Crea usuarios de prueba y asígnales rol con `$user->assignRole('conductor')` (o desde un seeder/tinker).

## Correcciones aplicadas sobre el borrador original

Este scaffold incorpora estas correcciones sobre la especificación inicial:

1. **`app/Models/User.php` añadido** con el trait `HasRoles` — sin esto, todo el control de acceso por rol (`hasRole`, `assignRole`, `getRoleNames`) lanza un error.
2. **`RutaController::store`** ya no llama a `$this->authorize('crear-rutas')` (no había ninguna Gate/Policy definida para esa habilidad, así que siempre fallaba). El control de acceso queda solo en el middleware `role:planificador` de la ruta.
3. **Recibos guardados en el disco `public`** (no `s3`), para que coincida con el enlace `/storage/{recibo_path}` que usa el panel del contable. Si más adelante mueves esto a S3, cambia el link del frontend por una URL firmada (`Storage::disk('s3')->temporaryUrl(...)`) en vez de `/storage/...`.
4. **`RutaController::store` valida que `conductor_id` tenga el rol `conductor`** antes de asignarle una ruta (antes solo se validaba que el usuario existiera, permitiendo asignar rutas a un planificador o contable por error).
5. **`bootstrap/app.php` añadido** registrando los middleware aliases `role`/`permission`/`role_or_permission` de Spatie. Laravel 11 eliminó `app/Http/Kernel.php`, así que estos alias ya no se registran solos al instalar el paquete — sin este archivo, **cualquier** ruta con `->middleware('role:...')` (asignar rutas, gastos, reportes, conductores) responde 500.
6. **`ConductorController` (CRUD de conductores) añadido** para la sección "Conductores" del planificador — el borrador original solo mencionaba esto como "próximo paso" (endpoint `/api/conductores` pendiente).

## Cómo funciona la lectura de recibos (QR primero, OCR como respaldo)

Tanto en el dispositivo como en el servidor, leer un recibo sigue el mismo orden: primero busca un **código QR** en la foto (muchas facturas electrónicas DIAN traen uno con el monto, IVA, número de factura y NIT ya codificados — mucho más confiable que leer texto impreso) y, solo si no hay QR o no trae nada reconocible, cae a **OCR** del texto impreso.

1. **En el dispositivo (offline)**: el conductor toma la foto → `driver-app/src/qr.js` (jsQR) busca un QR; si no hay, `driver-app/src/ocr.js` corre **Tesseract.js** en el navegador (WebAssembly, sin internet) → intenta leer monto, impuestos, número de factura y NIT → los prellena en el formulario → el conductor confirma o corrige.
2. **En el servidor (cuando hay conexión)**: al sincronizarse, un job (`ProcesarOcrRecibo`) vuelve a leer la imagen con el mismo QR-primero-luego-OCR (`ReconocedorRecibo` + `LectorQr`, vía `khanamiryan/qrcode-detector-decoder` y el binario `tesseract-ocr`) y compara el monto contra lo que confirmó el conductor. Si difieren, el gasto se marca `ocr_discrepancia = true` y aparece en el reporte del contable.

Esto evita bloquear al conductor en campo mientras igual le da al contable una segunda verificación.

## Web dashboard

```bash
cd web-dashboard
npm install
npm run dev
```

Rutas: `/login`, `/planificador` (asignar rutas), `/contable` (gráficos), `/contable/gastos` (tabla detalle).

## Driver app (PWA)

```bash
cd driver-app
npm install
npm run dev
```

Prueba el modo offline: DevTools → Network → "Offline", registra un gasto con foto, y verifica que quede guardado localmente (aparece "pendiente de sincronizar"). Al volver a "Online" se sincroniza solo.

Para instalar en Android: agrega íconos reales en `driver-app/public/` (`icon-192.png`, `icon-512.png`), despliega con HTTPS, y Chrome ofrecerá "Agregar a pantalla de inicio" automáticamente.

## Bot de Telegram (alternativa a la PWA para registrar gastos)

Un conductor que prefiera no instalar la app puede registrar gastos hablándole a un bot de Telegram — mismo backend, mismos datos, otra interfaz. Corre como un servicio aparte (`telegram-bot` en ambos `docker-compose*.yml`) que hace *long-polling* contra la API de Telegram (no un webhook — eso requeriría un endpoint HTTPS público, que este proyecto no siempre tiene, p. ej. en `sslip.io`).

**Configuración:**
1. Crea un bot con [@BotFather](https://t.me/BotFather) en Telegram y copia el token que te da (`123456:ABC-...`).
2. Local: copia `.env.example` a `.env` (ya está en `.gitignore`) y pon `TELEGRAM_BOT_TOKEN=` ahí. Dokploy: ponlo en la pestaña Environment de tu app — nunca lo subas al repo.
3. Si dejas `TELEGRAM_BOT_TOKEN` vacío, el servicio simplemente registra un error y termina — no rompe el resto del stack.

**Uso (desde Telegram, no requiere abrir el navegador):**
1. Busca tu bot y envía `/start` (o `/empezar`).
2. La primera vez, vincula tu cuenta: envía el número de celular con el que te registró tu planificador en `/planificador` → Conductores (ej: `3001234567`, con o sin `+57`).
3. Envía `/gasto` (o toca el botón "📝 Registrar gasto") para empezar a registrar uno — el bot pregunta primero la ruta (solo rutas `pendiente`/`en_curso`). Luego pide la foto del recibo (usa el ícono de cámara o clip 📎 de Telegram para tomarla o adjuntarla; el bot no puede abrir la cámara por ti), o toca "Sin foto"; si envías una, pregunta si quieres agregar una segunda (ej. el reverso) o toca "Listo, continuar". Lee ambas (QR primero si traen uno, si no OCR con Tesseract) y combina lo que encuentre en cada una; si detecta un monto lo ofrece como botón más adelante en vez de tener que escribirlo, y si detecta impuestos/factura/NIT los adjunta directo al gasto. Después pregunta categoría, monto (sugerido o escrito) y nota opcional, y confirma al terminar — mencionando lo detectado. `/cancelar` aborta el flujo en cualquier momento.

⚠️ La vinculación por número de celular no verifica que quien lo envía sea realmente el dueño de ese teléfono (cualquiera que conozca el número de otro conductor podría vincularlo a su propio Telegram) — igual de simple que el esquema anterior de correo+contraseña, pero sin dejar una contraseña visible en el historial del chat. Aceptable para una herramienta interna pequeña; si esto crece, vale la pena pedir confirmación por el botón nativo de Telegram "Compartir contacto" (`request_contact`), que sí verifica el número contra la cuenta de Telegram.

## Desplegar en Dokploy

Usa `docker-compose.dokploy.yml` (no el `docker-compose.yml` de desarrollo local) — apunta la app de Dokploy de tipo "Compose" a este archivo. Es autocontenido: incluye su propio servicio `db` (Postgres), igual que en local, para no depender de una base de datos administrada por Dokploy por separado.

**Configura estas variables en la pestaña Environment de Dokploy — nunca las pongas en el repo:**

| Variable | Requerida | Ejemplo |
|---|---|---|
| `APP_URL` | sí | `https://api.tudominio.com` |
| `DB_PASSWORD` | sí | la contraseña que quieras para Postgres — la usan tanto el servicio `db` como la conexión del backend |
| `DB_DATABASE` | no (default `rutas_gastos`) | `rutas_gastos` |
| `DB_USERNAME` | no (default `postgres`) | `postgres` |
| `DB_HOST` | no (default `db`, el servicio de este mismo compose) | solo cámbialo si prefieres apuntar a un Postgres externo (p. ej. `dev-transportia-transportiadb-t4poee`) en vez del `db` incluido aquí |
| `DB_PORT` | no (default `5432`) | `5432` |
| `TELEGRAM_BOT_TOKEN` | no (bot simply won't start without it) | token de @BotFather |

Si cambias `DB_HOST` para usar un Postgres externo, el servicio `db` de este archivo queda sin usar — puedes borrar ese bloque del compose si quieres evitar que consuma recursos innecesariamente.

Después de desplegar, asígnale un dominio a cada servicio desde la UI de Dokploy, apuntando al puerto de contenedor correspondiente: `backend` → 8000, `web-dashboard` → 5173, `driver-app` → 5174.

⚠️ Si en algún momento compartiste una contraseña real de base de datos en un chat, documento o commit, trátala como comprometida y rótala — no dependas de que "solo se vio una vez".

**Nota sobre `docker-entrypoint.sh`**: el backend corre el servidor embebido de PHP directamente (`php -S`) en vez de `php artisan serve` — el comando de Artisan reconstruye el entorno de su proceso hijo a partir del `.env` ya parseado por Laravel, no del entorno real del contenedor, así que ignoraba en silencio cualquier `DB_HOST`/`DB_PASSWORD` puesto vía `environment:` de Docker Compose o Dokploy a menos que esos mismos valores ya estuvieran horneados en `.env` al construir la imagen. Con la invocación directa, cualquier variable que pongas en Dokploy sí tiene efecto sin reconstruir la imagen.

## Próximos pasos sugeridos

- Agregar endpoint `/api/conductores` para el selector de conductores en "Asignar ruta" (actualmente pide el ID a mano)
- Exportar reportes a PDF/Excel para el contable
- Manejo de expiración/renovación de token para sesiones largas sin conexión
- Traducir mensajes de validación de Laravel a español (`lang/es/validation.php`)
- Añadir throttling a `/login` (`Auth::attempt` no tiene límite de intentos en este scaffold)
