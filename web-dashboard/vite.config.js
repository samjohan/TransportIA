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
    proxy: {
      '/api': { target: apiProxyTarget, changeOrigin: true },
      '/storage': { target: apiProxyTarget, changeOrigin: true }
    }
  }
})
