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

## Cómo funciona el OCR de recibos (dos pasadas)

1. **En el dispositivo (offline)**: el conductor toma la foto → **Tesseract.js** corre en el navegador (WebAssembly, sin internet) → intenta leer el monto → lo prellena en el formulario → el conductor confirma o corrige.
2. **En el servidor (cuando hay conexión)**: al sincronizarse, un job (`ProcesarOcrRecibo`) vuelve a leer la imagen con un servicio en la nube (Google Vision / AWS Textract — hay que conectar la API que prefieras) y compara contra lo que confirmó el conductor. Si difieren, el gasto se marca `ocr_discrepancia = true` y aparece en el reporte del contable.

Esto evita bloquear al conductor en campo mientras igual le da al contable una segunda verificación más precisa.

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

## Próximos pasos sugeridos

- Conectar un proveedor real de OCR en la nube dentro de `ProcesarOcrRecibo`
- Agregar endpoint `/api/conductores` para el selector de conductores en "Asignar ruta" (actualmente pide el ID a mano)
- Exportar reportes a PDF/Excel para el contable
- Manejo de expiración/renovación de token para sesiones largas sin conexión
- Traducir mensajes de validación de Laravel a español (`lang/es/validation.php`)
- Añadir throttling a `/login` (`Auth::attempt` no tiene límite de intentos en este scaffold)
