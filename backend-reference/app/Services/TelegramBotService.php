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
use Telegram\Bot\Objects\Update;

// Lets a conductor register gastos entirely from Telegram, as an
// alternative to the driver PWA for drivers who'd rather not install an
// app. One conversation flow per chat; state is tracked in
// TelegramSession since long-polling updates arrive one at a time with no
// memory of previous messages otherwise.
class TelegramBotService
{
    private const CATEGORIAS = ['Combustible', 'Peaje', 'Comida', 'Hospedaje', 'Mantenimiento', 'Otro'];

    public function __construct(
        private Api $telegram,
        private string $token,
    ) {}

    public function handle(Update $update): void
    {
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
            'esperando_ruta' => $this->recibirRuta($chatId, $conductor, $session, $text),
            'esperando_categoria' => $this->recibirCategoria($chatId, $session, $text),
            'esperando_monto' => $this->recibirMonto($chatId, $session, $text),
            'esperando_nota' => $this->recibirNota($chatId, $session, $text),
            'esperando_foto' => $this->recibirFoto($chatId, $conductor, $session, $photos, $text),
            default => $this->enviar($chatId, 'Escribe /gasto para registrar un gasto.'),
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

        $botones = $rutas->map(fn ($r) => [['text' => "{$r->origen} → {$r->destino}"]])->toArray();
        $this->enviar($chatId, '¿Para cuál ruta es el gasto?', $botones);
    }

    private function recibirRuta(string $chatId, User $conductor, TelegramSession $session, string $text): void
    {
        $ruta = Ruta::where('conductor_id', $conductor->id)
            ->whereRaw("origen || ' → ' || destino = ?", [$text])
            ->first();

        if (! $ruta) {
            $this->enviar($chatId, 'No reconozco esa ruta. Usa los botones de abajo.');
            return;
        }

        $session->update(['estado' => 'esperando_categoria', 'ruta_uuid' => $ruta->uuid]);
        $this->enviar($chatId, '¿Categoría del gasto?', array_map(fn ($c) => [['text' => $c]], self::CATEGORIAS));
    }

    private function recibirCategoria(string $chatId, TelegramSession $session, string $text): void
    {
        $valorEnBd = strtolower($text);
        if (! in_array($valorEnBd, array_map('strtolower', self::CATEGORIAS), true)) {
            $this->enviar($chatId, 'Elige una categoría con los botones de abajo.');
            return;
        }

        $session->update(['estado' => 'esperando_monto', 'categoria' => $valorEnBd]);
        $this->enviar($chatId, '¿Cuál fue el monto? Envía solo el número, en pesos colombianos (ej: 50000).', removerTeclado: true);
    }

    private function recibirMonto(string $chatId, TelegramSession $session, string $text): void
    {
        $monto = (float) preg_replace('/\D/', '', $text);
        if ($monto <= 0) {
            $this->enviar($chatId, 'Envía un monto válido, solo números (ej: 50000).');
            return;
        }

        $session->update(['estado' => 'esperando_nota', 'monto' => $monto]);
        $this->enviar($chatId, '¿Alguna nota? Escríbela, o toca "Sin nota".', [[['text' => 'Sin nota']]]);
    }

    private function recibirNota(string $chatId, TelegramSession $session, string $text): void
    {
        $session->update(['estado' => 'esperando_foto', 'nota' => $text === 'Sin nota' ? null : $text]);
        $this->enviar($chatId, 'Envía una foto del recibo, o toca "Sin foto".', [[['text' => 'Sin foto']]]);
    }

    private function recibirFoto(string $chatId, User $conductor, TelegramSession $session, ?array $photos, string $text): void
    {
        $reciboPath = null;

        if ($photos) {
            // Telegram sends the same photo at several resolutions; the
            // last entry is the largest.
            $reciboPath = $this->descargarFoto(collect($photos)->last()->getFileId());
        } elseif ($text !== 'Sin foto') {
            $this->enviar($chatId, 'Envía una foto o toca "Sin foto".');
            return;
        }

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
            "✅ Gasto registrado: \${$montoFormateado} en {$session->categoria} para {$ruta->origen} → {$ruta->destino}.",
            removerTeclado: true
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
        $this->enviar($chatId, $mensaje, removerTeclado: true);
    }

    private function enviar(string $chatId, string $texto, ?array $botones = null, bool $removerTeclado = false): void
    {
        $params = ['chat_id' => $chatId, 'text' => $texto];

        if ($botones) {
            $params['reply_markup'] = json_encode([
                'keyboard' => $botones,
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]);
        } elseif ($removerTeclado) {
            $params['reply_markup'] = json_encode(['remove_keyboard' => true]);
        }

        $this->telegram->sendMessage($params);
    }
}
