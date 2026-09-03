<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;

// Server-side OCR pass over a receipt photo. Same "largest
// currency-looking number" heuristic the driver PWA already runs
// on-device with tesseract.js (driver-app/src/ocr.js) — ported to PHP via
// the tesseract-ocr binary (see Dockerfile) so it can also run here: as
// the first step of the Telegram bot's /gasto flow, where there's no
// browser/WASM to run tesseract.js in, and as the "second opinion" pass
// over PWA-sourced photos in ProcesarOcrRecibo.
class ReconocedorRecibo
{
    public function extraerMonto(string $rutaImagen): ?float
    {
        try {
            $texto = (new TesseractOCR($rutaImagen))->lang('spa')->run();
        } catch (\Throwable $e) {
            report($e);
            return null;
        }

        return $this->montoDesdeTexto($texto);
    }

    private function montoDesdeTexto(string $texto): ?float
    {
        if (! preg_match_all('/\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})?/', $texto, $coincidencias)) {
            return null;
        }

        $numeros = array_values(array_filter(array_map(
            fn ($s) => (float) str_replace(',', '.', str_replace('.', '', $s)),
            $coincidencias[0]
        ), fn ($n) => $n > 0));

        return $numeros ? max($numeros) : null;
    }
}
