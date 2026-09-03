<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;

// Server-side OCR pass over a receipt photo. Same heuristics the driver
// PWA already runs on-device with tesseract.js (driver-app/src/ocr.js) —
// ported to PHP via the tesseract-ocr binary (see Dockerfile) so it can
// also run here: as the first step of the Telegram bot's /gasto flow,
// where there's no browser/WASM to run tesseract.js in, and as the
// "second opinion" pass over PWA-sourced photos in ProcesarOcrRecibo.
class ReconocedorRecibo
{
    public function extraerMonto(string $rutaImagen): ?float
    {
        return $this->montoDesdeTexto($this->leerTexto($rutaImagen));
    }

    // One OCR pass, both figures — used by the Telegram flow, which wants
    // the total and the tax line from the same photo without paying for
    // Tesseract twice.
    public function reconocer(string $rutaImagen): array
    {
        $texto = $this->leerTexto($rutaImagen);

        return [
            'monto' => $this->montoDesdeTexto($texto),
            'impuestos' => $this->buscarValorEnLinea($texto, fn ($l) => str_contains($l, 'iva')),
            'factura_numero' => $this->codigoEnLinea($texto, fn ($l) => str_contains($l, 'factura')),
            'nit' => $this->codigoEnLinea($texto, fn ($l) => str_contains($l, 'nit')),
        ];
    }

    private function leerTexto(string $rutaImagen): string
    {
        try {
            return (new TesseractOCR($rutaImagen))->lang('spa')->run();
        } catch (\Throwable $e) {
            report($e);
            return '';
        }
    }

    // Prefers the number on the line that says "TOTAL" (but not
    // "SUBTOTAL") over just the largest number anywhere on the receipt —
    // much more reliable once there's a subtotal, tax and tip all
    // printed as separate lines.
    private function montoDesdeTexto(string $texto): ?float
    {
        return $this->buscarValorEnLinea($texto, fn ($l) => str_contains($l, 'total') && ! str_contains($l, 'subtotal'))
            ?? $this->mayorNumero($texto);
    }

    private function buscarValorEnLinea(string $texto, callable $esCoincidencia): ?float
    {
        foreach (explode("\n", $texto) as $linea) {
            if ($esCoincidencia(strtolower($linea))) {
                $numero = $this->mayorNumero($linea);
                if ($numero !== null) {
                    return $numero;
                }
            }
        }

        return null;
    }

    private function mayorNumero(string $texto): ?float
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

    // Grabs an identifier-looking token (letters, digits, dots, dashes —
    // at least 5 characters, must contain a digit) from a matching line,
    // rather than currency parsing like mayorNumero: dots in a NIT
    // ("900.123.456-7") aren't thousands separators, and an invoice
    // number may have a real letter prefix ("FE-12345"). Takes the last
    // such token on the line, since the label itself ("NIT", "FACTURA")
    // has no digit and gets filtered out.
    private function codigoEnLinea(string $texto, callable $esCoincidencia): ?string
    {
        foreach (explode("\n", $texto) as $linea) {
            if (! $esCoincidencia(strtolower($linea))) {
                continue;
            }

            preg_match_all('/[a-z0-9][a-z0-9.\-]{4,}/i', $linea, $candidatos);
            $codigo = null;
            foreach ($candidatos[0] as $candidato) {
                if (preg_match('/\d/', $candidato)) {
                    $codigo = $candidato;
                }
            }

            if ($codigo !== null) {
                return strtoupper(trim($codigo, '.-'));
            }
        }

        return null;
    }
}
