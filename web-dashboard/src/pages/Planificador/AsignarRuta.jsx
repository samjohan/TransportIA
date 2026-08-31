import { useEffect, useState } from 'react'
import { api } from '../../api'
import { formatCOP } from '../../format'
import MoneyInput from '../../components/MoneyInput'
import LocationCombobox from '../../components/LocationCombobox'

const ESTADO_BADGE = {
  pendiente: 'bg-amber-100 text-amber-700',
  en_curso: 'bg-blue-100 text-blue-700',
  completada: 'bg-emerald-100 text-emerald-700',
  cancelada: 'bg-slate-200 text-slate-600',
}

const ESTADOS = ['pendiente', 'en_curso', 'completada', 'cancelada']

// Ruta's fecha_salida comes back as a full ISO string (with seconds/zone);
// <input type="datetime-local"> needs exactly "YYYY-MM-DDTHH:mm".
function paraInputFecha(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export default function AsignarRuta() {
  const [rutas, setRutas] = useState([])
  const [conductores, setConductores] = useState([])
  const [ubicaciones, setUbicaciones] = useState([])
  const [form, setForm] = useState({
    conductor_id: '', origen: '', destino: '', fecha_salida: '', monto_asignado: ''
  })
  const [mensaje, setMensaje] = useState(null) // { tipo: 'ok' | 'error', texto }
  const [enviando, setEnviando] = useState(false)

  const [editando, setEditando] = useState(null) // ruta siendo editada, o null
  const [formEdicion, setFormEdicion] = useState(null)
  const [errorEdicion, setErrorEdicion] = useState('')
  const [guardandoEdicion, setGuardandoEdicion] = useState(false)

  async function cargarRutas() {
    const { data } = await api.get('/rutas')
    setRutas(data)
  }

  async function cargarConductores() {
    const { data } = await api.get('/conductores')
    setConductores(data)
  }

  async function cargarUbicaciones() {
    const { data } = await api.get('/ubicaciones')
    setUbicaciones(data.map((u) => u.nombre))
  }

  useEffect(() => {
    cargarRutas()
    cargarConductores()
    cargarUbicaciones()
  }, [])

  async function handleSubmit(e) {
    e.preventDefault()
    setMensaje(null)
    setEnviando(true)
    try {
      await api.post('/rutas', form)
      setMensaje({ tipo: 'ok', texto: 'Ruta asignada correctamente.' })
      setForm({ conductor_id: '', origen: '', destino: '', fecha_salida: '', monto_asignado: '' })
      cargarRutas()
      cargarUbicaciones() // origen/destino nuevos quedan disponibles para la próxima ruta
    } catch (err) {
      const msg = err.response?.data?.errors
        ? Object.values(err.response.data.errors).flat().join(' ')
        : 'Error al asignar la ruta.'
      setMensaje({ tipo: 'error', texto: msg })
    } finally {
      setEnviando(false)
    }
  }

  function abrirEditar(ruta) {
    setEditando(ruta)
    setFormEdicion({
      conductor_id: ruta.conductor_id,
      origen: ruta.origen,
      destino: ruta.destino,
      fecha_salida: paraInputFecha(ruta.fecha_salida),
      monto_asignado: ruta.presupuesto ? Number(ruta.presupuesto.monto_asignado) : '',
      estado: ruta.estado,
    })
    setErrorEdicion('')
  }

  async function handleSubmitEdicion(e) {
    e.preventDefault()
    setErrorEdicion('')
    setGuardandoEdicion(true)
    try {
      await api.put(`/rutas/${editando.uuid}`, formEdicion)
      setEditando(null)
      await Promise.all([cargarRutas(), cargarUbicaciones()])
    } catch (err) {
      const msg = err.response?.data?.errors
        ? Object.values(err.response.data.errors).flat().join(' ')
        : 'No se pudo actualizar la ruta.'
      setErrorEdicion(msg)
    } finally {
      setGuardandoEdicion(false)
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Asignar ruta</h1>
        <p className="text-sm text-slate-500 mt-1">Crea una nueva ruta y asígnale un presupuesto.</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="card p-6 lg:col-span-1 h-fit">
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="label">Conductor <span className="text-red-500">*</span></label>
              <select
                className="input"
                value={form.conductor_id}
                onChange={(e) => setForm({ ...form, conductor_id: e.target.value })}
                required
              >
                <option value="" disabled>Selecciona un conductor</option>
                {conductores.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
              {conductores.length === 0 && (
                <p className="text-xs text-slate-400 mt-1">No hay conductores registrados todavía.</p>
              )}
            </div>
            <LocationCombobox
              label="Origen"
              value={form.origen}
              onChange={(origen) => setForm({ ...form, origen })}
              options={ubicaciones}
              required
            />
            <LocationCombobox
              label="Destino"
              value={form.destino}
              onChange={(destino) => setForm({ ...form, destino })}
              options={ubicaciones}
              required
            />
            <div>
              <label className="label">Fecha de salida <span className="text-slate-400 font-normal">(opcional)</span></label>
              <input className="input" type="datetime-local" value={form.fecha_salida}
                onChange={(e) => setForm({ ...form, fecha_salida: e.target.value })} />
            </div>
            <div>
              <label className="label">Anticipo asignado <span className="text-red-500">*</span></label>
              <MoneyInput
                value={form.monto_asignado}
                onChange={(monto_asignado) => setForm({ ...form, monto_asignado })}
                required
              />
            </div>

            {mensaje && (
              <p className={`text-sm ${mensaje.tipo === 'ok' ? 'text-emerald-600' : 'text-red-600'}`}>
                {mensaje.texto}
              </p>
            )}

            <button type="submit" disabled={enviando} className="btn-primary w-full">
              {enviando ? 'Asignando…' : 'Asignar ruta'}
            </button>
            <p className="text-xs text-slate-400">
              <span className="text-red-500">*</span> Campo obligatorio
            </p>
          </form>
        </div>

        <div className="card overflow-hidden lg:col-span-2 h-fit">
          <div className="px-5 py-4 border-b border-slate-200">
            <h2 className="text-sm font-semibold text-slate-700">Viajes en Curso</h2>
          </div>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                <th className="px-5 py-3">Conductor</th>
                <th className="px-5 py-3">Origen → Destino</th>
                <th className="px-5 py-3">Presupuesto</th>
                <th className="px-5 py-3">Estado</th>
                <th className="px-5 py-3 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rutas.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-5 py-8 text-center text-slate-400">
                    No hay rutas todavía.
                  </td>
                </tr>
              )}
              {rutas.map((r) => (
                <tr key={r.uuid} className="hover:bg-slate-50">
                  <td className="px-5 py-3 font-medium text-slate-800">{r.conductor?.name}</td>
                  <td className="px-5 py-3 text-slate-600">{r.origen} → {r.destino}</td>
                  <td className="px-5 py-3 text-slate-600">
                    {formatCOP(r.presupuesto?.monto_asignado)}
                  </td>
                  <td className="px-5 py-3">
                    <span className={`badge ${ESTADO_BADGE[r.estado] || 'bg-slate-100 text-slate-600'}`}>
                      {r.estado}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-right">
                    <button onClick={() => abrirEditar(r)} className="btn-secondary !px-3 !py-1.5">
                      Editar
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {editando && formEdicion && (
        <div className="fixed inset-0 bg-slate-900/50 flex items-center justify-center p-4 z-50">
          <div className="card w-full max-w-md p-6">
            <h2 className="text-lg font-semibold text-slate-900 mb-4">Editar viaje</h2>
            <form onSubmit={handleSubmitEdicion} className="space-y-4">
              <div>
                <label className="label">Conductor</label>
                <select
                  className="input"
                  value={formEdicion.conductor_id}
                  onChange={(e) => setFormEdicion({ ...formEdicion, conductor_id: e.target.value })}
                  required
                >
                  {conductores.map((c) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </div>
              <LocationCombobox
                label="Origen"
                value={formEdicion.origen}
                onChange={(origen) => setFormEdicion({ ...formEdicion, origen })}
                options={ubicaciones}
                required
              />
              <LocationCombobox
                label="Destino"
                value={formEdicion.destino}
                onChange={(destino) => setFormEdicion({ ...formEdicion, destino })}
                options={ubicaciones}
                required
              />
              <div>
                <label className="label">Fecha de salida <span className="text-slate-400 font-normal">(opcional)</span></label>
                <input className="input" type="datetime-local" value={formEdicion.fecha_salida}
                  onChange={(e) => setFormEdicion({ ...formEdicion, fecha_salida: e.target.value })} />
              </div>
              <div>
                <label className="label">Anticipo asignado</label>
                <MoneyInput
                  value={formEdicion.monto_asignado}
                  onChange={(monto_asignado) => setFormEdicion({ ...formEdicion, monto_asignado })}
                  required
                />
              </div>
              <div>
                <label className="label">Estado</label>
                <select
                  className="input"
                  value={formEdicion.estado}
                  onChange={(e) => setFormEdicion({ ...formEdicion, estado: e.target.value })}
                >
                  {ESTADOS.map((e) => <option key={e} value={e}>{e}</option>)}
                </select>
              </div>

              {errorEdicion && <p className="text-sm text-red-600">{errorEdicion}</p>}

              <div className="flex justify-end gap-3 pt-2">
                <button type="button" onClick={() => setEditando(null)} className="btn-secondary">
                  Cancelar
                </button>
                <button type="submit" disabled={guardandoEdicion} className="btn-primary">
                  {guardandoEdicion ? 'Guardando…' : 'Guardar cambios'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
