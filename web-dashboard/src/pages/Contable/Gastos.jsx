import { useEffect, useState } from 'react'
import { api } from '../../api'
import { formatCOP } from '../../format'

const CATEGORIAS = {
  combustible: 'Combustible', peaje: 'Peaje', comida: 'Comida',
  hospedaje: 'Hospedaje', mantenimiento: 'Mantenimiento', otro: 'Otro'
}

const CATEGORIA_BADGE = {
  combustible: 'bg-amber-100 text-amber-700',
  peaje: 'bg-blue-100 text-blue-700',
  comida: 'bg-emerald-100 text-emerald-700',
  hospedaje: 'bg-purple-100 text-purple-700',
  mantenimiento: 'bg-rose-100 text-rose-700',
  otro: 'bg-slate-200 text-slate-600',
}

export default function Gastos() {
  const [gastos, setGastos] = useState([])
  const [cargando, setCargando] = useState(true)

  useEffect(() => {
    api.get('/gastos').then((r) => {
      setGastos(r.data)
      setCargando(false)
    })
  }, [])

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Detalle de gastos</h1>
        <p className="text-sm text-slate-500 mt-1">Todos los gastos registrados por los conductores.</p>
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-5 py-3">Fecha</th>
              <th className="px-5 py-3">Conductor</th>
              <th className="px-5 py-3">Ruta</th>
              <th className="px-5 py-3">Categoría</th>
              <th className="px-5 py-3">Monto</th>
              <th className="px-5 py-3">Impuestos</th>
              <th className="px-5 py-3">Factura</th>
              <th className="px-5 py-3">NIT</th>
              <th className="px-5 py-3">Nota</th>
              <th className="px-5 py-3">Recibo</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {cargando && (
              <tr>
                <td colSpan={10} className="px-5 py-8 text-center text-slate-400">Cargando…</td>
              </tr>
            )}
            {!cargando && gastos.length === 0 && (
              <tr>
                <td colSpan={10} className="px-5 py-8 text-center text-slate-400">No hay gastos registrados.</td>
              </tr>
            )}
            {gastos.map((g) => (
              <tr key={g.uuid} className="hover:bg-slate-50">
                <td className="px-5 py-3 text-slate-600">{new Date(g.created_at).toLocaleDateString('es')}</td>
                <td className="px-5 py-3 font-medium text-slate-800">{g.conductor?.name}</td>
                <td className="px-5 py-3 text-slate-600">{g.ruta?.origen} → {g.ruta?.destino}</td>
                <td className="px-5 py-3">
                  <span className={`badge ${CATEGORIA_BADGE[g.categoria] || 'bg-slate-100 text-slate-600'}`}>
                    {CATEGORIAS[g.categoria]}
                  </span>
                </td>
                <td className="px-5 py-3 text-slate-600">{formatCOP(g.monto)}</td>
                <td className="px-5 py-3 text-slate-600">
                  {g.impuestos != null ? formatCOP(g.impuestos) : <span className="text-slate-300">—</span>}
                </td>
                <td className="px-5 py-3 text-slate-600">{g.factura_numero || <span className="text-slate-300">—</span>}</td>
                <td className="px-5 py-3 text-slate-600">{g.nit || <span className="text-slate-300">—</span>}</td>
                <td className="px-5 py-3 text-slate-500">{g.nota}</td>
                <td className="px-5 py-3">
                  {g.recibo_path ? (
                    <span className="flex gap-2">
                      <a href={`/storage/${g.recibo_path}`} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">
                        Ver{g.recibo_path_2 ? ' (1)' : ''}
                      </a>
                      {g.recibo_path_2 && (
                        <a href={`/storage/${g.recibo_path_2}`} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">
                          Ver (2)
                        </a>
                      )}
                    </span>
                  ) : (
                    <span className="text-slate-300">—</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
