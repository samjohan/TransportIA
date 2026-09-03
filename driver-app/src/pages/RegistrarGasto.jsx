import { useState } from 'react'
import { db, queueMutation } from '../db'
import { reconocerRecibo } from '../ocr'
import { pushQueuedMutations } from '../sync'
import MoneyInput from '../components/MoneyInput'

const CATEGORIAS = [
  ['combustible', 'Combustible'], ['peaje', 'Peaje'], ['comida', 'Comida'],
  ['hospedaje', 'Hospedaje'], ['mantenimiento', 'Mantenimiento'], ['otro', 'Otro'],
]

function uuid() {
  // crypto.randomUUID() only exists in a "secure context" (HTTPS or
  // localhost) — plain-HTTP deployments (e.g. Dokploy's sslip.io domain,
  // which has no TLS support) don't expose it at all. getRandomValues()
  // has no such restriction, so build a v4 UUID from that instead.
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID()
  }
  if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
    const bytes = crypto.getRandomValues(new Uint8Array(16))
    bytes[6] = (bytes[6] & 0x0f) | 0x40
    bytes[8] = (bytes[8] & 0x3f) | 0x80
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0'))
    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10, 16).join('')}`
  }
  // No Web Crypto API at all — extremely unlikely, but don't crash.
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    const v = c === 'x' ? r : (r & 0x3) | 0x8
    return v.toString(16)
  })
}

export default function RegistrarGasto({ ruta, onGuardado }) {
  const [monto, setMonto] = useState('')
  const [categoria, setCategoria] = useState('combustible')
  const [nota, setNota] = useState('')
  const [fotoBlob, setFotoBlob] = useState(null)
  const [fotoPreview, setFotoPreview] = useState(null)
  const [montoOcr, setMontoOcr] = useState(null)
  const [impuestos, setImpuestos] = useState(null)
  const [facturaNumero, setFacturaNumero] = useState(null)
  const [nit, setNit] = useState(null)
  const [procesandoOcr, setProcesandoOcr] = useState(false)
  const [guardado, setGuardado] = useState(false)

  async function handleFoto(e) {
    const file = e.target.files[0]
    if (!file) return

    setFotoBlob(file)
    setFotoPreview(URL.createObjectURL(file))
    setProcesandoOcr(true)

    try {
      // Runs fully on-device — works with no internet connection.
      const { montoDetectado, impuestoDetectado, facturaDetectada, nitDetectado } = await reconocerRecibo(file)
      if (montoDetectado) {
        setMontoOcr(montoDetectado)
        setMonto(montoDetectado) // pre-fill; driver can still edit
      }
      if (impuestoDetectado) {
        setImpuestos(impuestoDetectado)
      }
      if (facturaDetectada) {
        setFacturaNumero(facturaDetectada)
      }
      if (nitDetectado) {
        setNit(nitDetectado)
      }
    } catch (err) {
      console.warn('OCR no disponible:', err.message)
    } finally {
      setProcesandoOcr(false)
    }
  }

  async function handleSubmit(e) {
    e.preventDefault()

    const registro = {
      uuid: uuid(),
      ruta_uuid: ruta.uuid,
      monto: Number(monto),
      impuestos,
      categoria,
      nota,
      factura_numero: facturaNumero,
      nit,
      monto_ocr: montoOcr,
      creado_offline_en: new Date().toISOString(),
      synced: 0,
      created_at: new Date().toISOString(),
    }

    // Local-first: save immediately, regardless of connectivity.
    await db.gastos.put(registro)
    await queueMutation('gastos', registro, fotoBlob)

    // Nothing else was actually pushing queued gastos to the server after
    // this point — only a fresh app load or an `online` browser event did.
    // A gasto registered while the tab stayed open and online just sat
    // queued indefinitely. Try right away if we're online; if it fails
    // (or we're offline) it stays queued for the next sync as before.
    if (navigator.onLine) {
      pushQueuedMutations().catch((err) => console.warn('No se pudo sincronizar el gasto:', err.message))
    }

    setMonto(''); setCategoria('combustible'); setNota('')
    setFotoBlob(null); setFotoPreview(null); setMontoOcr(null); setImpuestos(null)
    setFacturaNumero(null); setNit(null)
    onGuardado?.()

    setGuardado(true)
    setTimeout(() => setGuardado(false), 2500)
  }

  return (
    <div className="card p-4">
      <h2 className="text-lg font-semibold text-slate-900">Registrar gasto</h2>
      <p className="text-sm text-slate-500 mb-4">{ruta.origen} → {ruta.destino}</p>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="label">Foto del recibo</label>
          <label className="flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500 cursor-pointer hover:border-brand-400 hover:text-brand-600 transition-colors">
            <span aria-hidden="true">📷</span>
            {fotoBlob ? 'Cambiar foto' : 'Tomar o elegir foto'}
            <input type="file" accept="image/*" capture="environment" onChange={handleFoto} className="hidden" />
          </label>

          {fotoPreview && (
            <img src={fotoPreview} alt="Recibo" className="mt-3 max-h-48 rounded-lg border border-slate-200" />
          )}
          {procesandoOcr && (
            <p className="mt-2 text-sm text-slate-500">Leyendo el recibo…</p>
          )}
          {montoOcr && !procesandoOcr && (
            <p className="mt-2 text-sm text-emerald-600">
              Monto detectado: {new Intl.NumberFormat('es-CO').format(montoOcr)} (puedes corregirlo abajo)
            </p>
          )}
          {impuestos && !procesandoOcr && (
            <p className="mt-1 text-sm text-emerald-600">
              Impuestos detectados: {new Intl.NumberFormat('es-CO').format(impuestos)}
            </p>
          )}
          {(facturaNumero || nit) && !procesandoOcr && (
            <p className="mt-1 text-sm text-emerald-600">
              {facturaNumero && `Factura: ${facturaNumero}`}
              {facturaNumero && nit && ' · '}
              {nit && `NIT: ${nit}`}
            </p>
          )}
        </div>

        <div>
          <label className="label">Monto</label>
          <MoneyInput value={monto} onChange={setMonto} required />
        </div>

        <div>
          <label className="label">Categoría</label>
          <select className="input" value={categoria} onChange={(e) => setCategoria(e.target.value)}>
            {CATEGORIAS.map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
          </select>
        </div>

        <div>
          <label className="label">Nota (opcional)</label>
          <textarea
            className="input"
            rows={2}
            value={nota}
            onChange={(e) => setNota(e.target.value)}
          />
        </div>

        {guardado && (
          <p className="text-sm text-emerald-600">Gasto guardado.</p>
        )}

        <button type="submit" className="btn-primary w-full">
          Guardar gasto
        </button>
      </form>
    </div>
  )
}
