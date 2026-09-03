import { createWorker } from 'tesseract.js'
import { reconocerQr } from './qr'

let worker = null

async function getWorker() {
  if (!worker) {
    // Loads from locally cached files (see vite.config.js globPatterns) —
    // works with no internet connection after the first app load.
    worker = await createWorker('spa') // Spanish-trained data for receipts
  }
  return worker
}

// Largest currency-looking number on a single line. Used both as the
// fallback when no labeled line matches, and to pull a number off a line
// that already matched one (TOTAL, IVA).
function mayorNumero(texto) {
  const coincidencias = texto.match(/\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})?/g)
  if (!coincidencias) return null

  const numeros = coincidencias
    .map((s) => parseFloat(s.replace(/\./g, '').replace(',', '.')))
    .filter((n) => !isNaN(n) && n > 0)

  return numeros.length ? Math.max(...numeros) : null
}

// Scans line by line for one matching `esCoincidencia` and returns the
// largest number on that line — e.g. the value next to "TOTAL" or "IVA",
// rather than just the largest number anywhere on the receipt.
function buscarValorEnLinea(texto, esCoincidencia) {
  for (const linea of texto.split('\n')) {
    if (!esCoincidencia(linea.toLowerCase())) continue
    const numero = mayorNumero(linea)
    if (numero !== null) return numero
  }
  return null
}

// Prefers the line that says "TOTAL" (but not "SUBTOTAL") over just the
// largest number on the receipt — much more reliable once subtotal, tax
// and tip are all printed as separate lines.
function extraerMonto(texto) {
  return (
    buscarValorEnLinea(texto, (l) => l.includes('total') && !l.includes('subtotal')) ??
    mayorNumero(texto)
  )
}

function extraerImpuestos(texto) {
  return buscarValorEnLinea(texto, (l) => l.includes('iva'))
}

// Grabs an identifier-looking token (letters, digits, dots, dashes — at
// least 5 characters, must contain a digit) from a matching line, rather
// than currency parsing like mayorNumero: dots in a NIT ("900.123.456-7")
// aren't thousands separators, and an invoice number may have a real
// letter prefix ("FE-12345"). Takes the last such token on the line,
// since the label itself ("NIT", "FACTURA") has no digit and gets
// filtered out.
function extraerCodigoEnLinea(texto, esCoincidencia) {
  for (const linea of texto.split('\n')) {
    if (!esCoincidencia(linea.toLowerCase())) continue
    const candidatos = linea.match(/[a-z0-9][a-z0-9.\-]{4,}/gi) || []
    const codigo = candidatos.filter((c) => /\d/.test(c)).pop()
    if (codigo) return codigo.replace(/^[.\-]+|[.\-]+$/g, '').toUpperCase()
  }
  return null
}

function extraerFactura(texto) {
  return extraerCodigoEnLinea(texto, (l) => l.includes('factura'))
}

function extraerNit(texto) {
  return extraerCodigoEnLinea(texto, (l) => l.includes('nit'))
}

// Tries the receipt's QR code first — far more reliable than OCR when
// the vendor's POS prints one with the invoice's fields in it — and only
// OCRs the printed text (via tesseract.js) when there's no QR or it
// didn't carry anything usable.
export async function reconocerRecibo(imagenBlob) {
  const desdeQr = await reconocerQr(imagenBlob)
  if (desdeQr) {
    return { texto: null, ...desdeQr }
  }

  const w = await getWorker()
  const { data } = await w.recognize(imagenBlob)
  return {
    texto: data.text,
    montoDetectado: extraerMonto(data.text),
    impuestoDetectado: extraerImpuestos(data.text),
    facturaDetectada: extraerFactura(data.text),
    nitDetectado: extraerNit(data.text),
  }
}
