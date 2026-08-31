<?php

namespace App\Jobs;

use App\Models\Gasto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcesarOcrRecibo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Gasto $gasto) {}

    public function handle(): void
    {
        if (! $this->gasto->recibo_path) {
            return;
        }

        // Swap in Google Vision, AWS Textract, or similar here. This is the
        // "second opinion" pass — more accurate than the on-device
        // Tesseract.js reading, but only runs once the photo has synced.
        //
        // $imagen = Storage::disk('public')->get($this->gasto->recibo_path);
        // $monto = app(RecognizeReceiptAmount::class)->desde($imagen);

        $monto = null; // placeholder — wire up your OCR provider of choice

        if ($monto !== null) {
            $this->gasto->update(['monto_ocr_servidor' => $monto]);
            $this->gasto->marcarDiscrepanciaSiAplica();
        }
    }
}
