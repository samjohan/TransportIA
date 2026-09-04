# App del conductor — TransportIA

Dos formas de que un conductor use el sistema, contra el mismo backend y los mismos datos:

1. **PWA** (`driver-app/`) — app web instalable en el celular, funciona **sin internet**.
2. **Bot de Telegram** — alternativa por chat para quien prefiera no instalar nada.

---

## 1. PWA del conductor (`driver-app/`)

### Stack

| Pieza | Tecnología |
|---|---|
| Framework | React 18 + Vite 5 |
| Estilos | Tailwind CSS |
| PWA / offline | `vite-plugin-pwa` (Workbox), `registerType: 'autoUpdate'` |
| Base de datos local | Dexie (IndexedDB) + `dexie-react-hooks` |
| HTTP | Axios, con interceptor de token Bearer |
| Lectura de recibos | `jsqr` (QR) + `tesseract.js` (OCR, WebAssembly, modelo español `spa`) |

Puerto dev: `5174`. En Docker Compose, hace proxy de `/api` y `/storage` hacia el backend (`VITE_API_PROXY_TARGET`).

### Estructura de archivos

```
driver-app/src/
├── App.jsx                  # shell: login gate, header, indicador online/offline
├── api.js                   # cliente axios, token bearer, logout forzado en 401
├── db.js                    # esquema Dexie (rutas, gastos, syncQueue)
├── sync.js                  # push/pull contra el backend
├── ocr.js                   # extracción de monto/IVA/factura/NIT por OCR
├── qr.js                    # lectura y parseo de QR (intenta primero)
├── format.js                # formato de moneda (COP)
├── hooks/useOnlineStatus.js # hook de navigator.onLine
├── components/MoneyInput.jsx
└── pages/
    ├── Login.jsx
    ├── InicioConductor.jsx  # lista de rutas asignadas + gastos por ruta
    └── RegistrarGasto.jsx   # formulario de registro de gasto
```

### Flujo de la app

1. **Login** (`Login.jsx`) — `POST /api/login` con correo/contraseña. Guarda `token` y `user` en `localStorage`. Un 401 con token presente fuerza logout + reload (token muerto/expirado); un 401 sin token (nadie logueado) se ignora.
2. **Mis rutas** (`InicioConductor.jsx`) — lista las rutas asignadas al conductor, cacheadas en Dexie (`db.rutas`) para verlas offline. Al montar (y al volver a la lista) intenta `fullSync()` si hay conexión. Badge de estado por ruta: `pendiente` / `en_curso` / `completada` / `cancelada`.
3. **Registrar gasto** (`RegistrarGasto.jsx`), dentro de una ruta seleccionada:
   - Foto del recibo (cámara o galería, `capture="environment"`). Se admite una **segunda foto opcional** (ej. el reverso) — cada una se procesa con QR-primero-luego-OCR y los campos se **fusionan**: lo que la primera foto ya encontró no se sobreescribe, la segunda solo llena lo que falte (ej. el frente trae el total, el reverso trae el NIT).
   - Campos autodetectados y editables: monto, impuestos (IVA), número de factura, NIT.
   - Categoría (`combustible`, `peaje`, `comida`, `hospedaje`, `mantenimiento`, `otro`), nota opcional.
   - Al guardar: se escribe primero en Dexie (`db.gastos`, `synced: 0`) y se encola en `syncQueue` — **local-first**, funciona con o sin conexión. Si hay conexión, intenta sincronizar de inmediato; si no, queda pendiente para la próxima sincronización.
   - Lista de gastos ya registrados en la ruta, con badge "Sincronizado" / "Pendiente".

### Lectura de recibos (QR primero, OCR como respaldo)

Corre 100% en el dispositivo, sin conexión:

1. `qr.js` dibuja la foto en un canvas offscreen y busca un código QR con `jsQR`. Muchas facturas electrónicas DIAN traen uno con monto, IVA, número de factura y NIT ya codificados — se parsea como query string (`valor`/`total`/`monto`, `iva`/`impuesto`, `factura`/`numfac`, `nit`).
2. Si no hay QR o no trae nada reconocible, `ocr.js` corre **Tesseract.js** (modelo `spa`) sobre la imagen y extrae por heurísticas de texto:
   - Monto: prioriza la línea que dice "TOTAL" (no "SUBTOTAL"); si no, el número más grande del texto.
   - Impuestos: línea que contiene "IVA".
   - Factura / NIT: token alfanumérico (≥5 caracteres, con al menos un dígito) en la línea que contiene "FACTURA" / "NIT".
3. Los archivos del modelo de Tesseract (worker/wasm/traineddata) están precacheados por Workbox (`vite.config.js`, `globPatterns` incluye `.wasm`/`.traineddata`, hasta 15 MB por archivo) para que el OCR siga funcionando sin internet después de la primera carga.

Al sincronizar, el backend vuelve a leer la imagen (mismo orden QR→OCR, con `khanamiryan/qrcode-detector-decoder` + `tesseract-ocr`) y compara el monto contra lo que confirmó el conductor; si difieren, el gasto queda marcado `ocr_discrepancia = true` para el reporte del contable.

### Sincronización (`sync.js`)

- `pushQueuedMutations()` — recorre `syncQueue` en orden (`created_at`), sube cada gasto como `multipart/form-data` (incluye `recibo` y, si existe, `recibo_2`) a `POST /api/gastos`. Se detiene en el primer error para no desordenar la cola si se corta la conexión a mitad de sincronización.
- `pullRutasAsignadas()` — trae `GET /api/rutas` y **reemplaza** el caché local completo (clear + bulkPut), para que rutas reasignadas/eliminadas no queden pegadas localmente para siempre.
- `fullSync()` = push luego pull. Se dispara al volver a la lista de rutas, al pulsar "↻ Actualizar", y de inmediato tras guardar un gasto si `navigator.onLine`.

### Modo offline / instalación

- Indicador visual en el header: verde "En línea" / ámbar "Sin conexión — los gastos se guardan localmente".
- Probar offline: DevTools → Network → "Offline", registrar un gasto con foto, verificar que quede "pendiente de sincronizar"; al volver a "Online" se sincroniza solo.
- Para instalar en Android: agregar íconos reales en `driver-app/public/` (`icon-192.png`, `icon-512.png`), desplegar con HTTPS — Chrome ofrece "Agregar a pantalla de inicio" automáticamente (`display: 'standalone'`, manifest en `vite.config.js`).
- `crypto.randomUUID()` requiere contexto seguro (HTTPS o localhost); como el despliegue puede quedar en un dominio HTTP plano (sslip.io de Dokploy, sin TLS), el UUID de cada gasto se genera con `crypto.getRandomValues()` en su lugar (`RegistrarGasto.jsx`).

### Endpoints del backend que usa

| Método | Ruta | Rol requerido | Uso |
|---|---|---|---|
| POST | `/api/login` | — | Autenticación |
| GET | `/api/rutas` | (autenticado) | Rutas asignadas al conductor |
| POST | `/api/gastos` | `conductor` | Registrar gasto (con `recibo` / `recibo_2` opcionales) |

---

## 2. Bot de Telegram (alternativa a la PWA)

Mismo backend, mismos datos, otra interfaz — para el conductor que prefiera no instalar la app. Corre como servicio Docker aparte (`telegram-bot` en `docker-compose*.yml`), por **long-polling** contra la API de Telegram (no webhook, ya que este proyecto no siempre tiene un endpoint HTTPS público).

### Piezas (Laravel, `backend-reference/`)

- `app/Console/Commands/TelegramPoll.php` — comando `php artisan telegram:poll`, el long-polling loop.
- `app/Services/TelegramBotService.php` — lógica de conversación.
- `app/Models/TelegramSession.php` — estado de la conversación en curso por chat.
- Migraciones: `telegram_chat_id` en `users`, tabla `telegram_sessions` (con campos de OCR, impuestos, factura, y una segunda foto de recibo).

### Configuración

1. Crear un bot con [@BotFather](https://t.me/BotFather) y copiar el token (`123456:ABC-...`).
2. Poner `TELEGRAM_BOT_TOKEN` en `.env` (local, gitignored) o en la pestaña Environment de Dokploy — nunca en el repo.
3. Sin token, el servicio registra un error y termina sin romper el resto del stack.

### Uso desde Telegram

1. `/start` (o `/empezar`).
2. Primera vez: vincular cuenta enviando el número de celular con el que el planificador registró al conductor (ej. `3001234567`, con o sin `+57`).
3. `/gasto` (o botón "📝 Registrar gasto"):
   - Pregunta la ruta (solo `pendiente` / `en_curso`).
   - Pide foto del recibo (o "Sin foto"); si se envía una, ofrece agregar una **segunda** (ej. reverso) o "Listo, continuar". Lee cada una QR-primero-luego-OCR y **combina** lo detectado entre ambas.
   - Si detectó un monto, lo ofrece como botón en vez de tener que escribirlo; impuestos/factura/NIT detectados se adjuntan directo al gasto.
   - Pregunta categoría, monto (sugerido o escrito), nota opcional, y confirma mencionando lo detectado.
   - `/cancelar` aborta el flujo en cualquier momento.

### Nota de seguridad (aceptada, documentada)

La vinculación por número de celular **no verifica** que quien lo envía sea el dueño real de ese teléfono — cualquiera que conozca el número de otro conductor podría vincularlo a su propio Telegram. Es equivalente en riesgo al esquema anterior de correo+contraseña, pero sin dejar una contraseña visible en el historial del chat. Aceptable para una herramienta interna pequeña; si el equipo crece, vale la pena migrar a confirmación por el botón nativo de Telegram "Compartir contacto" (`request_contact`), que sí valida el número contra la cuenta.

---

## Despliegue

- **Docker Compose (local)**: `driver-app` → `http://localhost:5174` (Vite dev server). `telegram-bot` depende de que `backend` esté *healthy* (no solo `db`) para no correr migraciones dos veces a la vez.
- **Dokploy**: usa `docker-compose.dokploy.yml`. Asignar dominio al servicio `driver-app` apuntando al puerto de contenedor `5174`. `TELEGRAM_BOT_TOKEN` es opcional (el bot simplemente no arranca sin él).
