<?php

namespace App\Console\Commands;

use App\Services\ReconocedorRecibo;
use App\Services\TelegramBotService;
use Illuminate\Console\Command;
use Telegram\Bot\Api;

// Long-polling instead of a webhook: Telegram webhooks require a public
// HTTPS endpoint, which this app may not have (e.g. Dokploy's sslip.io
// domains, which don't support TLS at all). Polling only needs outbound
// HTTPS to Telegram's API, so it works regardless of whether — or how —
// this app is reachable from the internet. Runs as its own long-lived
// process (see the `telegram-bot` service in docker-compose*.yml), not
// through the normal request/response backend.
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll';

    protected $description = 'Escucha mensajes de Telegram por long-polling y registra gastos de conductores';

    public function handle(): int
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        if (! $token) {
            $this->error('TELEGRAM_BOT_TOKEN no está configurado — el bot de Telegram no se iniciará.');

            return self::FAILURE;
        }

        $telegram = new Api($token);
        $service = new TelegramBotService($telegram, $token, new ReconocedorRecibo());

        $this->info('Bot de Telegram escuchando…');
        $offset = 0;

        while (true) {
            try {
                $updates = $telegram->getUpdates(['offset' => $offset, 'timeout' => 30]);
                foreach ($updates as $update) {
                    $offset = $update->getUpdateId() + 1;
                    $service->handle($update);
                }
            } catch (\Throwable $e) {
                $this->error('Error en el bot de Telegram: '.$e->getMessage());
                sleep(5);
            }
        }
    }
}
