import { createWorker } from 'tesseract.js'

let worker = null

async function getWorker() {
  if (!worker) {
    // Loads from locally cached files (see vite.config.js globPatterns) —
    // works with no internet connection after the first app load.
    worker = await createWorker('spa') // Spanish-trained data for receipts
  }
  return worker
}

// Very simple heuristic: find the largest currency-looking number in the
// recognized text. Good enough as a starting point — refine per receipt
// format once you see real data (e.g. look near the word "TOTAL").
function extraerMonto(texto) {
  const coincidencias = texto.match(/\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})?/g)
  if (!coincidencias) return null

  const numeros = coincidencias
    .map((s) => parseFloat(s.replace(/\./g, '').replace(',', '.')))
    .filter((n) => !isNaN(n) && n > 0)

  return numeros.length ? Math.max(...numeros) : null
}

export async function reconocerRecibo(imagenBlob) {
  const w = await getWorker()
  const { data } = await w.recognize(imagenBlob)
  return {
    texto: data.text,
    montoDetectado: extraerMonto(data.text)
  }
}
