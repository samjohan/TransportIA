<?php

namespace App\Services;

use App\Jobs\ProcesarOcrRecibo;
use App\Models\Gasto;
use App\Models\Ruta;
use App\Models\TelegramSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Update;

// Lets a conductor register gastos entirely from Telegram, as an
// alternative to the driver PWA for drivers who'd rather not install an
// app. One conversation flow per chat; state is tracked in
// TelegramSession since long-polling updates arrive one at a time with no
// memory of previous messages otherwise.
//
// The flow leads with the receipt photo (or "Sin foto") rather than
// ending with it: OCR-ing it right away (via ReconocedorRecibo) means the
// monto step later on can offer the detected amount as a one-tap button
// instead of making the driver type it, the same shortcut the PWA gives
// via on-device OCR.
//
// Ruta/categoría/nota/monto-confirm choices are inline keyboards (buttons
// attached to the bot's own message, answered via callback_query) rather
// than a persistent reply keyboard: tapping one doesn't leave a stray text
// message in the chat, and the message is edited afterwards to show what
// was picked and drop the buttons, so old ones can't be tapped again.
class TelegramBotService
{
    private const CATEGORIAS = ['Combustible', 'Peaje', 'Comida', 'Hospedaje', 'Mantenimiento', 'Otro'];

    // Which session state a given callback_data prefix is only valid in —
    // guards against a stray tap on an inline keyboard left over from an
    // abandoned or already-finished flow.
    private const ESTADO_POR_CALLBACK = [
        'foto' => 'esperando_foto',
        'ruta' => 'esperando_ruta',
        'cat' => 'esperando_categoria',
        'monto' => 'esperando_monto',
        'nota' => 'esperando_nota',
    ];

    // Every gasto-in-progress field, blanked out — reused everywhere a
    // session starts or finishes a flow, so a new OCR field only needs to
    // be added here instead of at every reset site.
    private const CAMPOS_GASTO_VACIOS = [
        'ruta_uuid' => null, 'categoria' => null, 'monto' => null, 'nota' => null,
        'recibo_path' => null, 'monto_ocr' => null, 'impuestos_ocr' => null,
        'factura_numero_ocr' => null, 'nit_ocr' => null,
    ];

    public function __construct(
        private Api $telegram,
        private string $token,
        private ReconocedorRecibo $reconocedor,
    ) {}

    public function handle(Update $update): void
    {
        if ($callback = $update->getCallbackQuery()) {
            $this->handleCallback($callback);
            return;
        }

        $message = $update->getMessage();
        if (! $message) {
            return; // ignore non-message updates (edits, reactions, etc.)
        }

        $chatId = (string) $message->getChat()->getId();
        $text = trim((string) $message->getText());
        // getPhoto() returns a Collection when present but (depending on
        // SDK version) either null or an empty Collection when absent —
        // normalize to always-a-Collection so downstream code has one
        // shape to check against.
        $photos = collect($message->getPhoto());

        $conductor = User::where('telegram_chat_id', $chatId)->first();

        if (! $conductor) {
            $this->vincularCuenta($chatId, $text);
            return;
        }

        if (in_array($text, ['/start', '/empezar', '/gasto', '/nuevo'], true)) {
            $this->iniciarGasto($chatId, $conductor);
            return;
        }

        if ($text === '/cancelar') {
            $this->reiniciar($chatId, 'Registro cancelado.');
            return;
        }

        $session = TelegramSession::firstOrCreate(['chat_id' => $chatId]);

        match ($session->estado) {
            'esperando_foto' => $this->recibirFotoInicial($chatId, $conductor, $session, $photos),
            'esperando_ruta' => $this->enviar($chatId, 'Toca uno de los botones de arriba para elegir la ruta.'),
            'esperando_categoria' => $this->enviar($chatId, 'Toca uno de los botones de arriba para elegir la categoría.'),
            'esperando_monto' => $this->recibirMonto($chatId, $session, $text),
            'esperando_nota' => $this->recibirNota($chatId, $conductor, $session, $text),
            default => $this->enviarConBotonGasto($chatId, 'Toca el botón para registrar un gasto.'),
        };
    }

    private function handleCallback(CallbackQuery $callback): void
    {
        $this->telegram->answerCallbackQuery(['callback_query_id' => $callback->getId()]);

        $chatId = (string) $callback->getMessage()->getChat()->getId();
        $messageId = $callback->getMessage()->getMessageId();

        $conductor = User::where('telegram_chat_id', $chatId)->first();
        if (! $conductor) {
            return;
        }

        [$tipo, $valor] = array_pad(explode(':', $callback->getData(), 2), 2, '');

        // Unlike the other callback types, "start a gasto" is valid from
        // any session state — same as the /gasto text command.
        if ($tipo === 'gasto') {
            $this->marcarSeleccion($chatId, $messageId, '📝 Registrar gasto');
            $this->iniciarGasto($chatId, $conductor);
            return;
        }

        $session = TelegramSession::firstOrCreate(['chat_id' => $chatId]);

        $estadoEsperado = self::ESTADO_POR_CALLBACK[$tipo] ?? null;
        if ($estadoEsperado === null || $session->estado !== $estadoEsperado) {
            $this->marcarSeleccion($chatId, $messageId, 'Ese botón ya no es válido.');
            $this->enviarConBotonGasto($chatId, 'Toca el botón para empezar de nuevo.');
            return;
        }

        match ($tipo) {
            'foto' => $this->omitirFotoInicial($chatId, $messageId, $conductor, $session),
            'ruta' => $this->seleccionarRuta($chatId, $messageId, $conductor, $session, $valor),
            'cat' => $this->seleccionarCategoria($chatId, $messageId, $session, $valor),
            'monto' => $this->confirmarMontoOcr($chatId, $messageId, $session),
            'nota' => $this->omitirNota($chatId, $messageId, $conductor, $session),
        };
    }

    private function vincularCuenta(string $chatId, string $text): void
    {
        $telefonoRecibido = preg_replace('/\D/', '', $text);

        // Anything without at least a plausible number of digits isn't a
        // phone number attempt yet (e.g. the driver just sent /start) —
        // show instructions instead of a bogus "not found".
        if (strlen($telefonoRecibido) < 7) {
            $this->enviar($chatId, "👋 Hola, soy el bot de TransportIA.\n\nPara vincular tu cuenta de conductor, envía el número de celular con el que te registraron en el planificador (ej: 3001234567).");
            return;
        }

        $conductor = User::role('conductor')
            ->get(['id', 'name', 'telefono'])
            ->first(fn ($u) => $u->telefono && $this->mismoTelefono($u->telefono, $telefonoRecibido));

        if (! $conductor) {
            $this->enviar($chatId, '❌ No encontré ese número entre los conductores registrados. Verifica con tu planificador y vuelve a intentar.');
            return;
        }

        $conductor->update(['telegram_chat_id' => $chatId]);
        $this->enviarConBotonGasto($chatId, "✅ Cuenta vinculada, {$conductor->name}.");
    }

    // Compares only the last 10 digits so it doesn't matter whether either
    // side includes the +57 country code or how the planificador formatted
    // it (spaces, dashes, etc. are already stripped by the caller).
    private function mismoTelefono(string $registrado, string $recibido): bool
    {
        $registrado = preg_replace('/\D/', '', $registrado);

        return $registrado !== '' && substr($registrado, -10) === substr($recibido, -10);
    }

    private function iniciarGasto(string $chatId, User $conductor): void
    {
        $tieneRutas = Ruta::where('conductor_id', $conductor->id)
            ->whereIn('estado', ['pendiente', 'en_curso'])
            ->exists();

        if (! $tieneRutas) {
            $this->enviar($chatId, 'No tienes rutas activas en este momento.');
            return;
        }

        TelegramSession::updateOrCreate(
            ['chat_id' => $chatId],
            ['estado' => 'esperando_foto'] + self::CAMPOS_GASTO_VACIOS
        );

        $this->enviarInline($chatId, 'Envía una foto del recibo, o toca "Sin foto" para continuar sin foto.', [
            [['text' => 'Sin foto', 'callback_data' => 'foto:skip']],
        ]);
    }

    private function recibirFotoInicial(string $chatId, User $conductor, TelegramSession $session, Collection $photos): void
    {
        if ($photos->isEmpty()) {
            $this->enviar($chatId, 'Envía una foto o toca "Sin foto".');
            return;
        }

        // Telegram sends the same photo at several resolutions; the last
        // entry is the largest.
        $reciboPath = $this->descargarFoto($photos->last()->getFileId());
        $leido = $this->reconocedor->reconocer(Storage::disk('public')->path($reciboPath));

        $session->update([
            'recibo_path' => $reciboPath,
            'monto_ocr' => $leido['monto'],
            'impuestos_ocr' => $leido['impuestos'],
            'factura_numero_ocr' => $leido['factura_numero'],
            'nit_ocr' => $leido['nit'],
        ]);
        $this->pedirRuta($chatId, $conductor, $session);
    }

    private function omitirFotoInicial(string $chatId, int $messageId, User $conductor, TelegramSession $session): void
    {
        $this->marcarSeleccion($chatId, $messageId, 'Sin foto');
        $session->update([
            'recibo_path' => null, 'monto_ocr' => null, 'impuestos_ocr' => null,
            'factura_numero_ocr' => null, 'nit_ocr' => null,
        ]);
        $this->pedirRuta($chatId, $conductor, $session);
    }

    private function pedirRuta(string $chatId, User $conductor, TelegramSession $session): void
    {
        $rutas = Ruta::where('conductor_id', $conductor->id)
            ->whereIn('estado', ['pendiente', 'en_curso'])
            ->latest()
            ->get();

        if ($rutas->isEmpty()) {
            $this->enviar($chatId, 'No tienes rutas activas en este momento.');
            $session->update(['estado' => 'inicio'] + self::CAMPOS_GASTO_VACIOS);
            return;
        }

        $session->update(['estado' => 'esperando_ruta']);

        $filas = $rutas->map(fn ($r) => [[
            'text' => "{$r->origen} → {$r->destino}",
            'callback_data' => "ruta:{$r->uuid}",
        ]])->toArray();
        $this->enviarInline($chatId, '¿Para cuál ruta es el gasto?', $filas);
    }

    private function seleccionarRuta(string $chatId, int $messageId, User $conductor, TelegramSession $session, string $rutaUuid): void
    {
        $ruta = Ruta::where('conductor_id', $conductor->id)->where('uuid', $rutaUuid)->first();

        if (! $ruta) {
            $this->marcarSeleccion($chatId, $messageId, 'Esa ruta ya no está disponible.');
            $this->enviarConBotonGasto($chatId, 'Toca el botón para empezar de nuevo.');
            return;
        }

        $this->marcarSeleccion($chatId, $messageId, "Ruta: {$ruta->origen} → {$ruta->destino}");
        $session->update(['estado' => 'esperando_categoria', 'ruta_uuid' => $ruta->uuid]);

        $filas = collect(self::CATEGORIAS)
            ->map(fn ($c) => [['text' => $c, 'callback_data' => 'cat:'.strtolower($c)]])
            ->toArray();
        $this->enviarInline($chatId, '¿Categoría del gasto?', $filas);
    }

    private function seleccionarCategoria(string $chatId, int $messageId, TelegramSession $session, string $categoria): void
    {
        $indice = array_search($categoria, array_map('strtolower', self::CATEGORIAS), true);
        if ($indice === false) {
            return;
        }

        $this->marcarSeleccion($chatId, $messageId, 'Categoría: '.self::CATEGORIAS[$indice]);
        $session->update(['estado' => 'esperando_monto', 'categoria' => $categoria]);
        $this->pedirMonto($chatId, $session);
    }

    private function pedirMonto(string $chatId, TelegramSession $session): void
    {
        if ($session->monto_ocr) {
            $montoFormateado = number_format((float) $session->monto_ocr, 0, ',', '.');
            $this->enviarInline(
                $chatId,
                "Detecté \${$montoFormateado} en la foto del recibo. Toca el botón si es correcto, o escribe el monto real (ej: 50000).",
                [[['text' => "Usar \${$montoFormateado}", 'callback_data' => 'monto:ocr']]]
            );
            return;
        }

        $this->enviar($chatId, '¿Cuál fue el monto? Envía solo el número, en pesos colombianos (ej: 50000).');
    }

    private function confirmarMontoOcr(string $chatId, int $messageId, TelegramSession $session): void
    {
        $montoFormateado = number_format((float) $session->monto_ocr, 0, ',', '.');
        $this->marcarSeleccion($chatId, $messageId, "Monto: \${$montoFormateado}");
        $session->update(['estado' => 'esperando_nota', 'monto' => $session->monto_ocr]);
        $this->enviarInline($chatId, '¿Alguna nota? Escríbela, o toca "Sin nota".', [
            [['text' => 'Sin nota', 'callback_data' => 'nota:skip']],
        ]);
    }

    private function recibirMonto(string $chatId, TelegramSession $session, string $text): void
    {
        $monto = (float) preg_replace('/\D/', '', $text);
        if ($monto <= 0) {
            $this->enviar($chatId, 'Envía un monto válido, solo números (ej: 50000).');
            return;
        }

        $session->update(['estado' => 'esperando_nota', 'monto' => $monto]);
        $this->enviarInline($chatId, '¿Alguna nota? Escríbela, o toca "Sin nota".', [
            [['text' => 'Sin nota', 'callback_data' => 'nota:skip']],
        ]);
    }

    private function recibirNota(string $chatId, User $conductor, TelegramSession $session, string $text): void
    {
        $session->update(['nota' => $text]);
        $this->finalizarGasto($chatId, $conductor, $session);
    }

    private function omitirNota(string $chatId, int $messageId, User $conductor, TelegramSession $session): void
    {
        $this->marcarSeleccion($chatId, $messageId, 'Sin nota');
        $session->update(['nota' => null]);
        $this->finalizarGasto($chatId, $conductor, $session);
    }

    private function finalizarGasto(string $chatId, User $conductor, TelegramSession $session): void
    {
        $gasto = Gasto::create([
            'uuid' => (string) Str::uuid(),
            'ruta_uuid' => $session->ruta_uuid,
            'conductor_id' => $conductor->id,
            'monto' => $session->monto,
            'impuestos' => $session->impuestos_ocr,
            'categoria' => $session->categoria,
            'nota' => $session->nota,
            'factura_numero' => $session->factura_numero_ocr,
            'nit' => $session->nit_ocr,
            'recibo_path' => $session->recibo_path,
            'monto_ocr' => $session->monto_ocr,
            'creado_offline_en' => now(),
        ]);

        if ($session->recibo_path) {
            ProcesarOcrRecibo::dispatch($gasto);
        }

        $ruta = Ruta::find($session->ruta_uuid);
        $montoFormateado = number_format((float) $session->monto, 0, ',', '.');
        $mensaje = "✅ Gasto registrado: \${$montoFormateado} en {$session->categoria} para {$ruta->origen} → {$ruta->destino}.";
        if ($session->impuestos_ocr) {
            $mensaje .= ' Impuestos: $'.number_format((float) $session->impuestos_ocr, 0, ',', '.').'.';
        }
        if ($session->factura_numero_ocr) {
            $mensaje .= " Factura: {$session->factura_numero_ocr}.";
        }
        if ($session->nit_ocr) {
            $mensaje .= " NIT: {$session->nit_ocr}.";
        }
        $this->enviarConBotonGasto($chatId, $mensaje);

        $session->update(['estado' => 'inicio'] + self::CAMPOS_GASTO_VACIOS);
    }

    private function descargarFoto(string $fileId): string
    {
        $file = $this->telegram->getFile(['file_id' => $fileId]);
        $contenido = Http::get("https://api.telegram.org/file/bot{$this->token}/{$file->getFilePath()}")->body();

        $path = 'recibos/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, $contenido);

        return $path;
    }

    private function reiniciar(string $chatId, string $mensaje): void
    {
        TelegramSession::updateOrCreate(
            ['chat_id' => $chatId],
            ['estado' => 'inicio'] + self::CAMPOS_GASTO_VACIOS
        );
        $this->enviar($chatId, $mensaje);
    }

    // Edits the bot's own question message in place once it's answered —
    // shows what was picked and drops the inline keyboard so the buttons
    // can't be tapped again.
    private function marcarSeleccion(string $chatId, int $messageId, string $texto): void
    {
        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $texto,
            'reply_markup' => json_encode(['inline_keyboard' => []]),
        ]);
    }

    private function enviarInline(string $chatId, string $texto, array $filas): void
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $texto,
            'reply_markup' => json_encode(['inline_keyboard' => $filas]),
        ]);
    }

    // The button equivalent of "Escribe /gasto" — a tap sends a
    // gasto:nuevo callback, handled the same as the /gasto text command.
    private function enviarConBotonGasto(string $chatId, string $texto): void
    {
        $this->enviarInline($chatId, $texto, [
            [['text' => '📝 Registrar gasto', 'callback_data' => 'gasto:nuevo']],
        ]);
    }

    private function enviar(string $chatId, string $texto): void
    {
        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $texto]);
    }
}
