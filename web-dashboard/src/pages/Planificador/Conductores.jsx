import { useEffect, useState } from 'react'
import { api } from '../../api'

const EMPTY_FORM = { name: '', email: '', password: '', telefono: '', licencia_conducir: '' }

export default function Conductores() {
  const [conductores, setConductores] = useState([])
  const [cargando, setCargando] = useState(true)
  const [modalAbierto, setModalAbierto] = useState(false)
  const [editando, setEditando] = useState(null) // conductor object, or null for "new"
  const [form, setForm] = useState(EMPTY_FORM)
  const [error, setError] = useState('')
  const [guardando, setGuardando] = useState(false)

  async function cargar() {
    setCargando(true)
    const { data } = await api.get('/conductores')
    setConductores(data)
    setCargando(false)
  }

  useEffect(() => {
    cargar()
  }, [])

  function abrirNuevo() {
    setEditando(null)
    setForm(EMPTY_FORM)
    setError('')
    setModalAbierto(true)
  }

  function abrirEditar(conductor) {
    setEditando(conductor)
    setForm({
      name: conductor.name,
      email: conductor.email,
      password: '',
      telefono: conductor.telefono || '',
      licencia_conducir: conductor.licencia_conducir || '',
    })
    setError('')
    setModalAbierto(true)
  }

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setGuardando(true)
    try {
      if (editando) {
        const payload = { ...form }
        if (!payload.password) delete payload.password
        await api.put(`/conductores/${editando.id}`, payload)
      } else {
        await api.post('/conductores', form)
      }
      setModalAbierto(false)
      await cargar()
    } catch (err) {
      const msg =
        err.response?.data?.errors
          ? Object.values(err.response.data.errors).flat().join(' ')
          : 'No se pudo guardar el conductor.'
      setError(msg)
    } finally {
      setGuardando(false)
    }
  }

  async function handleEliminar(conductor) {
    if (!confirm(`¿Eliminar a ${conductor.name}? Esta acción no se puede deshacer.`)) return
    try {
      await api.delete(`/conductores/${conductor.id}`)
      await cargar()
    } catch (err) {
      const msg =
        err.response?.data?.errors?.conductor?.[0] || 'No se pudo eliminar el conductor.'
      alert(msg)
    }
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Conductores</h1>
          <p className="text-sm text-slate-500 mt-1">Alta, edición y baja de cuentas de conductor.</p>
        </div>
        <button onClick={abrirNuevo} className="btn-primary">
          + Nuevo conductor
        </button>
      </div>

      <div className="card overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-5 py-3">Nombre</th>
              <th className="px-5 py-3">Correo</th>
              <th className="px-5 py-3">Teléfono</th>
              <th className="px-5 py-3">Licencia</th>
              <th className="px-5 py-3 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {cargando && (
              <tr>
                <td colSpan={5} className="px-5 py-8 text-center text-slate-400">
                  Cargando…
                </td>
              </tr>
            )}
            {!cargando && conductores.length === 0 && (
              <tr>
                <td colSpan={5} className="px-5 py-8 text-center text-slate-400">
                  No hay conductores todavía.
                </td>
              </tr>
            )}
            {conductores.map((c) => (
              <tr key={c.id} className="hover:bg-slate-50">
                <td className="px-5 py-3 font-medium text-slate-800">{c.name}</td>
                <td className="px-5 py-3 text-slate-600">{c.email}</td>
                <td className="px-5 py-3 text-slate-600">{c.telefono || '—'}</td>
                <td className="px-5 py-3 text-slate-600">{c.licencia_conducir || '—'}</td>
                <td className="px-5 py-3">
                  <div className="flex justify-end gap-2">
                    <button onClick={() => abrirEditar(c)} className="btn-secondary !px-3 !py-1.5">
                      Editar
                    </button>
                    <button onClick={() => handleEliminar(c)} className="btn-danger !px-3 !py-1.5">
                      Eliminar
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {modalAbierto && (
        <div className="fixed inset-0 bg-slate-900/50 flex items-center justify-center p-4 z-50">
          <div className="card w-full max-w-md p-6">
            <h2 className="text-lg font-semibold text-slate-900 mb-4">
              {editando ? 'Editar conductor' : 'Nuevo conductor'}
            </h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="label">Nombre</label>
                <input
                  className="input"
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  required
                />
              </div>
              <div>
                <label className="label">Correo electrónico</label>
                <input
                  className="input"
                  type="email"
                  value={form.email}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  required
                />
              </div>
              <div>
                <label className="label">
                  {editando ? 'Nueva contraseña (opcional)' : 'Contraseña'}
                </label>
                <input
                  className="input"
                  type="password"
                  value={form.password}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                  placeholder={editando ? 'Dejar en blanco para no cambiarla' : ''}
                  required={!editando}
                  minLength={8}
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="label">Teléfono</label>
                  <input
                    className="input"
                    value={form.telefono}
                    onChange={(e) => setForm({ ...form, telefono: e.target.value })}
                  />
                </div>
                <div>
                  <label className="label">Licencia</label>
                  <input
                    className="input"
                    value={form.licencia_conducir}
                    onChange={(e) => setForm({ ...form, licencia_conducir: e.target.value })}
                  />
                </div>
              </div>

              {error && <p className="text-sm text-red-600">{error}</p>}

              <div className="flex justify-end gap-3 pt-2">
                <button type="button" onClick={() => setModalAbierto(false)} className="btn-secondary">
                  Cancelar
                </button>
                <button type="submit" disabled={guardando} className="btn-primary">
                  {guardando ? 'Guardando…' : 'Guardar'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
