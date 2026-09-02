import { useEffect, useState } from 'react'
import { useLiveQuery } from 'dexie-react-hooks'
import { db } from '../db'
import { pullRutasAsignadas } from '../sync'
import RegistrarGasto from './RegistrarGasto'
import { formatCOP } from '../format'

const CATEGORIAS = {
  combustible: 'Combustible', peaje: 'Peaje', comida: 'Comida',
  hospedaje: 'Hospedaje', mantenimiento: 'Mantenimiento', otro: 'Otro',
}

const ESTADO_BADGE = {
  pendiente: 'bg-amber-100 text-amber-700',
  en_curso: 'bg-blue-100 text-blue-700',
  completada: 'bg-emerald-100 text-emerald-700',
  cancelada: 'bg-slate-200 text-slate-600',
}

export default function InicioConductor() {
  const [rutaSeleccionada, setRutaSeleccionada] = useState(null)
  const [actualizando, setActualizando] = useState(false)
  const rutas = useLiveQuery(() => db.rutas.toArray(), []) ?? []
  const gastos = useLiveQuery(
    () => rutaSeleccionada
      ? db.gastos.where('ruta_uuid').equals(rutaSeleccionada.uuid).toArray()
      : [],
    [rutaSeleccionada]
  ) ?? []

  async function actualizar() {
    if (!navigator.onLine) return
    setActualizando(true)
    try {
      await pullRutasAsignadas()
    } catch (err) {
      console.warn('No se pudo actualizar la lista de rutas:', err.message)
    } finally {
      setActualizando(false)
    }
  }

  // Re-sincroniza cada vez que se vuelve a la lista (incluye el montaje
  // inicial) — antes solo se traía una vez al cargar la app, así que si el
  // planificador cambiaba algo mientras el conductor seguía con la app
  // abierta, veía datos desactualizados aunque estuviera en línea.
  useEffect(() => {
    if (!rutaSeleccionada) actualizar()
  }, [rutaSeleccionada])

  if (rutaSeleccionada) {
    return (
      <div className="pb-8">
        <div className="px-4 py-3">
          <button
            onClick={() => setRutaSeleccionada(null)}
            className="text-sm font-medium text-brand-600 hover:text-brand-700"
          >
            ← Volver a mis rutas
          </button>
        </div>

        <div className="px-4">
          <RegistrarGasto ruta={rutaSeleccionada} onGuardado={() => {}} />
        </div>

        <div className="px-4 mt-6">
          <h3 className="text-sm font-semibold text-slate-700 mb-3">Gastos registrados en esta ruta</h3>
          {gastos.length === 0 && (
            <p className="text-sm text-slate-400">Todavía no hay gastos en esta ruta.</p>
          )}
          <ul className="space-y-2">
            {gastos.map((g) => (
              <li key={g.uuid} className="card px-4 py-3 flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-slate-800">{CATEGORIAS[g.categoria] || g.categoria}</p>
                  <p className="text-sm text-slate-500">{formatCOP(g.monto)}</p>
                </div>
                <span className={`badge ${g.synced ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>
                  {g.synced ? 'Sincronizado' : 'Pendiente'}
                </span>
              </li>
            ))}
          </ul>
        </div>
      </div>
    )
  }

  return (
    <div className="px-4 py-5">
      <div className="flex items-center justify-between mb-4">
        <h1 className="text-xl font-semibold text-slate-900">Mis rutas</h1>
        <button
          onClick={actualizar}
          disabled={actualizando}
          className="text-sm font-medium text-brand-600 hover:text-brand-700 disabled:text-slate-400"
        >
          {actualizando ? 'Actualizando…' : '↻ Actualizar'}
        </button>
      </div>
      {rutas.length === 0 && (
        <p className="text-sm text-slate-400">No hay rutas asignadas todavía.</p>
      )}
      <ul className="space-y-3">
        {rutas.map((r) => (
          <li key={r.uuid}>
            <button
              onClick={() => setRutaSeleccionada(r)}
              className="card w-full text-left px-4 py-4 hover:border-brand-400 transition-colors"
            >
              <div className="flex items-center justify-between gap-3">
                <strong className="text-slate-800">{r.origen} → {r.destino}</strong>
                <span className={`badge shrink-0 ${ESTADO_BADGE[r.estado] || 'bg-slate-100 text-slate-600'}`}>
                  {r.estado}
                </span>
              </div>
            </button>
          </li>
        ))}
      </ul>
    </div>
  )
}
