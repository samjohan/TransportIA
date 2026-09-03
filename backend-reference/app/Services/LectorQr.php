<?php

namespace App\Services;

use Zxing\QrReader;

// Decodes a QR code from a receipt photo, when there is one. Colombian
// electronic invoices (DIAN) print one that, when the vendor's POS
// includes the invoice's fields in it, is a far more reliable source for
// monto/impuestos/factura/nit than OCR-ing the printed text — see
// ReconocedorRecibo, which tries this first and only falls back to OCR
// when there's no QR or it doesn't carry anything usable.
class LectorQr
{
    public function leer(string $rutaImagen): ?string
    {
        try {
            $texto = (new QrReader($rutaImagen))->text();
        } catch (\Throwable $e) {
            report($e);
            return null;
        }

        return $texto !== false && $texto !== '' ? $texto : null;
    }
}
