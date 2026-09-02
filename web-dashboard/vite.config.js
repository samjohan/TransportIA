import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Allows the proxy target to be overridden when running inside Docker
// Compose, where the backend is reachable at http://backend:8000 instead of
// http://localhost:8000.
const apiProxyTarget = process.env.VITE_API_PROXY_TARGET || 'http://localhost:8000'

export default defineConfig({
  plugins: [react()],
  server: {
    host: true,
    // Deployed behind Dokploy/Traefik with a random per-deploy sslip.io (or
    // later, a real custom) domain — Vite's Host-header allowlist would
    // otherwise reject every request unless that exact, changing hostname
    // were hardcoded here.
    allowedHosts: true,
    proxy: {
      '/api': { target: apiProxyTarget, changeOrigin: true },
      '/storage': { target: apiProxyTarget, changeOrigin: true }
    }
  }
})
