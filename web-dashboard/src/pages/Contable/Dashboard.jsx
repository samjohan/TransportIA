import { useEffect, useState } from 'react'
import { BarChart, Bar, PieChart, Pie, Cell, XAxis, YAxis, Tooltip, Legend, ResponsiveContainer } from 'recharts'
import { api } from '../../api'
import { formatCOP, formatCOPCompact } from '../../format'

const COLORES = ['#4f46e5', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2']

function Panel({ title, subtitle, children }) {
  return (
    <div className="card p-6">
      <h2 className="text-sm font-semibold text-slate-700">{title}</h2>
      {subtitle && <p className="text-xs text-slate-400 mt-0.5 mb-2">{subtitle}</p>}
      <div className="mt-4">{children}</div>
    </div>
  )
}

export default function Dashboard() {
  const [presupuestoVsGasto, setPresupuestoVsGasto] = useState([])
  const [porCategoria, setPorCategoria] = useState([])
  const [porConductor, setPorConductor] = useState([])
  const [discrepancias, setDiscrepancias] = useState([])

  useEffect(() => {
    api.get('/reportes/presupuesto-vs-gasto').then((r) => setPresupuestoVsGasto(r.data))
    api.get('/reportes/gastos-por-categoria').then((r) => setPorCategoria(r.data))
    api.get('/reportes/gastos-por-conductor').then((r) => setPorConductor(r.data))
    api.get('/reportes/discrepancias').then((r) => setDiscrepancias(r.data))
  }, [])

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Panel del contable</h1>
        <p className="text-sm text-slate-500 mt-1">Presupuesto, gasto y discrepancias de OCR.</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Panel title="Presupuesto vs. gasto por ruta">
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={presupuestoVsGasto}>
              <XAxis dataKey="ruta" tick={{ fontSize: 12 }} />
              <YAxis tick={{ fontSize: 12 }} tickFormatter={formatCOPCompact} width={72} />
              <Tooltip formatter={(value) => formatCOP(value)} />
              <Legend />
              <Bar dataKey="presupuesto" fill="#4f46e5" name="Presupuesto" radius={[4, 4, 0, 0]} />
              <Bar dataKey="gastado" fill="#dc2626" name="Gastado" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </Panel>

        <Panel title="Gastos por categoría">
          <ResponsiveContainer width="100%" height={280}>
            <PieChart>
              <Pie data={porCategoria} dataKey="total" nameKey="categoria" outerRadius={100} label>
                {porCategoria.map((_, i) => <Cell key={i} fill={COLORES[i % COLORES.length]} />)}
              </Pie>
              <Tooltip formatter={(value) => formatCOP(value)} />
              <Legend />
            </PieChart>
          </ResponsiveContainer>
        </Panel>

        <Panel title="Gastos por conductor">
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={porConductor}>
              <XAxis dataKey="conductor" tick={{ fontSize: 12 }} />
              <YAxis tick={{ fontSize: 12 }} tickFormatter={formatCOPCompact} width={72} />
              <Tooltip formatter={(value) => formatCOP(value)} />
              <Bar dataKey="total" fill="#059669" name="Total gastado" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </Panel>

        <Panel
          title="Recibos con discrepancia de OCR"
          subtitle="Casos donde el monto confirmado por el conductor difiere del monto leído por el segundo análisis (servidor) del recibo."
        >
          <div className="overflow-x-auto -mx-6">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-6 py-2">Conductor</th>
                  <th className="px-6 py-2">Ruta</th>
                  <th className="px-6 py-2">Monto conductor</th>
                  <th className="px-6 py-2">Monto OCR servidor</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {discrepancias.length === 0 && (
                  <tr>
                    <td colSpan={4} className="px-6 py-6 text-center text-slate-400">
                      Sin discrepancias registradas.
                    </td>
                  </tr>
                )}
                {discrepancias.map((g) => (
                  <tr key={g.uuid}>
                    <td className="px-6 py-2 font-medium text-slate-800">{g.conductor?.name}</td>
                    <td className="px-6 py-2 text-slate-600">{g.ruta?.origen} → {g.ruta?.destino}</td>
                    <td className="px-6 py-2 text-slate-600">{formatCOP(g.monto)}</td>
                    <td className="px-6 py-2 text-slate-600">{formatCOP(g.monto_ocr_servidor)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>
      </div>
    </div>
  )
}
