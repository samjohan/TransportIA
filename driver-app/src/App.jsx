import { useState } from 'react'
import { useOnlineStatus } from './hooks/useOnlineStatus'
import Login from './pages/Login'
import InicioConductor from './pages/InicioConductor'

export default function App() {
  const [usuario, setUsuario] = useState(() => {
    const saved = localStorage.getItem('user')
    return saved ? JSON.parse(saved) : null
  })
  const isOnline = useOnlineStatus()

  function logout() {
    localStorage.removeItem('token')
    localStorage.removeItem('user')
    setUsuario(null)
  }

  if (!usuario) {
    return <Login onLogin={setUsuario} />
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="bg-slate-900 text-white px-4 py-3 flex items-center justify-between sticky top-0 z-10">
        <div>
          <p className="font-semibold leading-tight">TransportIA</p>
          <p className="text-xs text-slate-400 leading-tight">{usuario.name}</p>
        </div>
        <button
          onClick={logout}
          className="text-sm text-slate-300 hover:text-white px-2 py-1"
        >
          Salir
        </button>
      </header>

      <div
        className={`px-4 py-2 text-center text-sm font-medium text-white ${
          isOnline ? 'bg-emerald-600' : 'bg-amber-600'
        }`}
      >
        {isOnline ? 'En línea' : 'Sin conexión — los gastos se guardan localmente'}
      </div>

      <InicioConductor />
    </div>
  )
}
