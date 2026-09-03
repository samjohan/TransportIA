import jsQR from 'jsqr'

// Decodes a QR code from an image blob, if there is one — draws it to an
// offscreen canvas since jsQR needs raw pixel data (ImageData), not the
// blob itself.
async function leerQr(imagenBlob) {
  const bitmap = await createImageBitmap(imagenBlob)
  const canvas = document.createElement('canvas')
  canvas.width = bitmap.width
  canvas.height = bitmap.height
  const ctx = canvas.getContext('2d')
  ctx.drawImage(bitmap, 0, 0)

  const { data, width, height } = ctx.getImageData(0, 0, canvas.width, canvas.height)
  return jsQR(data, width, height)?.data || null
}

// DIAN and most POS QR payloads are either a URL with the invoice's
// fields as query params, or a flat delimited string of "key=value"
// pairs — either way, parsing it as a query string picks them up.
// Deliberately doesn't fall back to "largest number in the text" like OCR
// does: a QR also typically encodes a CUFE hash or document ID, and
// grabbing the largest number there would just be noise.
function camposDesdeQr(texto) {
  let query = texto
  try {
    query = new URL(texto).search.slice(1)
  } catch {
    query = texto.replace(/[;|]/g, '&')
  }

  const params = new URLSearchParams(query)
  const buscar = (claves) => {
    for (const [clave, valor] of params) {
      if (!valor) continue
      if (claves.some((c) => clave.toLowerCase().includes(c))) return valor
    }
    return null
  }

  const comoMoneda = (valor) => {
    if (!valor) return null
    const numero = parseFloat(
      valor.replace(/[^\d.,]/g, '').replace(/\./g, '').replace(',', '.')
    )
    return numero > 0 ? numero : null
  }

  return {
    montoDetectado: comoMoneda(buscar(['valor', 'total', 'monto'])),
    impuestoDetectado: comoMoneda(buscar(['iva', 'impuesto'])),
    facturaDetectada: buscar(['factura', 'numfac', 'nrofactura', 'numero']),
    nitDetectado: buscar(['nit']),
  }
}

// Returns null (rather than a campos object with everything null) when
// there's no QR, or it didn't carry anything recognizable — reconocerRecibo
// in ocr.js treats either case the same way: fall back to OCR-ing the
// printed text instead.
export async function reconocerQr(imagenBlob) {
  const texto = await leerQr(imagenBlob)
  if (!texto) return null

  const campos = camposDesdeQr(texto)
  return Object.values(campos).some((v) => v !== null) ? campos : null
}
