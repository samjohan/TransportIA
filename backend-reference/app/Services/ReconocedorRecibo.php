<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;

// Reads a receipt photo for monto/impuestos/factura_numero/nit. Tries the
// QR code first (see LectorQr) — far more reliable when the vendor's POS
// prints one with the invoice's fields in it — and only OCRs the printed
// text (via the tesseract-ocr binary, see Dockerfile) when there's no QR
// or it didn't carry anything usable. Same heuristics the driver PWA runs
// on-device (driver-app/src/qr.js, driver-app/src/ocr.js), ported to PHP
// so they can also run here: as the first step of the Telegram bot's
// /gasto flow, where there's no browser/WASM to run them in, and as the
// "second opinion" pass over PWA-sourced photos in ProcesarOcrRecibo.
class ReconocedorRecibo
{
    public function __construct(private LectorQr $qr) {}

    public function extraerMonto(string $rutaImagen): ?float
    {
        return $this->reconocer($rutaImagen)['monto'];
    }

    public function reconocer(string $rutaImagen): array
    {
        $textoQr = $this->qr->leer($rutaImagen);
        if ($textoQr !== null) {
            $desdeQr = $this->desdeTextoQr($textoQr);
            if (array_filter($desdeQr, fn ($v) => $v !== null)) {
                return $desdeQr;
            }
        }

        $texto = $this->leerTexto($rutaImagen);

        return [
            'monto' => $this->montoDesdeTexto($texto),
            'impuestos' => $this->buscarValorEnLinea($texto, fn ($l) => str_contains($l, 'iva')),
            'factura_numero' => $this->codigoEnLinea($texto, fn ($l) => str_contains($l, 'factura')),
            'nit' => $this->codigoEnLinea($texto, fn ($l) => str_contains($l, 'nit')),
        ];
    }

    // DIAN and most POS QR payloads are either a URL with the invoice's
    // fields as query params, or a flat delimited string of "key=value"
    // pairs — either way, parsing it as a query string picks them up.
    // Deliberately doesn't fall back to "largest number in the text" like
    // OCR does: a QR also typically encodes a CUFE hash or document ID,
    // and grabbing the largest number there would just be noise.
    private function desdeTextoQr(string $texto): array
    {
        $query = parse_url($texto, PHP_URL_QUERY) ?: preg_replace('/[;|]/', '&', $texto);
        parse_str($query, $parametros);
        $parametros = array_change_key_case($parametros);

        $buscar = function (array $claves) use ($parametros): ?string {
            foreach ($parametros as $clave => $valor) {
                if (! is_string($valor) || $valor === '') {
                    continue;
                }
                foreach ($claves as $buscado) {
                    if (str_contains($clave, $buscado)) {
                        return $valor;
                    }
                }
            }

            return null;
        };

        return [
            'monto' => $this->comoMoneda($buscar(['valor', 'total', 'monto'])),
            'impuestos' => $this->comoMoneda($buscar(['iva', 'impuesto'])),
            'factura_numero' => $buscar(['factura', 'numfac', 'nrofactura', 'numero']),
            'nit' => $buscar(['nit']),
        ];
    }

    private function comoMoneda(?string $valor): ?float
    {
        if ($valor === null) {
            return null;
        }

        $numero = (float) str_replace(',', '.', str_replace('.', '', preg_replace('/[^\d.,]/', '', $valor)));

        return $numero > 0 ? $numero : null;
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
