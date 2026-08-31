import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '../AuthContext'

const NAV = {
  planificador: [
    { to: '/planificador', label: 'Asignar rutas', icon: '🗺️' },
    { to: '/planificador/conductores', label: 'Conductores', icon: '🧑‍✈️' },
  ],
  contable: [
    { to: '/contable', label: 'Panel', icon: '📊' },
    { to: '/contable/gastos', label: 'Gastos', icon: '🧾' },
  ],
}

function initials(name) {
  return (name || '')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((s) => s[0]?.toUpperCase())
    .join('')
}

export default function Layout({ children }) {
  const { user, logout } = useAuth()
  const location = useLocation()
  const items = NAV[user?.role] ?? []

  return (
    <div className="min-h-screen flex bg-slate-50">
      <aside className="w-64 shrink-0 bg-slate-900 text-slate-200 flex flex-col">
        <div className="px-5 py-5 border-b border-slate-800">
          <span className="text-lg font-semibold tracking-tight text-white">TransportIA</span>
          <p className="text-xs text-slate-400 mt-0.5">Rutas y gastos</p>
        </div>

        <nav className="flex-1 px-3 py-4 space-y-1">
          {items.map((item) => {
            const active = location.pathname === item.to
            return (
              <Link
                key={item.to}
                to={item.to}
                className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                  active
                    ? 'bg-brand-600 text-white'
                    : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                }`}
              >
                <span aria-hidden="true">{item.icon}</span>
                {item.label}
              </Link>
            )
          })}
        </nav>

        <div className="px-3 py-4 border-t border-slate-800">
          <div className="flex items-center gap-3 px-2 mb-3">
            <div className="h-9 w-9 rounded-full bg-brand-600 text-white flex items-center justify-center text-sm font-semibold shrink-0">
              {initials(user?.name)}
            </div>
            <div className="min-w-0">
              <p className="text-sm font-medium text-white truncate">{user?.name}</p>
              <p className="text-xs text-slate-400 capitalize">{user?.role}</p>
            </div>
          </div>
          <button
            onClick={logout}
            className="w-full rounded-lg px-3 py-2 text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors text-left"
          >
            Cerrar sesión
          </button>
        </div>
      </aside>

      <main className="flex-1 min-w-0">
        <div className="max-w-6xl mx-auto px-6 py-8 lg:px-10">{children}</div>
      </main>
    </div>
  )
}
