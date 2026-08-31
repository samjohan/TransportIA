import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './AuthContext'
import Layout from './components/Layout'
import Login from './pages/Auth/Login'
import AsignarRuta from './pages/Planificador/AsignarRuta'
import Conductores from './pages/Planificador/Conductores'
import Dashboard from './pages/Contable/Dashboard'
import Gastos from './pages/Contable/Gastos'

function Protegida({ rolPermitido, children }) {
  const { user } = useAuth()
  if (!user) return <Navigate to="/login" />
  if (rolPermitido && user.role !== rolPermitido) return <Navigate to="/" />
  return <Layout>{children}</Layout>
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/planificador" element={<Protegida rolPermitido="planificador"><AsignarRuta /></Protegida>} />
          <Route path="/planificador/conductores" element={<Protegida rolPermitido="planificador"><Conductores /></Protegida>} />
          <Route path="/contable" element={<Protegida rolPermitido="contable"><Dashboard /></Protegida>} />
          <Route path="/contable/gastos" element={<Protegida rolPermitido="contable"><Gastos /></Protegida>} />
          <Route path="/" element={<Navigate to="/login" />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}
