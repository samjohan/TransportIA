<?php

namespace App\Jobs;

use App\Models\Gasto;
use App\Services\ReconocedorRecibo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcesarOcrRecibo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Gasto $gasto) {}

    public function handle(ReconocedorRecibo $reconocedor): void
    {
        if (! $this->gasto->recibo_path) {
            return;
        }

        // "Second opinion" pass — runs once the photo has synced, so it
        // catches gastos created from a low-quality on-device (PWA) or
        // no-OCR (Telegram "Sin foto" then later-attached) reading too.
        $monto = $reconocedor->extraerMonto(Storage::disk('public')->path($this->gasto->recibo_path));

        if ($monto !== null) {
            $this->gasto->update(['monto_ocr_servidor' => $monto]);
            $this->gasto->marcarDiscrepanciaSiAplica();
        }
    }
}
