<?php

namespace App\Services;

use App\Jobs\ProcesarOcrRecibo;
use App\Models\Gasto;
use App\Models\Ruta;
use App\Models\TelegramSession;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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
// Ruta/categoría/nota/foto choices are inline keyboards (buttons attached
// to the bot's own message, answered via callback_query) rather than a
// persistent reply keyboard: tapping one doesn't leave a stray text message
// in the chat, and the message is edited afterwards to show what was
// picked and drop the buttons, so old ones can't be tapped again.
class TelegramBotService
{
    private const CATEGORIAS = ['Combustible', 'Peaje', 'Comida', 'Hospedaje', 'Mantenimiento', 'Otro'];

    // Which session state a given callback_data prefix is only valid in —
    // guards against a stray tap on an inline keyboard left over from an
    // abandoned or already-finished flow.
    private const ESTADO_POR_CALLBACK = [
        'ruta' => 'esperando_ruta',
        'cat' => 'esperando_categoria',
        'nota' => 'esperando_nota',
        'foto' => 'esperando_foto',
    ];

    public function __construct(
        private Api $telegram,
        private string $token,
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
        $photos = $message->getPhoto();

        $conductor = User::where('telegram_chat_id', $chatId)->first();

        if (! $conductor) {
            $this->vincularCuenta($chatId, $text);
            return;
        }

        if (in_array($text, ['/start', '/gasto', '/nuevo'], true)) {
            $this->iniciarGasto($chatId, $conductor);
            return;
        }

        if ($text === '/cancelar') {
            $this->reiniciar($chatId, 'Registro cancelado. Escribe /gasto para empezar de nuevo.');
            return;
        }

        $session = TelegramSession::firstOrCreate(['chat_id' => $chatId]);

        match ($session->estado) {
            'esperando_ruta' => $this->enviar($chatId, 'Toca uno de los botones de arriba para elegir la ruta.'),
            'esperando_categoria' => $this->enviar($chatId, 'Toca uno de los botones de arriba para elegir la categoría.'),
            'esperando_monto' => $this->recibirMonto($chatId, $session, $text),
            'esperando_nota' => $this->recibirNota($chatId, $session, $text),
            'esperando_foto' => $this->recibirFoto($chatId, $conductor, $session, $photos),
            default => $this->enviar($chatId, 'Escribe /gasto para registrar un gasto.'),
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
        $session = TelegramSession::firstOrCreate(['chat_id' => $chatId]);

        $estadoEsperado = self::ESTADO_POR_CALLBACK[$tipo] ?? null;
        if ($estadoEsperado === null || $session->estado !== $estadoEsperado) {
            $this->marcarSeleccion($chatId, $messageId, 'Ese botón ya no es válido. Escribe /gasto para empezar de nuevo.');
            return;
        }

        match ($tipo) {
            'ruta' => $this->seleccionarRuta($chatId, $messageId, $conductor, $session, $valor),
            'cat' => $this->seleccionarCategoria($chatId, $messageId, $session, $valor),
            'nota' => $this->omitirNota($chatId, $messageId, $session),
            'foto' => $this->omitirFoto($chatId, $messageId, $conductor, $session),
        };
    }

    private function vincularCuenta(string $chatId, string $text): void
    {
        if (! str_contains($text, ' ')) {
            $this->enviar($chatId, "👋 Hola, soy el bot de TransportIA.\n\nPara vincular tu cuenta de conductor, envía tu correo y contraseña separados por un espacio, así:\ncorreo@ejemplo.com tu_contraseña");
            return;
        }

        [$email, $password] = explode(' ', $text, 2);
        $conductor = User::where('email', trim($email))->role('conductor')->first();

        if (! $conductor || ! Hash::check($password, $conductor->password)) {
            $this->enviar($chatId, '❌ Correo o contraseña incorrectos. Intenta de nuevo.');
            return;
        }

        $conductor->update(['telegram_chat_id' => $chatId]);
        $this->enviar($chatId, "✅ Cuenta vinculada, {$conductor->name}. Escribe /gasto para registrar un gasto.");
    }

    private function iniciarGasto(string $chatId, User $conductor): void
    {
        $rutas = Ruta::where('conductor_id', $conductor->id)
            ->whereIn('estado', ['pendiente', 'en_curso'])
            ->latest()
            ->get();

        if ($rutas->isEmpty()) {
            $this->enviar($chatId, 'No tienes rutas activas en este momento.');
            return;
        }

        TelegramSession::updateOrCreate(['chat_id' => $chatId], [
            'estado' => 'esperando_ruta', 'ruta_uuid' => null, 'categoria' => null, 'monto' => null, 'nota' => null,
        ]);

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
            $this->marcarSeleccion($chatId, $messageId, 'Esa ruta ya no está disponible. Escribe /gasto para empezar de nuevo.');
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
        $this->enviar($chatId, '¿Cuál fue el monto? Envía solo el número, en pesos colombianos (ej: 50000).');
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

    private function recibirNota(string $chatId, TelegramSession $session, string $text): void
    {
        $session->update(['estado' => 'esperando_foto', 'nota' => $text]);
        $this->enviarInline($chatId, 'Envía una foto del recibo, o toca "Sin foto".', [
            [['text' => 'Sin foto', 'callback_data' => 'foto:skip']],
        ]);
    }

    private function omitirNota(string $chatId, int $messageId, TelegramSession $session): void
    {
        $this->marcarSeleccion($chatId, $messageId, 'Sin nota');
        $session->update(['estado' => 'esperando_foto', 'nota' => null]);
        $this->enviarInline($chatId, 'Envía una foto del recibo, o toca "Sin foto".', [
            [['text' => 'Sin foto', 'callback_data' => 'foto:skip']],
        ]);
    }

    private function recibirFoto(string $chatId, User $conductor, TelegramSession $session, ?array $photos): void
    {
        if (! $photos) {
            $this->enviar($chatId, 'Envía una foto o toca "Sin foto".');
            return;
        }

        // Telegram sends the same photo at several resolutions; the last
        // entry is the largest.
        $reciboPath = $this->descargarFoto(collect($photos)->last()->getFileId());
        $this->finalizarGasto($chatId, $conductor, $session, $reciboPath);
    }

    private function omitirFoto(string $chatId, int $messageId, User $conductor, TelegramSession $session): void
    {
        $this->marcarSeleccion($chatId, $messageId, 'Sin foto');
        $this->finalizarGasto($chatId, $conductor, $session, null);
    }

    private function finalizarGasto(string $chatId, User $conductor, TelegramSession $session, ?string $reciboPath): void
    {
        $gasto = Gasto::create([
            'uuid' => (string) Str::uuid(),
            'ruta_uuid' => $session->ruta_uuid,
            'conductor_id' => $conductor->id,
            'monto' => $session->monto,
            'categoria' => $session->categoria,
            'nota' => $session->nota,
            'recibo_path' => $reciboPath,
            'creado_offline_en' => now(),
        ]);

        if ($reciboPath) {
            ProcesarOcrRecibo::dispatch($gasto);
        }

        $ruta = Ruta::find($session->ruta_uuid);
        $montoFormateado = number_format((float) $session->monto, 0, ',', '.');
        $this->enviar(
            $chatId,
            "✅ Gasto registrado: \${$montoFormateado} en {$session->categoria} para {$ruta->origen} → {$ruta->destino}."
        );

        $session->update(['estado' => 'inicio', 'ruta_uuid' => null, 'categoria' => null, 'monto' => null, 'nota' => null]);
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
        TelegramSession::updateOrCreate(['chat_id' => $chatId], [
            'estado' => 'inicio', 'ruta_uuid' => null, 'categoria' => null, 'monto' => null, 'nota' => null,
        ]);
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

    private function enviar(string $chatId, string $texto): void
    {
        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $texto]);
    }
}
